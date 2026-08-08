import { get, post, download } from './api.js';

/** Two-phase end-of-cycle distribution endpoints (Req 10.1–10.7). */
const distributionService = {
  /** Live ledger preview for the active cycle. */
  current() {
    return get('/distribution/current');
  },

  /** Phase 1 — build and store the signature-ready PDF. */
  generatePdf() {
    return post('/distribution/generate-pdf', {});
  },

  downloadPdf(cycleId) {
    return download(`/distribution/pdf/${cycleId}`, {}, `distribution_cycle_${cycleId}.pdf`);
  },

  /** Phase 2 — atomic payout, cycle rollover and new open period. */
  confirm() {
    return post('/distribution/confirm', {});
  },

  history() {
    return get('/distribution/history');
  },

  historyDetail(cycleId) {
    return get(`/distribution/history/${cycleId}`);
  },
};

export default distributionService;
