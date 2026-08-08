import { NavLink } from 'react-router-dom';
import {
  Archive,
  ArrowLeftRight,
  Landmark,
  CalendarCheck,
  FileText,
  HandCoins,
  LayoutDashboard,
  PiggyBank,
  ScrollText,
  Settings as SettingsIcon,
  Share2,
  ShieldCheck,
  Users,
  X,
} from 'lucide-react';

import { useI18n } from '../../hooks/useI18n.jsx';
import { useAuth } from '../../hooks/useAuth.jsx';

/**
 * Primary navigation. Super_Admin-only entries are filtered out for ordinary
 * admins so the UI matches what the RBAC middleware will actually allow.
 */

const NAV_GROUPS = [
  {
    items: [{ to: '/dashboard', labelKey: 'nav.dashboard', icon: LayoutDashboard }],
  },
  {
    titleKey: 'nav.members',
    items: [
      { to: '/members', labelKey: 'nav.members', icon: Users },
      { to: '/savings/bulk', labelKey: 'nav.bulk_collection', icon: PiggyBank },
      { to: '/loans', labelKey: 'nav.loans', icon: HandCoins },
      { to: '/cash-bank', labelKey: 'nav.cash_bank', icon: ArrowLeftRight },
    ],
  },
  {
    titleKey: 'nav.reports',
    items: [
      { to: '/reports', labelKey: 'nav.reports', icon: FileText },
      { to: '/distribution', labelKey: 'nav.distribution', icon: Share2 },
      { to: '/audit', labelKey: 'nav.audit', icon: ScrollText },
    ],
  },
  {
    titleKey: 'nav.settings',
    items: [
      { to: '/month-close', labelKey: 'nav.month_close', icon: CalendarCheck },
      { to: '/admin-management', labelKey: 'nav.admin_management', icon: ShieldCheck, superAdminOnly: true },
      { to: '/backup', labelKey: 'nav.backup', icon: Archive, superAdminOnly: true },
      { to: '/settings', labelKey: 'nav.settings', icon: SettingsIcon },
    ],
  },
];

function linkClasses({ isActive }) {
  return [
    'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition',
    isActive ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-300 hover:bg-slate-800 hover:text-white',
  ].join(' ');
}

export default function Sidebar({ open = false, onClose }) {
  const { t } = useI18n();
  const { isSuperAdmin } = useAuth();

  const content = (
    <nav className="flex h-full flex-col gap-6 overflow-y-auto px-3 py-4">
      <div className="flex items-center justify-between px-2">
        <div className="flex items-center gap-2">
          <Landmark className="h-6 w-6 text-brand-400" aria-hidden="true" />
          <span className="text-base font-semibold text-white">{t('app.short_name')}</span>
        </div>

        <button
          type="button"
          onClick={onClose}
          aria-label={t('button.close')}
          className="rounded p-1 text-slate-400 hover:text-white lg:hidden"
        >
          <X className="h-5 w-5" />
        </button>
      </div>

      {NAV_GROUPS.map((group, index) => {
        const items = group.items.filter((item) => !item.superAdminOnly || isSuperAdmin);
        if (items.length === 0) return null;

        return (
          <div key={group.titleKey || index}>
            {group.titleKey && (
              <p className="mb-1 px-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                {t(group.titleKey)}
              </p>
            )}

            <ul className="space-y-1">
              {items.map((item) => (
                <li key={item.to}>
                  <NavLink to={item.to} className={linkClasses} onClick={onClose} end={item.to === '/dashboard'}>
                    <item.icon className="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{t(item.labelKey)}</span>
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        );
      })}
    </nav>
  );

  return (
    <>
      {/* Desktop: always visible, part of the flex row. */}
      <aside className="hidden w-60 shrink-0 bg-slate-900 lg:block no-print">{content}</aside>

      {/* Mobile: slide-over above the page. */}
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden no-print">
          <div className="absolute inset-0 bg-slate-900/60" onClick={onClose} aria-hidden="true" />
          <aside className="relative h-full w-64 bg-slate-900 shadow-xl">{content}</aside>
        </div>
      )}
    </>
  );
}
