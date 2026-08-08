/**
 * Bridge between the PHP report envelope and the <Table> column contract.
 *
 * The backend describes each column as `{ key, label, type }` where type is one
 * of text | date | int | money. Table understands money | number and treats
 * everything else as text, so `int` is remapped here rather than teaching Table
 * a second vocabulary.
 *
 * Labels arrive in English from PHP. Where the UI already ships a translation
 * for that column, LABEL_KEYS swaps it in so a Nepali user sees Nepali headers
 * (Req 14.2); anything unmapped falls back to the server's label.
 */

const TYPE_MAP = {
  int: 'number',
  integer: 'number',
  number: 'number',
  money: 'money',
  decimal: 'money',
};

/** Column key → translation key, for the columns the language files cover. */
const LABEL_KEYS = {
  member_code: 'member.member_id',
  member_id: 'member.member_id',
  full_name: 'member.full_name',
  phone: 'member.phone',
  description: 'common.description',
  amount: 'common.amount',
  running_balance: 'report.running_balance',
  date_bs: 'common.date',
  loan_amount: 'loan.amount',
  interest_rate: 'loan.interest_rate',
  outstanding_principal: 'loan.outstanding_principal',
  accrued_interest: 'loan.accrued_interest',
  total_due: 'loan.total_due',
  loan_status: 'loan.status',
  total_savings: 'member.total_savings',
  total_interest: 'member.total_interest',
  final_payable: 'distribution.final_payable',
  logged_at: 'audit.datetime',
  admin_username: 'audit.admin',
  action_type: 'audit.action',
  ip_address: 'audit.ip',
  user_agent: 'audit.user_agent',
};

/**
 * Convert an envelope's column list into Table columns.
 *
 * @param {Array<{key: string, label: string, type: string}>} columns
 * @param {Function} [t] translator; when omitted the server labels are kept
 * @returns {Array}
 */
export function normaliseColumns(columns, t) {
  if (!Array.isArray(columns)) return [];

  return columns.map((column) => {
    const langKey = LABEL_KEYS[column.key];
    // t() falls back to the key itself, so only take a translation that resolved.
    const translated = t && langKey ? t(langKey) : null;

    return {
      key: column.key,
      label: translated && translated !== langKey ? translated : column.label,
      type: TYPE_MAP[column.type] || 'text',
    };
  });
}

export default normaliseColumns;
