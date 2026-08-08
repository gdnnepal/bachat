import { Link } from 'react-router-dom';
import { ChevronLeft } from 'lucide-react';

/**
 * Page title block with an optional back link and right-aligned actions.
 * Hidden from print output except the title, which prints as the report heading.
 */
export default function PageHeader({ title, subtitle, backTo, backLabel, actions, className = '' }) {
  return (
    <div className={`mb-4 flex flex-wrap items-start justify-between gap-3 ${className}`}>
      <div>
        {backTo && (
          <Link
            to={backTo}
            className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 transition hover:text-brand-700 no-print"
          >
            <ChevronLeft className="h-3.5 w-3.5" aria-hidden="true" />
            {backLabel}
          </Link>
        )}

        <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
        {subtitle && <p className="mt-0.5 text-sm text-slate-500">{subtitle}</p>}
      </div>

      {actions && <div className="flex flex-wrap items-center gap-2 no-print">{actions}</div>}
    </div>
  );
}
