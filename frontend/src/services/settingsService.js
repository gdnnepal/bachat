import { get, put } from './api.js';

/** Cooperative settings endpoints (Req 12.1, 14.3). */
const settingsService = {
  fetch() {
    return get('/settings');
  },

  /** Only Super_Admin may write; the API returns 403 for anyone else. */
  update(settings) {
    return put('/settings', { settings });
  },
};

export default settingsService;
