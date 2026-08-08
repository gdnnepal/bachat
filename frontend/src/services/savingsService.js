import { get, post } from './api.js';

/** Bulk monthly savings endpoints (Req 5.1–5.5). */
const savingsService = {
  /** Active members plus their already-paid flag for the current period. */
  bulkScreen() {
    return get('/savings/bulk-screen');
  },

  bulkCollect(memberIds) {
    return post('/savings/bulk-collect', { member_ids: memberIds });
  },

  list(filters = {}) {
    return get('/savings', filters);
  },
};

export default savingsService;
