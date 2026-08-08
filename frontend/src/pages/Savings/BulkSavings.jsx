import { useEffect, useMemo, useState } from 'react';
import { CheckCircle2, PiggyBank, Search } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Badge from '../../components/ui/Badge.jsx';
import Button from '../../components/ui/Button.jsx';
import Card, { StatCard } from '../../components/ui/Card.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import { ConfirmModal } from '../../components/ui/Modal.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import savingsService from '../../services/savingsService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { formatNPR, formatNumber } from '../../utils/currency.js';
import { bsMonthName, toNepaliNumeral } from '../../utils/bsDate.js';

/**
 * Bulk monthly savings collection (Req 5.1–5.5).
 *
 * The whole point of this screen is that the secretary never opens a member
 * individually: tick everyone who paid at the meeting, press once, done.
 * Members who already saved this period are shown disabled rather than hidden,
 * so the list still matches the attendance sheet being read from.
 */
export default function BulkSavings() {
  const { t, locale } = useI18n();
  const toast = useToast();

  const [selected, setSelected] = useState(() => new Set());
  const [query, setQuery] = useState('');
  const [confirming, setConfirming] = useState(false);

  const { data, loading, error, reload } = useFetch(() => savingsService.bulkScreen(), []);

  const { run: runCollect, loading: collecting } = useApi((ids) => savingsService.bulkCollect(ids));

  const members = useMemo(() => data?.members || [], [data]);
  const fixedAmount = Number(data?.fixed_amount || 0);
  const period = data?.period;

  const collectable = useMemo(() => members.filter((member) => !member.already_paid), [members]);

  // A reload (or a fresh period) invalidates any previous tick marks.
  useEffect(() => {
    setSelected(new Set());
  }, [data]);

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return members;

    return members.filter((member) =>
      `${member.member_id} ${member.full_name} ${member.phone || ''}`.toLowerCase().includes(needle),
    );
  }, [members, query]);

  const toggle = (memberId) => {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(memberId)) next.delete(memberId);
      else next.add(memberId);
      return next;
    });
  };

  // Select-all applies to what is currently visible, so a filtered list can be
  // ticked without also selecting members the user cannot see.
  const selectAllVisible = () => {
    setSelected((current) => {
      const next = new Set(current);
      visible.filter((member) => !member.already_paid).forEach((member) => next.add(member.id));
      return next;
    });
  };

  const onCollect = async () => {
    try {
      const result = await runCollect([...selected]);
      setConfirming(false);

      const saved = result?.saved ?? 0;
      const skipped = result?.skipped ?? 0;

      toast.success(
        `${formatNumber(saved, locale)} ${t('savings.collected')}` +
          (skipped > 0 ? ` ${formatNumber(skipped, locale)} ${t('savings.skipped')}` : ''),
      );

      reload();
    } catch (caught) {
      setConfirming(false);
      toast.error(caught.message);
    }
  };

  if (loading && !data) return <LoadingState />;

  // No open period is a 409 from the API — nothing can be collected (Req 4.7).
  if (error) {
    return (
      <>
        <PageHeader title={t('savings.title')} />
        <Alert variant="error" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      </>
    );
  }

  const digits = (value) => (locale === 'ne' ? toNepaliNumeral(value) : String(value));

  const periodLabel = period
    ? `${digits(period.bs_year)} ${bsMonthName(Number(period.bs_month), locale)}`
    : t('error.no_open_period');

  const selectedTotal = selected.size * fixedAmount;

  return (
    <>
      <PageHeader
        title={t('savings.title')}
        subtitle={`${t('savings.period')}: ${periodLabel}`}
        actions={
          <>
            <Button variant="secondary" onClick={selectAllVisible} disabled={collectable.length === 0}>
              {t('button.select_all')}
            </Button>
            <Button variant="secondary" onClick={() => setSelected(new Set())} disabled={selected.size === 0}>
              {t('button.clear_selection')}
            </Button>
            <Button icon={PiggyBank} disabled={selected.size === 0} onClick={() => setConfirming(true)}>
              {t('button.collect_savings')}
            </Button>
          </>
        }
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label={t('savings.amount_per_member')} value={formatNPR(fixedAmount, locale)} />
        <StatCard
          label={t('savings.selected_count')}
          value={formatNumber(selected.size, locale)}
          hint={`${formatNumber(collectable.length, locale)} ${t('common.all').toLowerCase()}`}
        />
        <StatCard label={t('common.amount')} value={formatNPR(selectedTotal, locale)} tone="positive" />
      </div>

      <Card className="mt-4" bodyClassName="p-0">
        <div className="border-b border-slate-200 px-4 py-3 no-print">
          <div className="relative max-w-sm">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
            <input
              type="search"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder={t('member.search_placeholder')}
              aria-label={t('table.search')}
              className="form-input pl-8"
            />
          </div>
        </div>

        {visible.length === 0 ? (
          <p className="px-4 py-10 text-center text-sm text-slate-500">{t('member.none_found')}</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {visible.map((member) => {
              const checked = selected.has(member.id);
              const paid = member.already_paid;

              return (
                <li key={member.id}>
                  <label
                    className={`flex cursor-pointer items-center gap-3 px-4 py-2.5 text-sm transition ${
                      paid ? 'cursor-not-allowed bg-slate-50 text-slate-400' : 'hover:bg-brand-50/50'
                    }`}
                  >
                    <input
                      type="checkbox"
                      className="h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-brand-500 disabled:opacity-50"
                      checked={checked}
                      disabled={paid}
                      onChange={() => toggle(member.id)}
                    />

                    <span className="w-24 shrink-0 font-mono text-xs text-slate-500">{member.member_id}</span>

                    <span className={`min-w-0 flex-1 truncate ${paid ? '' : 'font-medium text-slate-800'}`}>
                      {member.full_name}
                    </span>

                    <span className="hidden w-32 shrink-0 text-slate-500 sm:block">{member.phone || '—'}</span>

                    {paid ? (
                      <Badge tone="info" className="shrink-0">
                        <CheckCircle2 className="mr-1 h-3 w-3" aria-hidden="true" />
                        {t('savings.already_paid')}
                      </Badge>
                    ) : (
                      <span className="shrink-0 tabular-nums text-slate-700">
                        {formatNPR(member.amount ?? fixedAmount, locale)}
                      </span>
                    )}
                  </label>
                </li>
              );
            })}
          </ul>
        )}
      </Card>

      <ConfirmModal
        open={confirming}
        onClose={() => setConfirming(false)}
        onConfirm={onCollect}
        loading={collecting}
        title={t('button.collect_savings')}
        confirmLabel={t('button.collect_savings')}
        message={`${formatNumber(selected.size, locale)} ${t('savings.selected_count')} — ${formatNPR(
          selectedTotal,
          locale,
        )}`}
      >
        <p className="mt-2 text-sm text-slate-600">
          {t('savings.period')}: {periodLabel}
        </p>
      </ConfirmModal>
    </>
  );
}
