import { get, post } from './api.js';

/** Accounting period and Month_Close endpoints (Req 4.1–4.9). */
const periodService = {
  list(cycleId) {
    return get('/accounting-periods', cycleId ? { cycle_id: cycleId } : {});
  },

  current() {
    return get('/accounting-periods/current');
  },

  /** Atomic close: credit interest, close this period, open the next BS month. */
  monthClose() {
    return post('/accounting-periods/month-close', {});
  },

  /** Super_Admin only; a reason is mandatory (Req 4.8). */
  reopen(id, reason) {
    return post(`/accounting-periods/${id}/reopen`, { reason });
  },
};

export default periodService;
