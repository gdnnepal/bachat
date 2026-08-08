import { AlertTriangle, CheckCircle2, Info, XCircle, X } from 'lucide-react';

/**
 * Inline message block for page-level success, warning and error states.
 */

const VARIANTS = {
  info: { wrapper: 'bg-sky-50 border-sky-200 text-sky-800', Icon: Info },
  success: { wrapper: 'bg-brand-50 border-brand-200 text-brand-800', Icon: CheckCircle2 },
  warning: { wrapper: 'bg-amber-50 border-amber-200 text-amber-800', Icon: AlertTriangle },
  error: { wrapper: 'bg-red-50 border-red-200 text-red-800', Icon: XCircle },
};

export default function Alert({ variant = 'info', title, children, onDismiss, actions, className = '' }) {
  const { wrapper, Icon } = VARIANTS[variant] || VARIANTS.info;

  return (
    <div
      role={variant === 'error' ? 'alert' : 'status'}
      className={`flex items-start gap-3 rounded-md border px-4 py-3 text-sm ${wrapper} ${className}`}
    >
      <Icon className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />

      <div className="flex-1">
        {title && <p className="font-semibold">{title}</p>}
        {children && <div className={title ? 'mt-1' : ''}>{children}</div>}
        {actions && <div className="mt-2 flex gap-2">{actions}</div>}
      </div>

      {onDismiss && (
        <button type="button" onClick={onDismiss} className="shrink-0 rounded p-0.5 opacity-70 hover:opacity-100">
          <X className="h-4 w-4" />
        </button>
      )}
    </div>
  );
}
