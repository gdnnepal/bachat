import { get, getPage, post, put, del, download } from './api.js';

/** Member roster endpoints (Req 3.1–3.9, 11.2). */
const memberService = {
  /** @returns {Promise<{rows: object[], pagination: object}>} */
  list({ q = '', page = 1, per_page = 25, status = '' } = {}) {
    return getPage('/members', { q, page, per_page, status });
  },

  find(id) {
    return get(`/members/${id}`);
  },

  create(payload) {
    return post('/members', payload);
  },

  update(id, payload) {
    return put(`/members/${id}`, payload);
  },

  remove(id) {
    return del(`/members/${id}`);
  },

  statement(id, range = {}) {
    return get(`/members/${id}/statement`, range);
  },

  exportStatement(id, format = 'pdf', range = {}) {
    return download(
      `/reports/member-statement/export`,
      { ...range, member_id: id, format },
      `member_statement_${id}.${format}`,
    );
  },
};

export default memberService;
