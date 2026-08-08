/**
 * Small status pill. `status` maps the domain's status strings onto tones so
 * callers can pass the raw API value.
 */

const TONES = {
  neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
  success: 'bg-brand-50 text-brand-700 ring-brand-200',
  warning: 'bg-amber-50 text-amber-700 ring-amber-200',
  danger: 'bg-red-50 text-red-700 ring-red-200',
  info: 'bg-sky-50 text-sky-700 ring-sky-200',
};

const STATUS_TONES = {
  Active: 'success',
  Open: 'success',
  OPEN: 'success',
  Completed: 'info',
  PdfGenerated: 'warning',
  Outstanding: 'warning',
  Pending: 'warning',
  Inactive: 'neutral',
  Closed: 'neutral',
  CLOSED: 'neutral',
  Cancelled: 'danger',
};

export default function Badge({ children, tone, status, className = '' }) {
  const resolved = tone || STATUS_TONES[status] || 'neutral';

  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${
        TONES[resolved] || TONES.neutral
      } ${className}`}
    >
      {children ?? status}
    </span>
  );
}
