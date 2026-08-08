import { get, download } from './api.js';

/**
 * Report endpoints (Req 11.1–11.6, 12.4).
 *
 * Every report returns the same envelope:
 *   { title, columns: [{key, label, type}], rows: [...], totals: {}, meta: {} }
 * so ReportViewer can render any of them without special-casing.
 */

export const REPORT_TYPES = [
  { value: 'member-statement', labelKey: 'report.member_statement', needsMember: true },
  { value: 'monthly', labelKey: 'report.monthly' },
  { value: 'loans', labelKey: 'report.loans' },
  { value: 'cash-book', labelKey: 'report.cash_book' },
  { value: 'bank-book', labelKey: 'report.bank_book' },
  { value: 'savings', labelKey: 'report.savings' },
  { value: 'interest', labelKey: 'report.interest' },
  { value: 'distribution', labelKey: 'report.distribution' },
  { value: 'audit', labelKey: 'report.audit', isAudit: true },
];

/** Report types that have their own named GET route on the backend. */
const NAMED_ROUTES = {
  monthly: '/reports/monthly',
  loans: '/reports/loans',
  'cash-book': '/reports/cash-book',
  'bank-book': '/reports/bank-book',
  savings: '/reports/savings',
  interest: '/reports/interest',
  distribution: '/reports/distribution',
  audit: '/reports/audit',
};

const reportService = {
  /**
   * Fetch any report by type. Member statements go through the member route
   * because that is where the backend exposes them.
   */
  fetch(type, filters = {}) {
    if (type === 'member-statement') {
      const { member_id: memberId, ...range } = filters;
      if (!memberId) {
        return Promise.reject(new Error('A member must be selected for the member statement.'));
      }
      return get(`/members/${memberId}/statement`, range);
    }

    const route = NAMED_ROUTES[type];
    if (!route) {
      return Promise.reject(new Error(`Unknown report type: ${type}`));
    }

    return get(route, filters);
  },

  audit(filters = {}) {
    return get('/reports/audit', filters);
  },

  /** @param {'pdf'|'xlsx'} format */
  export(type, format = 'pdf', filters = {}) {
    return download(
      `/reports/${type}/export`,
      { ...filters, format },
      `${type}.${format}`,
    );
  },
};

export default reportService;
