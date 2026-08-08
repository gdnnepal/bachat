import { useEffect, useRef } from 'react';
import { X } from 'lucide-react';

import Button from './Button.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';

/**
 * Accessible modal dialog: closes on Escape and backdrop click, traps focus
 * inside the panel, and restores focus to the trigger on close.
 */

const SIZES = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-4xl',
};

export default function Modal({
  open,
  onClose,
  title,
  description,
  size = 'md',
  footer,
  closeOnBackdrop = true,
  children,
}) {
  const { t } = useI18n();
  const panelRef = useRef(null);
  const previouslyFocused = useRef(null);

  useEffect(() => {
    if (!open) return undefined;

    previouslyFocused.current = document.activeElement;

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        onClose?.();
        return;
      }

      if (event.key !== 'Tab' || !panelRef.current) return;

      const focusable = panelRef.current.querySelectorAll(
        'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
      );

      if (focusable.length === 0) return;

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown);
    document.body.style.overflow = 'hidden';

    // Focus the panel so screen readers announce the dialog immediately.
    const timer = setTimeout(() => panelRef.current?.focus(), 0);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.body.style.overflow = '';
      clearTimeout(timer);
      previouslyFocused.current?.focus?.();
    };
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 no-print">
      <div
        className="absolute inset-0 bg-slate-900/50"
        onClick={closeOnBackdrop ? onClose : undefined}
        aria-hidden="true"
      />

      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={typeof title === 'string' ? title : undefined}
        tabIndex={-1}
        className={`relative z-10 w-full ${SIZES[size] || SIZES.md} rounded-lg bg-white shadow-xl outline-none`}
      >
        <header className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-3">
          <div>
            <h2 className="text-base font-semibold text-slate-900">{title || t('common.confirm_title')}</h2>
            {description && <p className="mt-1 text-sm text-slate-600">{description}</p>}
          </div>

          <button
            type="button"
            onClick={onClose}
            aria-label={t('button.close')}
            className="rounded p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
          >
            <X className="h-5 w-5" />
          </button>
        </header>

        <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>

        {footer && (
          <footer className="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-3">{footer}</footer>
        )}
      </div>
    </div>
  );
}

/**
 * Yes/no confirmation built on Modal — used for deletes, month close and the
 * destructive restore and distribution confirmations.
 */
export function ConfirmModal({
  open,
  onClose,
  onConfirm,
  title,
  message,
  confirmLabel,
  cancelLabel,
  variant = 'primary',
  loading = false,
  children,
}) {
  const { t } = useI18n();

  return (
    <Modal
      open={open}
      onClose={loading ? undefined : onClose}
      title={title || t('common.confirm_title')}
      closeOnBackdrop={!loading}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading}>
            {cancelLabel || t('button.cancel')}
          </Button>
          <Button variant={variant} onClick={onConfirm} loading={loading}>
            {confirmLabel || t('button.confirm')}
          </Button>
        </>
      }
    >
      {message && <p className="text-sm text-slate-700">{message}</p>}
      {children}
    </Modal>
  );
}
