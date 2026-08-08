/**
 * bsDate.js — Bikram Sambat ↔ Gregorian (AD) calendar utilities
 *
 * Pure ES module, no external dependencies.
 * Mirrors the algorithm and lookup table from backend/app/helpers/BsCalendar.php.
 *
 * Epoch: BS 2000/01/01 = AD 1943/04/14
 * Lookup table covers BS years 2000–2090.
 *
 * Requirements: 4.2, 4.5
 */

// ---------------------------------------------------------------------------
// Epoch
// ---------------------------------------------------------------------------

/** AD date of the BS epoch start (BS 2000-01-01). */
const EPOCH_AD = '1943-04-14';

// ---------------------------------------------------------------------------
// Lookup table — MUST stay identical to BsCalendar.php::$table
// Each entry: [Baishakh, Jestha, Ashadh, Shrawan, Bhadra, Ashwin,
//              Kartik, Mangsir, Poush, Magh, Falgun, Chaitra]
// ---------------------------------------------------------------------------

const BS_TABLE = {
  2000: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2001: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2002: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2003: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2004: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2005: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2006: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2007: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2008: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
  2009: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2010: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2011: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2012: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
  2013: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2014: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2015: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2016: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
  2017: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2018: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2019: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2020: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
  2021: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2022: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
  2023: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2024: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
  2025: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2026: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2027: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2028: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2029: [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
  2030: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2031: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2032: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2033: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2034: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2035: [30, 32, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
  2036: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2037: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2038: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2039: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
  2040: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2041: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2042: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2043: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
  2044: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2045: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2046: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2047: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
  2048: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2049: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
  2050: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2051: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
  2052: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2053: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
  2054: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2055: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2056: [31, 31, 32, 31, 32, 30, 30, 29, 30, 29, 30, 30],
  2057: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2058: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2059: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2060: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2061: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2062: [30, 32, 31, 32, 31, 31, 29, 30, 29, 30, 29, 31],
  2063: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2064: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2065: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2066: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 29, 31],
  2067: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2068: [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2069: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2070: [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30],
  2071: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2072: [31, 32, 31, 32, 31, 30, 30, 29, 30, 29, 30, 30],
  2073: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31],
  2074: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
  2075: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2076: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30],
  2077: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31],
  2078: [31, 31, 31, 32, 31, 31, 30, 29, 30, 29, 30, 30],
  2079: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30],
  2080: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 30], // spec
  2081: [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // spec
  2082: [31, 32, 31, 32, 31, 30, 30, 30, 29, 30, 29, 31], // spec
  2083: [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // spec
  2084: [31, 31, 32, 31, 31, 30, 30, 30, 29, 30, 30, 30],
  2085: [31, 32, 31, 32, 30, 31, 30, 30, 29, 30, 30, 30],
  2086: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
  2087: [31, 31, 32, 31, 31, 31, 30, 30, 29, 30, 30, 30],
  2088: [30, 31, 32, 32, 30, 31, 30, 30, 29, 30, 30, 30],
  2089: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
  2090: [30, 32, 31, 32, 31, 30, 30, 30, 29, 30, 30, 30],
};

// ---------------------------------------------------------------------------
// Month name tables (1-indexed arrays)
// ---------------------------------------------------------------------------

const MONTH_NAMES_EN = [
  null, // placeholder so index 1 = Baishakh
  'Baishakh',
  'Jestha',
  'Ashadh',
  'Shrawan',
  'Bhadra',
  'Ashwin',
  'Kartik',
  'Mangsir',
  'Poush',
  'Magh',
  'Falgun',
  'Chaitra',
];

const MONTH_NAMES_NE = [
  null,
  'बैशाख',
  'जेठ',
  'असार',
  'श्रावण',
  'भदौ',
  'असोज',
  'कार्तिक',
  'मंसिर',
  'पुष',
  'माघ',
  'फाल्गुन',
  'चैत्र',
];

// ---------------------------------------------------------------------------
// Internal helpers
// ---------------------------------------------------------------------------

/**
 * Parse an AD date string 'YYYY-MM-DD' and return a UTC-normalised Date.
 * @param {string} adDateString
 * @returns {Date}
 */
function parseAdDate(adDateString) {
  const [year, month, day] = adDateString.split('-').map(Number);
  // Use UTC to avoid timezone-shift surprises.
  return new Date(Date.UTC(year, month - 1, day));
}

/**
 * Convert a Date to 'YYYY-MM-DD' using its UTC components.
 * @param {Date} date
 * @returns {string}
 */
function formatAdDate(date) {
  const y = date.getUTCFullYear();
  const m = String(date.getUTCMonth() + 1).padStart(2, '0');
  const d = String(date.getUTCDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/**
 * Count calendar days between two UTC-normalised Dates (toDate − fromDate).
 * @param {Date} fromDate
 * @param {Date} toDate
 * @returns {number}
 */
function daysBetween(fromDate, toDate) {
  const MS_PER_DAY = 86_400_000;
  return Math.round((toDate.getTime() - fromDate.getTime()) / MS_PER_DAY);
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Convert an AD date string to a BS date object.
 *
 * Mirrors BsCalendar::adToBs() exactly: count days from the epoch, then
 * walk the lookup table year-by-year and month-by-month.
 *
 * @param {string} adDateString  AD date in 'YYYY-MM-DD' format
 * @returns {{ year: number, month: number, day: number }}
 */
export function adToBs(adDateString) {
  const epochDate = parseAdDate(EPOCH_AD);
  const adDate    = parseAdDate(adDateString);

  let totalDays = daysBetween(epochDate, adDate);

  if (totalDays < 0) {
    throw new RangeError(
      `AD date '${adDateString}' is before the BS epoch (AD ${EPOCH_AD}).`
    );
  }

  let bsYear  = 2000;
  let bsMonth = 1;
  let bsDay   = 1;

  for (const [yearStr, months] of Object.entries(BS_TABLE)) {
    const year       = Number(yearStr);
    const daysInYear = months.reduce((a, b) => a + b, 0);

    if (totalDays < daysInYear) {
      bsYear = year;

      for (let mIdx = 0; mIdx < months.length; mIdx++) {
        const daysInMonth = months[mIdx];
        if (totalDays < daysInMonth) {
          bsMonth = mIdx + 1; // mIdx is 0-based
          bsDay   = totalDays + 1;
          break;
        }
        totalDays -= daysInMonth;
      }

      break;
    }

    totalDays -= daysInYear;
  }

  return { year: bsYear, month: bsMonth, day: bsDay };
}

/**
 * Convert a BS date to an AD date string 'YYYY-MM-DD'.
 *
 * Mirrors BsCalendar::bsToAd() exactly: count total days from the epoch to
 * the target BS date, then add that offset to the epoch AD date.
 *
 * @param {number} year   BS year  (2000–2090)
 * @param {number} month  BS month (1–12)
 * @param {number} day    BS day
 * @returns {string}
 */
export function bsToAd(year, month, day) {
  const months = BS_TABLE[year];
  if (!months) {
    throw new RangeError(`BS year ${year} is outside the supported range (2000–2090).`);
  }
  if (month < 1 || month > 12) {
    throw new RangeError(`Month must be 1–12, got ${month}.`);
  }
  const maxDay = months[month - 1];
  if (day < 1 || day > maxDay) {
    throw new RangeError(`Day ${day} is invalid for BS ${year}/${month} (max ${maxDay}).`);
  }

  // Count total days from epoch (BS 2000-01-01) up to (but not including) the
  // target date — same as PHP: complete years + complete months + (day − 1).
  let totalDays = 0;

  for (const [yearStr, yMonths] of Object.entries(BS_TABLE)) {
    const y = Number(yearStr);
    if (y === year) {
      // Add days for complete months before the target month.
      for (let m = 0; m < month - 1; m++) {
        totalDays += yMonths[m];
      }
      // Add days within the target month (day is 1-based).
      totalDays += day - 1;
      break;
    }
    totalDays += yMonths.reduce((a, b) => a + b, 0);
  }

  const epochDate = parseAdDate(EPOCH_AD);
  const resultDate = new Date(epochDate.getTime() + totalDays * 86_400_000);
  return formatAdDate(resultDate);
}

/**
 * Return the next BS month, rolling Chaitra (month 12) into the next year.
 *
 * @param {number} year
 * @param {number} month  (1–12)
 * @returns {{ year: number, month: number }}
 */
export function nextBsMonth(year, month) {
  if (month === 12) {
    return { year: year + 1, month: 1 };
  }
  return { year, month: month + 1 };
}

/**
 * Return the BS month name in English or Nepali.
 *
 * @param {number} month   BS month (1–12)
 * @param {string} locale  'ne' for Nepali, anything else for English
 * @returns {string}
 */
export function bsMonthName(month, locale = 'en') {
  if (month < 1 || month > 12) {
    throw new RangeError(`Month must be 1–12, got ${month}.`);
  }
  return locale === 'ne' ? MONTH_NAMES_NE[month] : MONTH_NAMES_EN[month];
}

/**
 * Return the number of days in a given BS year/month from the lookup table.
 *
 * @param {number} year   BS year  (2000–2090)
 * @param {number} month  BS month (1–12)
 * @returns {number}
 */
export function bsMonthDays(year, month) {
  const months = BS_TABLE[year];
  if (!months) {
    throw new RangeError(`BS year ${year} is outside the supported range (2000–2090).`);
  }
  if (month < 1 || month > 12) {
    throw new RangeError(`Month must be 1–12, got ${month}.`);
  }
  return months[month - 1];
}

/**
 * Convert a non-negative integer to a string of Nepali Unicode numerals.
 *
 * Western digits 0–9 map to Nepali ०–९ (U+0966–U+096F).
 *
 * @param {number} n
 * @returns {string}
 */
export function toNepaliNumeral(n) {
  return String(n).replace(/[0-9]/g, (d) => String.fromCharCode(0x0966 + Number(d)));
}

/**
 * Format a BS date as a human-readable string.
 *
 * English:  "2083 Shrawan 15"
 * Nepali:   "२०८३ श्रावण १५"
 *
 * @param {number} year
 * @param {number} month
 * @param {number} day
 * @param {string} locale  'ne' for Nepali, anything else for English
 * @returns {string}
 */
export function formatBsDate(year, month, day, locale = 'en') {
  if (locale === 'ne') {
    return `${toNepaliNumeral(year)} ${bsMonthName(month, 'ne')} ${toNepaliNumeral(day)}`;
  }
  return `${year} ${bsMonthName(month, 'en')} ${day}`;
}

/**
 * Return today's date as a BS date object.
 *
 * @returns {{ year: number, month: number, day: number }}
 */
export function currentBsDate() {
  const today = new Date();
  const adString = formatAdDate(
    new Date(Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()))
  );
  return adToBs(adString);
}
