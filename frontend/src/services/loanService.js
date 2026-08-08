import { get, post, put, patch } from './api.js';

/** Loan disbursement and repayment endpoints (Req 7.1–7.11). */
const loanService = {
  list(filters = {}) {
    return get('/loans', filters);
  },

  find(id) {
    return get(`/loans/${id}`);
  },

  disburse(payload) {
    return post('/loans', payload);
  },

  update(id, payload) {
    return put(`/loans/${id}`, payload);
  },

  cancel(id, reason = '') {
    return patch(`/loans/${id}/cancel`, { reason });
  },

  repayments(id) {
    return get(`/loans/${id}/repayments`);
  },

  recordRepayment(id, payload) {
    return post(`/loans/${id}/repayments`, payload);
  },
};

export default loanService;
