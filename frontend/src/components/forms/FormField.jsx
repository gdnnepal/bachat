import { forwardRef } from 'react';

import { useI18n } from '../../hooks/useI18n.jsx';

/**
 * Labelled input wired for React Hook Form.
 *
 * Usage: <FormField label={t('member.full_name')} error={errors.full_name} {...register('full_name')} />
 *
 * `error` accepts either an RHF error object ({ message }) or a plain string.
 */
const FormField = forwardRef(function FormField(
  { label, name, type = 'text', error, hint, required = false, prefix, suffix, className = '', containerClassName = '', ...rest },
  ref,
) {
  const { t } = useI18n();

  const message = typeof error === 'string' ? error : error?.message;
  const inputId = rest.id || name;
  const describedBy = [message ? `${inputId}-error` : null, hint ? `${inputId}-hint` : null]
    .filter(Boolean)
    .join(' ');

  const input = (
    <input
      ref={ref}
      id={inputId}
      name={name}
      type={type}
      aria-invalid={message ? 'true' : undefined}
      aria-describedby={describedBy || undefined}
      className={`form-input ${message ? 'form-input-error' : ''} ${prefix ? 'pl-12' : ''} ${
        suffix ? 'pr-12' : ''
      } ${className}`}
      {...rest}
    />
  );

  return (
    <div className={containerClassName}>
      {label && (
        <label htmlFor={inputId} className="form-label">
          {label}
          {required && <span className="ml-0.5 text-red-600" aria-hidden="true">*</span>}
          {!required && <span className="ml-1 text-xs font-normal text-slate-400">({t('common.optional')})</span>}
        </label>
      )}

      {prefix || suffix ? (
        <div className="relative">
          {prefix && (
            <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">
              {prefix}
            </span>
          )}
          {input}
          {suffix && (
            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-500">
              {suffix}
            </span>
          )}
        </div>
      ) : (
        input
      )}

      {hint && !message && (
        <p id={`${inputId}-hint`} className="mt-1 text-xs text-slate-500">
          {hint}
        </p>
      )}

      {message && (
        <p id={`${inputId}-error`} className="form-error" role="alert">
          {message}
        </p>
      )}
    </div>
  );
});

export default FormField;

/**
 * Multi-line variant — used for descriptions and remarks.
 */
export const TextAreaField = forwardRef(function TextAreaField(
  { label, name, error, hint, required = false, rows = 3, className = '', containerClassName = '', ...rest },
  ref,
) {
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

      <textarea
        ref={ref}
        id={inputId}
        name={name}
        rows={rows}
        aria-invalid={message ? 'true' : undefined}
        className={`form-input ${message ? 'form-input-error' : ''} ${className}`}
        {...rest}
      />

      {hint && !message && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
      {message && (
        <p className="form-error" role="alert">
          {message}
        </p>
      )}
    </div>
  );
});
