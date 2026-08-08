import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';

import langService from '../services/langService.js';

/**
 * Translation context (Req 14.1–14.5).
 *
 * - Locale lives in React state and localStorage so a reload keeps the choice.
 * - Switching language re-fetches the map and re-renders; no page reload.
 * - t(key) falls back through: active locale → English → the key itself,
 *   so a missing translation never renders an empty string (Property 17).
 */

const STORAGE_KEY = 'vcms.locale';
const SUPPORTED = ['en', 'ne'];
const DEFAULT_LOCALE = 'en';

const I18nContext = createContext(null);

function readStoredLocale() {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY);
    return SUPPORTED.includes(stored) ? stored : DEFAULT_LOCALE;
  } catch {
    return DEFAULT_LOCALE;
  }
}

export function I18nProvider({ children, initialLocale }) {
  const [locale, setLocaleState] = useState(initialLocale || readStoredLocale());
  const [translations, setTranslations] = useState({});
  const [loading, setLoading] = useState(true);

  // English is kept separately so it can back-stop a partial translation even
  // if the server ever returns an unmerged map.
  const englishRef = useRef({});

  const loadLocale = useCallback(async (next) => {
    setLoading(true);

    try {
      const result = await langService.fetch(next);
      const map = result?.translations || {};

      setTranslations(map);

      if (next === DEFAULT_LOCALE) {
        englishRef.current = map;
      } else if (Object.keys(englishRef.current).length === 0) {
        // Fetch English once in the background as the fallback source.
        langService
          .fetch(DEFAULT_LOCALE)
          .then((res) => {
            englishRef.current = res?.translations || {};
          })
          .catch(() => {});
      }
    } catch {
      // Offline or the API is down — keep whatever map we already have so the
      // UI degrades to English keys rather than blanking out.
      setTranslations((current) => (Object.keys(current).length ? current : englishRef.current));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadLocale(locale);
  }, [locale, loadLocale]);

  // Keep the document lang attribute in step so the :lang(ne) CSS rule applies.
  useEffect(() => {
    document.documentElement.setAttribute('lang', locale);
  }, [locale]);

  const setLocale = useCallback((next) => {
    const target = SUPPORTED.includes(next) ? next : DEFAULT_LOCALE;

    setLocaleState(target);

    try {
      window.localStorage.setItem(STORAGE_KEY, target);
    } catch {
      /* private browsing — the in-memory choice still applies */
    }

    // Best effort: remember it on the session too. A failure here is harmless.
    langService.setPreference(target).catch(() => {});
  }, []);

  /**
   * Translate a key, optionally interpolating {placeholders}.
   *
   *   t('savings.collected')
   *   t('table.showing', { count: 12 })
   */
  const t = useCallback(
    (key, vars) => {
      if (!key) return '';

      const raw =
        pickString(translations[key]) ??
        pickString(englishRef.current[key]) ??
        key;

      if (!vars) return raw;

      return raw.replace(/\{(\w+)\}/g, (match, name) =>
        Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : match,
      );
    },
    [translations],
  );

  const value = useMemo(
    () => ({ locale, setLocale, t, loading, translations, supported: SUPPORTED }),
    [locale, setLocale, t, loading, translations],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

/** A translation only counts if it is a non-blank string. */
function pickString(value) {
  return typeof value === 'string' && value.trim() !== '' ? value : undefined;
}

export function useI18n() {
  const context = useContext(I18nContext);

  if (!context) {
    throw new Error('useI18n must be used inside an <I18nProvider>.');
  }

  return context;
}

export default useI18n;
