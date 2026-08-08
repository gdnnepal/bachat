import { get, post, put, fetchCsrfToken, setCsrfToken, clearCsrfToken } from './api.js';

/** Authentication endpoints (Req 1.1–1.11). */
const authService = {
  /** Prime the CSRF token before the first mutating request. */
  async csrfToken(force = false) {
    return fetchCsrfToken(force);
  },

  async login({ username, password, remember = false }) {
    // The login POST itself needs a token, so make sure one exists first.
    await fetchCsrfToken();

    // The API reads the flag as `remember_me` (AuthController::login).
    const result = await post('/auth/login', { username, password, remember_me: remember });

    // The server rotates the session id on success, which invalidates the old
    // token — adopt whatever it hands back.
    const rotated = result?.csrf_token;
    if (rotated) setCsrfToken(rotated);

    return result;
  },

  async logout() {
    try {
      return await post('/auth/logout', {});
    } finally {
      clearCsrfToken();
    }
  },

  me() {
    return get('/auth/me');
  },

  changePassword({ current_password, new_password, confirm_password }) {
    return put('/auth/change-password', {
      current_password,
      new_password,
      confirm_password,
    });
  },
};

export default authService;
