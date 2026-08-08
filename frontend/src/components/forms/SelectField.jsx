import { forwardRef } from 'react';

import { useI18n } from '../../hooks/useI18n.jsx';

/**
 * Labelled <select> wired for React Hook Form.
 *
 * `options` is [{ value, label, disabled? }]. Pass `placeholder` to render a
 * disabled empty first option (for required selects).
 */
const SelectField = forwardRef(function SelectField(
  {
    label,
    name,
    options = [],
    error,
    hint,
    required = false,
    placeholder,
    includeAll = false,
    className = '',
    containerClassName = '',
    children,
    ...rest
  },
  ref,
) {
  const { t } = useI18n();

  const message = typeof error === 'string' ? error : error?.message;
  const inputId = rest.id || name;

  return (
    <div className={containerClassName}>
      {label && (
        <label htmlFor={inputId} className="form-label">
          {label}
          {required && <span className="ml-0.5 text-red-600" aria-hidden="true">*</span>}
        </label>
      )}

      <select
        ref={ref}
        id={inputId}
        name={name}
        aria-invalid={message ? 'true' : undefined}
        className={`form-input ${message ? 'form-input-error' : ''} ${className}`}
        {...rest}
      >
        {placeholder && (
          <option value="" disabled={required}>
            {placeholder === true ? t('common.select') : placeholder}
          </option>
        )}

        {includeAll && <option value="">{t('common.all')}</option>}

        {options.map((option) => (
          <option key={option.value} value={option.value} disabled={option.disabled}>
            {option.label}
          </option>
        ))}

        {children}
      </select>

      {hint && !message && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
      {message && (
        <p className="form-error" role="alert">
          {message}
        </p>
      )}
    </div>
  );
});

export default SelectField;
