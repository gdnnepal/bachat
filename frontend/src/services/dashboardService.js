import { get } from './api.js';

/** Dashboard aggregate endpoint (Req 9.1–9.5). */
const dashboardService = {
  /** @param {'en'|'ne'} locale controls the BS month name returned. */
  summary(locale = 'en') {
    return get('/dashboard', { locale });
  },
};

export default dashboardService;
