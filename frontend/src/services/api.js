import axios from 'axios';

/**
 * Shared Axios instance for the whole app.
 *
 * - withCredentials so the PHP session cookie travels with every request
 * - X-CSRF-Token injected on state-changing verbs from an in-memory store
 *   (never localStorage — a token readable by injected script defeats the point)
 * - 401 → clear auth state and bounce to /login
 * - 403 → surface a toast; the caller still receives the rejection
 */

const BASE_URL = import.meta.env?.VITE_API_BASE_URL || '/api/v1';

const api = axios.create({
  baseURL: BASE_URL,
  withCredentials: true,
  timeout: 30000,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
});

// ─── In-memory CSRF token store ──────────────────────────────────────────────

let csrfToken = null;
let csrfRequest = null;

const MUTATING_METHODS = ['post', 'put', 'patch', 'delete'];

export function setCsrfToken(token) {
  csrfToken = token || null;
}

export function getCsrfToken() {
  return csrfToken;
}

export function clearCsrfToken() {
  csrfToken = null;
  csrfRequest = null;
}

/**
 * Fetch a CSRF token, de-duplicating concurrent callers so a burst of
 * mutations on page load only ever triggers one round trip.
 */
export async function fetchCsrfToken(force = false) {
  if (csrfToken && !force) return csrfToken;

  if (!csrfRequest) {
    csrfRequest = api
      .get('/auth/csrf-token')
      .then((response) => {
        csrfToken = response?.data?.data?.csrf_token || response?.data?.data?.token || null;
        return csrfToken;
      })
      .finally(() => {
        csrfRequest = null;
      });
  }

  return csrfRequest;
}

// ─── Global handlers registered by the app shell ─────────────────────────────

const handlers = {
  onUnauthorized: null,
  onForbidden: null,
  onServerError: null,
};

export function registerApiHandlers(next) {
  Object.assign(handlers, next);
}

// ─── Request interceptor ─────────────────────────────────────────────────────

api.interceptors.request.use(async (config) => {
  const method = (config.method || 'get').toLowerCase();

  if (MUTATING_METHODS.includes(method) && !config.skipCsrf) {
    const token = csrfToken || (await fetchCsrfToken());
    if (token) {
      config.headers['X-CSRF-Token'] = token;
    }
  }

  return config;
});

// ─── Response interceptor ────────────────────────────────────────────────────

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error?.response?.status;
    const original = error.config || {};

    // A rejected CSRF token usually means the session was recycled. Refresh the
    // token once and replay the request rather than failing the user's action.
    if (status === 403 && isCsrfRejection(error) && !original.__csrfRetried) {
      original.__csrfRetried = true;
      clearCsrfToken();
      const token = await fetchCsrfToken(true);
      if (token) {
        original.headers = { ...original.headers, 'X-CSRF-Token': token };
        return api.request(original);
      }
    }

    if (status === 401) {
      clearCsrfToken();
      handlers.onUnauthorized?.(normaliseError(error));
    } else if (status === 403) {
      handlers.onForbidden?.(normaliseError(error));
    } else if (status >= 500) {
      handlers.onServerError?.(normaliseError(error));
    }

    return Promise.reject(error);
  },
);

// ─── Error shaping ───────────────────────────────────────────────────────────

function isCsrfRejection(error) {
  const code = error?.response?.data?.error?.code || '';
  const message = error?.response?.data?.error?.message || '';
  return code === 'CSRF_ERROR' || /csrf/i.test(message);
}

/**
 * Flatten any Axios failure into a predictable shape the UI can render.
 *
 * @returns {{status: number, code: string, message: string, fields: object}}
 */
export function normaliseError(error) {
  if (error?.response) {
    const { status, data } = error.response;
    const payload = data?.error || {};

    return {
      status,
      code: payload.code || 'ERROR',
      message: payload.message || defaultMessageFor(status),
      fields: payload.fields || {},
    };
  }

  if (error?.request) {
    return { status: 0, code: 'NETWORK_ERROR', message: 'error.network', fields: {} };
  }

  return { status: 0, code: 'ERROR', message: error?.message || 'error.generic', fields: {} };
}

function defaultMessageFor(status) {
  switch (status) {
    case 401:
      return 'error.unauthorized';
    case 403:
      return 'error.forbidden';
    case 404:
      return 'error.not_found';
    case 409:
      return 'error.conflict';
    case 422:
      return 'error.validation';
    default:
      return status >= 500 ? 'error.server' : 'error.generic';
  }
}

// ─── Convenience wrappers ────────────────────────────────────────────────────

/**
 * Peel the `{ success, data, message }` envelope the API always sends so
 * callers work with the payload directly. A non-enveloped body (a file, a raw
 * string) is returned untouched.
 */
function unwrap(body) {
  if (body && typeof body === 'object' && 'success' in body && 'data' in body) {
    return body.data;
  }

  return body;
}

/** GET returning the unwrapped `data` payload. */
export async function get(url, params, config = {}) {
  const response = await api.get(url, { params, ...config });
  return unwrap(response.data);
}

/**
 * GET for endpoints that answer with Response::paginated — keeps the
 * `pagination` block, which unwrap() would otherwise discard.
 *
 * @returns {Promise<{rows: any, pagination: object}>}
 */
export async function getPage(url, params, config = {}) {
  const response = await api.get(url, { params, ...config });
  const body = response.data || {};

  return {
    rows: body.data ?? [],
    pagination: body.pagination || { total: 0, page: 1, per_page: 0, total_pages: 0 },
  };
}

export async function post(url, body, config = {}) {
  const response = await api.post(url, body, config);
  return unwrap(response.data);
}

export async function put(url, body, config = {}) {
  const response = await api.put(url, body, config);
  return unwrap(response.data);
}

export async function patch(url, body, config = {}) {
  const response = await api.patch(url, body, config);
  return unwrap(response.data);
}

export async function del(url, config = {}) {
  const response = await api.delete(url, config);
  return unwrap(response.data);
}

/**
 * Trigger a browser download for an endpoint that streams a file.
 * Uses a blob so the session cookie and CSRF handling still apply.
 */
export async function download(url, params, fallbackName = 'download') {
  const response = await api.get(url, { params, responseType: 'blob' });

  const disposition = response.headers?.['content-disposition'] || '';
  const match = /filename="?([^";]+)"?/i.exec(disposition);
  const filename = match ? match[1] : fallbackName;

  const href = URL.createObjectURL(response.data);
  const link = document.createElement('a');
  link.href = href;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(href);

  return filename;
}

export { BASE_URL };
export default api;
