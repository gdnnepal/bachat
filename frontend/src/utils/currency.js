/**
 * Currency and rounding helpers.
 *
 * All monetary arithmetic in this system is DECIMAL(15,2) in MySQL and
 * PHP_ROUND_HALF_UP in PHP. JavaScript's Math.round() is half-up for positive
 * numbers but half-toward-+Infinity for negatives (Math.round(-0.5) === -0),
 * and toFixed() rounds half-to-even on some values because of binary floats.
 * roundHalfUp() below matches PHP exactly: magnitude is rounded half-away-from
 * -zero, then the sign is restored.
 */

import { toNepaliNumeral } from './bsDate.js';

/**
 * Round to `decimals` places using half-up on the magnitude, matching
 * PHP's PHP_ROUND_HALF_UP.
 *
 *   roundHalfUp(2.675, 2)  → 2.68   (toFixed gives 2.67)
 *   roundHalfUp(-2.5, 0)   → -3     (Math.round gives -2)
 *
 * @param {number|string} value
 * @param {number} decimals
 * @returns {number}
 */
export function roundHalfUp(value, decimals = 2) {
  const num = typeof value === 'number' ? value : parseFloat(value);

  if (!Number.isFinite(num)) return 0;

  const sign = num < 0 ? -1 : 1;
  const factor = 10 ** decimals;

  // Scale through a string to shed the binary-float error that makes
  // 2.675 * 100 land on 267.49999999999997.
  const scaled = parseFloat((Math.abs(num) * factor).toPrecision(15));

  return (sign * Math.round(scaled)) / factor;
}

/**
 * Format a number as NPR with exactly two decimal places and thousand
 * separators.
 *
 *   formatNPR(1234.5)            → "NPR 1,234.50"
 *   formatNPR(1234.5, 'ne')      → "रु. १,२३४.५०"
 *   formatNPR(1234.5, 'en', {symbol: false}) → "1,234.50"
 *
 * @param {number|string} amount
 * @param {'en'|'ne'} locale
 * @param {{symbol?: boolean}} options
 * @returns {string}
 */
export function formatNPR(amount, locale = 'en', options = {}) {
  const { symbol = true } = options;
  const rounded = roundHalfUp(amount, 2);

  const formatted = groupThousands(rounded.toFixed(2));

  if (locale === 'ne') {
    const nepali = toNepaliDigits(formatted);
    return symbol ? `रु. ${nepali}` : nepali;
  }

  return symbol ? `NPR ${formatted}` : formatted;
}

/**
 * Format a plain number (counts, not money) with thousand separators.
 */
export function formatNumber(value, locale = 'en') {
  const num = Number(value) || 0;
  const formatted = groupThousands(String(Math.trunc(num)));

  return locale === 'ne' ? toNepaliDigits(formatted) : formatted;
}

/**
 * Format a percentage with up to two decimals, trimming trailing zeros.
 *
 *   formatPercent(12)    → "12%"
 *   formatPercent(12.5)  → "12.5%"
 */
export function formatPercent(value, locale = 'en') {
  const rounded = roundHalfUp(value, 2);
  const text = String(rounded);

  return locale === 'ne' ? `${toNepaliDigits(text)}%` : `${text}%`;
}

/**
 * Parse user input into a number, tolerating commas, the NPR prefix and
 * Nepali digits. Returns NaN for anything unusable so callers can validate.
 */
export function parseAmount(input) {
  if (typeof input === 'number') return input;
  if (input === null || input === undefined || input === '') return NaN;

  const latin = fromNepaliDigits(String(input));
  const cleaned = latin.replace(/[^\d.-]/g, '');

  return cleaned === '' ? NaN : parseFloat(cleaned);
}

/**
 * True when the value has at most two decimal places — mirrors the
 * `decimal_places:2` backend rule so the form can reject early.
 */
export function hasAtMostTwoDecimals(input) {
  const text = fromNepaliDigits(String(input ?? '')).trim();

  if (text === '') return true;

  const match = /^-?\d*(?:\.(\d*))?$/.exec(text);

  return match ? (match[1] ? match[1].length <= 2 : true) : false;
}

// ─── Internal helpers ────────────────────────────────────────────────────────

/**
 * Insert commas every three digits in the integer part, leaving any decimal
 * portion untouched. (Western grouping — the cooperative's ledgers and the
 * generated PDFs both use it, so the UI stays consistent with the paper trail.)
 */
function groupThousands(text) {
  const negative = text.startsWith('-');
  const body = negative ? text.slice(1) : text;
  const [whole, fraction] = body.split('.');

  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const result = fraction === undefined ? grouped : `${grouped}.${fraction}`;

  return negative ? `-${result}` : result;
}

/** Convert every ASCII digit in a string to its Devanagari counterpart. */
function toNepaliDigits(text) {
  return String(text).replace(/\d/g, (digit) => toNepaliNumeral(Number(digit)));
}

/** Convert Devanagari digits back to ASCII so parsing works on Nepali input. */
function fromNepaliDigits(text) {
  const devanagari = '०१२३४५६७८९';

  return String(text).replace(/[०-९]/g, (char) => String(devanagari.indexOf(char)));
}

export { toNepaliDigits, fromNepaliDigits };
