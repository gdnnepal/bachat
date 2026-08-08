/**
 * Card container plus a StatCard variant for the dashboard summary tiles.
 */

export default function Card({ title, subtitle, actions, footer, className = '', bodyClassName = '', children }) {
  return (
    <section className={`card ${className}`}>
      {(title || actions) && (
        <header className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
          <div>
            {title && <h2 className="text-sm font-semibold text-slate-800">{title}</h2>}
            {subtitle && <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>}
          </div>
          {actions && <div className="flex shrink-0 items-center gap-2 no-print">{actions}</div>}
        </header>
      )}

      <div className={`px-4 py-4 ${bodyClassName}`}>{children}</div>

      {footer && <footer className="border-t border-slate-200 px-4 py-3 text-sm">{footer}</footer>}
    </section>
  );
}

/**
 * Summary tile: label, big value, optional hint line and icon.
 */
export function StatCard({ label, value, hint, icon: Icon, tone = 'default', to, onClick }) {
  const tones = {
    default: 'text-slate-900',
    positive: 'text-brand-700',
    warning: 'text-amber-600',
    danger: 'text-red-600',
  };

  const interactive = Boolean(to || onClick);

  return (
    <div
      className={`card px-4 py-3 ${interactive ? 'cursor-pointer transition hover:border-brand-300 hover:shadow' : ''}`}
      onClick={onClick}
      role={interactive ? 'button' : undefined}
      tabIndex={interactive ? 0 : undefined}
      onKeyDown={interactive ? (event) => event.key === 'Enter' && onClick?.() : undefined}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
        {Icon && <Icon className="h-5 w-5 shrink-0 text-slate-400" aria-hidden="true" />}
      </div>

      <p className={`mt-2 text-2xl font-semibold tabular-nums ${tones[tone] || tones.default}`}>{value}</p>

      {hint && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
    </div>
  );
}
