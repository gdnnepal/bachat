import { Link } from 'react-router-dom';
import {
  ArrowLeftRight,
  Banknote,
  FileText,
  HandCoins,
  Landmark,
  PiggyBank,
  RefreshCw,
  Share2,
  Users,
  Wallet,
} from 'lucide-react';

import Alert from '../components/ui/Alert.jsx';
import Button from '../components/ui/Button.jsx';
import Card, { StatCard } from '../components/ui/Card.jsx';
import PageHeader from '../components/layout/PageHeader.jsx';
import { LoadingState } from '../components/ui/Spinner.jsx';
import dashboardService from '../services/dashboardService.js';
import { useFetch } from '../hooks/useApi.jsx';
import { useI18n } from '../hooks/useI18n.jsx';
import { formatNPR, formatNumber } from '../utils/currency.js';
import { toNepaliNumeral } from '../utils/bsDate.js';

/**
 * Home screen (Req 9.1–9.5).
 *
 * Every number comes from the single /dashboard aggregate, so the page makes
 * one request regardless of member count.
 */

const QUICK_ACTIONS = [
  { to: '/savings/bulk', labelKey: 'nav.bulk_collection', icon: PiggyBank },
  { to: '/loans/new', labelKey: 'button.new_loan', icon: HandCoins },
  { to: '/loans', labelKey: 'button.record_repayment', icon: Banknote },
  { to: '/members', labelKey: 'nav.members', icon: Users },
  { to: '/cash-bank', labelKey: 'nav.cash_bank', icon: ArrowLeftRight },
  { to: '/reports', labelKey: 'nav.reports', icon: FileText },
  { to: '/distribution', labelKey: 'nav.distribution', icon: Share2 },
];

export default function Dashboard() {
  const { t, locale } = useI18n();

  const { data, loading, error, reload } = useFetch(() => dashboardService.summary(locale), [locale]);

  if (loading && !data) return <LoadingState />;

  const cards = data?.cards || {};
  const activities = data?.recent_activities || [];

  const digits = (value) => (locale === 'ne' ? toNepaliNumeral(value) : String(value));

  const periodLabel =
    cards.current_bs_month_name && cards.current_bs_year
      ? `${digits(cards.current_bs_year)} ${cards.current_bs_month_name}`
      : t('error.no_open_period');

  const cycleLabel =
    cards.current_cycle_number !== null && cards.current_cycle_number !== undefined
      ? cards.current_cycle_name || `#${digits(cards.current_cycle_number)}`
      : t('common.none');

  return (
    <>
      <PageHeader
        title={t('dashboard.title')}
        subtitle={periodLabel}
        actions={
          <Button variant="secondary" size="sm" icon={RefreshCw} onClick={reload} loading={loading}>
            {t('button.refresh')}
          </Button>
        }
      />

      {error && (
        <Alert variant="error" className="mb-4" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      )}

      {/* ─── Summary cards ─────────────────────────────────────────────── */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label={t('dashboard.total_members')}
          value={formatNumber(cards.total_members ?? 0, locale)}
          icon={Users}
        />
        <StatCard
          label={t('cashbank.cash_in_hand')}
          value={formatNPR(cards.cash_in_hand ?? 0, locale)}
          icon={Wallet}
          tone="positive"
        />
        <StatCard
          label={t('cashbank.bank_balance')}
          value={formatNPR(cards.bank_balance ?? 0, locale)}
          icon={Landmark}
          tone="positive"
        />
        <StatCard
          label={t('dashboard.outstanding_loan')}
          value={formatNPR(cards.outstanding_loan ?? 0, locale)}
          hint={`${formatNumber(cards.outstanding_loan_count ?? 0, locale)} ${t('loan.title')}`}
          icon={HandCoins}
          tone="warning"
        />
      </div>

      <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <StatCard label={t('dashboard.current_period')} value={periodLabel} />
        <StatCard label={t('dashboard.current_cycle')} value={cycleLabel} />
      </div>

      {/* ─── Quick actions ─────────────────────────────────────────────── */}
      <Card title={t('dashboard.quick_actions')} className="mt-4">
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
          {QUICK_ACTIONS.map((action) => (
            <Link
              key={action.to + action.labelKey}
              to={action.to}
              className="flex flex-col items-center gap-2 rounded-md border border-slate-200 px-3 py-4 text-center text-sm font-medium text-slate-700 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
            >
              <action.icon className="h-6 w-6" aria-hidden="true" />
              {t(action.labelKey)}
            </Link>
          ))}
        </div>
      </Card>

      {/* ─── Recent activity ───────────────────────────────────────────── */}
      <Card title={t('dashboard.recent_activities')} className="mt-4" bodyClassName="p-0">
        {activities.length === 0 ? (
          <p className="px-4 py-8 text-center text-sm text-slate-500">{t('dashboard.no_activities')}</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {activities.map((entry) => (
              <li key={entry.id} className="flex flex-wrap items-start justify-between gap-2 px-4 py-2.5 text-sm">
                <div className="min-w-0">
                  <p className="font-medium text-slate-800">{entry.action_type?.replace(/_/g, ' ')}</p>
                  <p className="mt-0.5 truncate text-slate-600">{entry.description}</p>
                </div>

                <div className="shrink-0 text-right text-xs text-slate-500">
                  <p>{entry.admin_username || '—'}</p>
                  <p className="mt-0.5 tabular-nums">{entry.logged_at}</p>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  );
}
