import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react';

import { useToast } from '../../hooks/useToast.jsx';

/**
 * Renders the toast queue from useToast in a fixed top-right stack.
 * Mounted once inside AppLayout and AuthLayout.
 */

const VARIANTS = {
  success: { wrapper: 'border-brand-200 bg-white text-brand-800', Icon: CheckCircle2, iconClass: 'text-brand-600' },
  error: { wrapper: 'border-red-200 bg-white text-red-800', Icon: XCircle, iconClass: 'text-red-600' },
  warning: { wrapper: 'border-amber-200 bg-white text-amber-800', Icon: AlertTriangle, iconClass: 'text-amber-600' },
  info: { wrapper: 'border-sky-200 bg-white text-sky-800', Icon: Info, iconClass: 'text-sky-600' },
};

export default function ToastContainer() {
  const { toasts, dismiss } = useToast();

  if (!toasts.length) return null;

  return (
    <div
      className="pointer-events-none fixed right-4 top-4 z-[60] flex w-full max-w-sm flex-col gap-2 no-print"
      aria-live="polite"
      aria-atomic="false"
    >
      {toasts.map((toast) => {
        const { wrapper, Icon, iconClass } = VARIANTS[toast.variant] || VARIANTS.info;

        return (
          <div
            key={toast.id}
            role={toast.variant === 'error' ? 'alert' : 'status'}
            className={`pointer-events-auto flex items-start gap-3 rounded-md border px-4 py-3 text-sm shadow-lg ${wrapper}`}
          >
            <Icon className={`mt-0.5 h-5 w-5 shrink-0 ${iconClass}`} aria-hidden="true" />

            <div className="flex-1">
              {toast.title && <p className="font-semibold">{toast.title}</p>}
              <p className={toast.title ? 'mt-0.5 text-slate-700' : 'text-slate-700'}>{toast.message}</p>
            </div>

            <button
              type="button"
              onClick={() => dismiss(toast.id)}
              className="shrink-0 rounded p-0.5 text-slate-400 hover:text-slate-700"
              aria-label="Dismiss"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        );
      })}
    </div>
  );
}
