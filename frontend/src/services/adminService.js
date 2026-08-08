import { get, post, put, patch } from './api.js';

/** Admin account management — Super_Admin only (Req 2.1–2.6). */
const adminService = {
  list() {
    return get('/admins');
  },

  find(id) {
    return get(`/admins/${id}`);
  },

  create(payload) {
    return post('/admins', payload);
  },

  update(id, payload) {
    return put(`/admins/${id}`, payload);
  },

  /** @param {0|1} status 1 = Active, 0 = Inactive */
  setStatus(id, status) {
    return patch(`/admins/${id}/status`, { status });
  },
};

export default adminService;
