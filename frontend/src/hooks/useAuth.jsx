import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

import authService from '../services/authService.js';
import { normaliseError, registerApiHandlers, clearCsrfToken } from '../services/api.js';
import { useToast } from './useToast.jsx';
import { useI18n } from './useI18n.jsx';

/**
 * Authentication state and route-guard helpers (Req 1.1–1.11, 2.6).
 *
 * The session itself lives in the PHP session cookie; this context only mirrors
 * who is signed in so the UI can render and guard routes. On mount it calls
 * /auth/me, which also revives a remember-me session server-side.
 */

const AuthContext = createContext(null);

export const ROLE_SUPER_ADMIN = 'Super_Admin';

export function AuthProvider({ children }) {
  const [admin, setAdmin] = useState(null);
  const [checking, setChecking] = useState(true);
  const toast = useToast();
  const { t } = useI18n();

  // ─── Bootstrap: who is signed in? ──────────────────────────────────────────
  useEffect(() => {
    let cancelled = false;

    authService
      .me()
      .then((result) => {
        if (!cancelled) setAdmin(result?.admin || result || null);
      })
      .catch(() => {
        if (!cancelled) setAdmin(null);
      })
      .finally(() => {
        if (!cancelled) setChecking(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  // ─── Wire the API interceptors to auth state and toasts ────────────────────
  useEffect(() => {
    registerApiHandlers({
      onUnauthorized: () => {
        setAdmin(null);
        clearCsrfToken();
      },
      onForbidden: (error) => {
        toast.error(translateApiMessage(t, error));
      },
      onServerError: (error) => {
        toast.error(translateApiMessage(t, error));
      },
    });
  }, [toast, t]);

  const login = useCallback(async ({ username, password, remember }) => {
    const result = await authService.login({ username, password, remember });
    const signedIn = result?.admin || result || null;

    setAdmin(signedIn);

    return signedIn;
  }, []);

  const logout = useCallback(async () => {
    try {
      await authService.logout();
    } catch {
      /* the session may already be gone — clearing local state is what matters */
    } finally {
      setAdmin(null);
    }
  }, []);

  /** Re-read the current admin, e.g. after a role or status change. */
  const refresh = useCallback(async () => {
    try {
      const result = await authService.me();
      const current = result?.admin || result || null;
      setAdmin(current);
      return current;
    } catch {
      setAdmin(null);
      return null;
    }
  }, []);

  const value = useMemo(
    () => ({
      admin,
      checking,
      isAuthenticated: Boolean(admin),
      isSuperAdmin: admin?.role === ROLE_SUPER_ADMIN,
      login,
      logout,
      refresh,
    }),
    [admin, checking, login, logout, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

/**
 * API errors carry either a translation key (from the client) or a ready
 * sentence (from PHP). Keys always contain a dot and no spaces.
 */
export function translateApiMessage(t, error) {
  const message = error?.message || 'error.generic';
  const looksLikeKey = /^[a-z0-9_]+\.[a-z0-9_.]+$/i.test(message);

  return looksLikeKey ? t(message) : message;
}

export function useAuth() {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used inside an <AuthProvider>.');
  }

  return context;
}

/** Shape any thrown Axios error for form and toast consumption. */
export function toApiError(error) {
  return normaliseError(error);
}

export default useAuth;
