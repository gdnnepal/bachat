import { get, post } from './api.js';

/** Language file delivery and preference (Req 14.3–14.5). */
const langService = {
  /**
   * Fetch a locale's translations. The backend merges non-English locales over
   * English, so any missing key already resolves to its English value.
   */
  fetch(locale) {
    return get(`/lang/${locale}`);
  },

  /** Persist the choice on the PHP session. Failure is non-fatal. */
  setPreference(locale) {
    return post('/lang/preference', { locale });
  },
};

export default langService;
