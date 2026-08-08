import { get, post } from './api.js';

/** Cash box and bank account endpoints (Req 8.1–8.6). */
const cashBankService = {
  balances() {
    return get('/cash-bank/balances');
  },

  /** @param {'cash'|'bank'|'all'} view */
  transactions(view = 'cash', filters = {}) {
    return get('/cash-bank/transactions', { view, ...filters });
  },

  /** @param {{direction: 'CashToBank'|'BankToCash', amount: number, description?: string}} payload */
  transfer(payload) {
    return post('/cash-bank/transfer', payload);
  },
};

export default cashBankService;
