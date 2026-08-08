import { Outlet } from 'react-router-dom';
import { Globe, Landmark } from 'lucide-react';

import ToastContainer from '../components/ui/ToastContainer.jsx';
import { useI18n } from '../hooks/useI18n.jsx';

/**
 * Unauthenticated shell — centred card with the app branding and a language
 * toggle, so the login screen can be read before signing in.
 */
export default function AuthLayout() {
  const { t, locale, setLocale } = useI18n();

  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-slate-100 px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-6 flex flex-col items-center text-center">
          <span className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-brand-600 text-white">
            <Landmark className="h-6 w-6" aria-hidden="true" />
          </span>

          <h1 className="text-lg font-semibold text-slate-900">{t('app.name')}</h1>
          <p className="mt-1 text-sm text-slate-500">{t('app.tagline')}</p>
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <Outlet />
        </div>

        <div className="mt-4 flex justify-center">
          <button
            type="button"
            onClick={() => setLocale(locale === 'ne' ? 'en' : 'ne')}
            className="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-white hover:text-slate-900"
          >
            <Globe className="h-4 w-4" aria-hidden="true" />
            {locale === 'ne' ? 'English' : 'नेपाली'}
          </button>
        </div>
      </div>

      <ToastContainer />
    </div>
  );
}
