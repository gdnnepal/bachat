import api, { get, post } from './api.js';

/** Backup and restore endpoints (Req 13.1–13.5). */
const backupService = {
  list() {
    return get('/backup/list');
  },

  create() {
    return post('/backup/create', {});
  },

  /**
   * Step 1 — validate an existing backup file without touching the database.
   */
  validate(filename) {
    return post('/backup/restore', { filename, confirm: false });
  },

  /**
   * Step 2 — destructive: overwrite the current database from the file.
   */
  restore(filename) {
    return post('/backup/restore', { filename, confirm: true });
  },

  /**
   * Upload a .sql.gz archive and validate it. Nothing is written to the
   * database until restore() is called with the returned filename.
   */
  async upload(file) {
    const form = new FormData();
    form.append('file', file);
    form.append('confirm', 'false');

    const response = await api.post('/backup/restore', form, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000,
    });

    return response.data;
  },
};

export default backupService;
