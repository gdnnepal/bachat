import { useCallback, useEffect, useRef, useState } from 'react';

import { normaliseError } from '../services/api.js';
import { useI18n } from './useI18n.jsx';
import { translateApiMessage } from './useAuth.jsx';

/**
 * Data-fetching helpers built on the shared Axios instance.
 *
 * useApi   — run a request on demand, tracking loading/error state
 * useFetch — run a request on mount (and whenever deps change)
 *
 * Both return errors already normalised to {status, code, message, fields} and
 * translated where the message is a lang key, so pages can render them directly.
 */

/**
 * @param {Function} requestFn async (...args) => response
 * @returns {{run: Function, loading: boolean, error: object|null, reset: Function}}
 */
export function useApi(requestFn) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const { t } = useI18n();

  const mounted = useRef(true);
  const fnRef = useRef(requestFn);
  fnRef.current = requestFn;

  // The flag must be re-armed in the effect body, not just cleared in the
  // cleanup: StrictMode mounts, unmounts and remounts in development, so a
  // cleanup-only version leaves `mounted` false for the rest of the component's
  // life and every state update below is silently dropped.
  useEffect(() => {
    mounted.current = true;

    return () => {
      mounted.current = false;
    };
  }, []);

  const run = useCallback(
    async (...args) => {
      setLoading(true);
      setError(null);

      try {
        const result = await fnRef.current(...args);
        return result;
      } catch (caught) {
        const shaped = normaliseError(caught);
        shaped.message = translateApiMessage(t, shaped);

        if (mounted.current) setError(shaped);

        throw shaped;
      } finally {
        if (mounted.current) setLoading(false);
      }
    },
    [t],
  );

  const reset = useCallback(() => setError(null), []);

  return { run, loading, error, reset };
}

/**
 * Fetch on mount and whenever `deps` change.
 *
 * @param {Function} requestFn async () => response
 * @param {Array} deps
 * @param {{skip?: boolean, initialData?: any}} options
 */
export function useFetch(requestFn, deps = [], options = {}) {
  const { skip = false, initialData = null } = options;

  const [data, setData] = useState(initialData);
  const [loading, setLoading] = useState(!skip);
  const [error, setError] = useState(null);
  const { t } = useI18n();

  const mounted = useRef(true);
  const fnRef = useRef(requestFn);
  fnRef.current = requestFn;

  // Re-armed on every mount — see the note in useApi above.
  useEffect(() => {
    mounted.current = true;

    return () => {
      mounted.current = false;
    };
  }, []);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const result = await fnRef.current();
      if (mounted.current) setData(result);
      return result;
    } catch (caught) {
      const shaped = normaliseError(caught);
      shaped.message = translateApiMessage(t, shaped);
      if (mounted.current) setError(shaped);
      return null;
    } finally {
      if (mounted.current) setLoading(false);
    }
  }, [t]);

  useEffect(() => {
    if (skip) {
      setLoading(false);
      return;
    }

    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [skip, ...deps]);

  return { data, setData, loading, error, reload: load };
}

/**
 * Copy a validation error map from the API onto a React Hook Form instance so
 * field-level messages appear next to the right inputs.
 *
 * @param {object} form   React Hook Form's useForm() return value
 * @param {object} error  Normalised API error
 * @returns {boolean}     true when at least one field error was applied
 */
export function applyFieldErrors(form, error) {
  const fields = error?.fields || {};
  const names = Object.keys(fields);

  names.forEach((name) => {
    form.setError(name, { type: 'server', message: fields[name] });
  });

  return names.length > 0;
}

export default useApi;
