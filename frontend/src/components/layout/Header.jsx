import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ChevronDown, Globe, KeyRound, LogOut, Menu, User } from 'lucide-react';

import { useI18n } from '../../hooks/useI18n.jsx';
import { useAuth } from '../../hooks/useAuth.jsx';
import { useToast } from '../../hooks/useToast.jsx';

/**
 * Top bar: mobile menu trigger, cooperative name, language toggle and the
 * signed-in user menu.
 */
export default function Header({ onMenuClick, cooperativeName }) {
  const { t, locale, setLocale } = useI18n();
  const { admin, logout } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const [menuOpen, setMenuOpen] = useState(false);
  const menuRef = useRef(null);

  // Close the user menu on any outside click.
  useEffect(() => {
    if (!menuOpen) return undefined;

    const onDocumentClick = (event) => {
      if (menuRef.current && !menuRef.current.contains(event.target)) setMenuOpen(false);
    };

    document.addEventListener('mousedown', onDocumentClick);
    return () => document.removeEventListener('mousedown', onDocumentClick);
  }, [menuOpen]);

  const onToggleLanguage = () => setLocale(locale === 'ne' ? 'en' : 'ne');

  const onLogout = async () => {
    setMenuOpen(false);
    await logout();
    toast.success(t('auth.logged_out'));
    navigate('/login', { replace: true });
  };

  return (
    <header className="flex h-14 shrink-0 items-center justify-between gap-3 border-b border-slate-200 bg-white px-4 no-print">
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={onMenuClick}
          aria-label={t('nav.dashboard')}
          className="rounded p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
        >
          <Menu className="h-5 w-5" />
        </button>

        <h1 className="truncate text-sm font-semibold text-slate-800 sm:text-base">
          {cooperativeName || t('app.name')}
        </h1>
      </div>

      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={onToggleLanguage}
          title={t('settings.language')}
          className="inline-flex items-center gap-1.5 rounded-md border border-slate-300 px-2.5 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
        >
          <Globe className="h-4 w-4" aria-hidden="true" />
          {locale === 'ne' ? 'नेपाली' : 'English'}
        </button>

        <div className="relative" ref={menuRef}>
          <button
            type="button"
            onClick={() => setMenuOpen((open) => !open)}
            aria-haspopup="menu"
            aria-expanded={menuOpen}
            className="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-slate-700 transition hover:bg-slate-100"
          >
            <span className="flex h-7 w-7 items-center justify-center rounded-full bg-brand-100 text-brand-700">
              <User className="h-4 w-4" aria-hidden="true" />
            </span>
            <span className="hidden max-w-[10rem] truncate sm:inline">{admin?.name || admin?.username}</span>
            <ChevronDown className="h-4 w-4 text-slate-400" aria-hidden="true" />
          </button>

          {menuOpen && (
            <div
              role="menu"
              className="absolute right-0 z-30 mt-1 w-56 overflow-hidden rounded-md border border-slate-200 bg-white shadow-lg"
            >
              <div className="border-b border-slate-100 px-3 py-2">
                <p className="truncate text-sm font-medium text-slate-800">{admin?.name || admin?.username}</p>
                <p className="mt-0.5 text-xs text-slate-500">{admin?.role?.replace('_', ' ')}</p>
              </div>

              <button
                type="button"
                role="menuitem"
                onClick={() => {
                  setMenuOpen(false);
                  navigate('/change-password');
                }}
                className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
              >
                <KeyRound className="h-4 w-4" aria-hidden="true" />
                {t('button.change_password')}
              </button>

              <button
                type="button"
                role="menuitem"
                onClick={onLogout}
                className="flex w-full items-center gap-2 border-t border-slate-100 px-3 py-2 text-left text-sm text-red-600 hover:bg-red-50"
              >
                <LogOut className="h-4 w-4" aria-hidden="true" />
                {t('nav.logout')}
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
