import { useMemo } from 'react';

import { bsMonthDays, bsMonthName, bsToAd, adToBs, currentBsDate, toNepaliNumeral } from '../../utils/bsDate.js';
import { useI18n } from '../../hooks/useI18n.jsx';

/**
 * Bikram Sambat date picker: three selects (year / month / day).
 *
 * The component speaks AD on its public surface — `value` is an AD
 * 'YYYY-MM-DD' string and `onChange(adDate, bs)` hands back both — because the
 * API stores an AD date alongside every BS triple. Internally everything is BS,
 * so the user never sees a Gregorian date.
 *
 * With React Hook Form use a <Controller>:
 *   <Controller name="date_ad" control={control}
 *     render={({ field }) => <DatePickerBS value={field.value} onChange={field.onChange} />} />
 */

const MIN_BS_YEAR = 2000;
const MAX_BS_YEAR = 2090;

export default function DatePickerBS({
  value,
  onChange,
  label,
  name,
  error,
  hint,
  required = false,
  disabled = false,
  minYear = MIN_BS_YEAR,
  maxYear = MAX_BS_YEAR,
  containerClassName = '',
}) {
  const { t, locale } = useI18n();

  const message = typeof error === 'string' ? error : error?.message;

  // Fall back to today so the selects are never blank on an empty form.
  const bs = useMemo(() => {
    if (!value) return currentBsDate();

    try {
      return adToBs(value);
    } catch {
      return currentBsDate();
    }
  }, [value]);

  const years = useMemo(() => {
    const list = [];
    for (let year = minYear; year <= maxYear; year += 1) list.push(year);
    return list;
  }, [minYear, maxYear]);

  const daysInMonth = useMemo(() => {
    try {
      return bsMonthDays(bs.year, bs.month);
    } catch {
      return 30;
    }
  }, [bs.year, bs.month]);

  const digits = (n) => (locale === 'ne' ? toNepaliNumeral(n) : String(n));

  const emit = (next) => {
    // Clamp the day — Chaitra 30 does not exist in every year.
    const maxDay = bsMonthDays(next.year, next.month);
    const day = Math.min(next.day, maxDay);
    const bsParts = { ...next, day };

    onChange?.(bsToAd(bsParts.year, bsParts.month, bsParts.day), bsParts);
  };

  const selectClass = `form-input ${message ? 'form-input-error' : ''}`;

  return (
    <div className={containerClassName}>
      {label && (
        <span className="form-label" id={name ? `${name}-label` : undefined}>
          {label}
          {required && <span className="ml-0.5 text-red-600" aria-hidden="true">*</span>}
        </span>
      )}

      <div className="grid grid-cols-3 gap-2" role="group" aria-labelledby={name ? `${name}-label` : undefined}>
        <select
          className={selectClass}
          value={bs.year}
          disabled={disabled}
          aria-label={t('common.bs_year')}
          onChange={(event) => emit({ ...bs, year: Number(event.target.value) })}
        >
          {years.map((year) => (
            <option key={year} value={year}>
              {digits(year)}
            </option>
          ))}
        </select>

        <select
          className={selectClass}
          value={bs.month}
          disabled={disabled}
          aria-label={t('common.bs_month')}
          onChange={(event) => emit({ ...bs, month: Number(event.target.value) })}
        >
          {Array.from({ length: 12 }, (_, index) => index + 1).map((month) => (
            <option key={month} value={month}>
              {bsMonthName(month, locale)}
            </option>
          ))}
        </select>

        <select
          className={selectClass}
          value={Math.min(bs.day, daysInMonth)}
          disabled={disabled}
          aria-label={t('common.bs_day')}
          onChange={(event) => emit({ ...bs, day: Number(event.target.value) })}
        >
          {Array.from({ length: daysInMonth }, (_, index) => index + 1).map((day) => (
            <option key={day} value={day}>
              {digits(day)}
            </option>
          ))}
        </select>
      </div>

      {hint && !message && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
      {message && (
        <p className="form-error" role="alert">
          {message}
        </p>
      )}
    </div>
  );
}
