import { useState } from 'react';
import { CalendarCheck, RotateCcw } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Badge from '../../components/ui/Badge.jsx';
import Button from '../../components/ui/Button.jsx';
import Card, { StatCard } from '../../components/ui/Card.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import Table from '../../components/ui/Table.jsx';
import { ConfirmModal } from '../../components/ui/Modal.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import { TextAreaField } from '../../components/forms/FormField.jsx';
import periodService from '../../services/periodService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useAuth } from '../../hooks/useAuth.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { formatNPR, formatNumber } from '../../utils/currency.js';
import { bsMonthName, nextBsMonth, toNepaliNumeral } from '../../utils/bsDate.js';

/**
 * Month close and period history (Req 4.1–4.9).
 *
 * Closing is a single button because the backend does the whole sequence in one
 * transaction — credit interest, lock the month, open the next. The summary it
 * returns is surfaced afterwards so the secretary can see what was posted.
 *
 * Reopening a closed period is Super Admin only and needs a written reason
 * (Req 4.8); the reason is what the audit entry records.
 */
export default function MonthClose() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const { isSuperAdmin } = useAuth();

  const [closing, setClosing] = useState(false);
  const [summary, setSummary] = useState(null);
  const [reopenTarget, setReopenTarget] = useState(null);
  const [reason, setReason] = useState('');

  const { data: periods, loading, error, reload } = useFetch(() => periodService.list(), []);

  const { run: runClose, loading: closingBusy } = useApi(() => periodService.monthClose());
  const { run: runReopen, loading: reopening } = useApi(({ id, text }) => periodService.reopen(id, text));

  const rows = Array.isArray(periods) ? periods : [];
  const openPeriod = rows.find((period) => period.period_status === 'OPEN');

  const digits = (value) => (locale === 'ne' ? toNepaliNumeral(value) : String(value));

  const label = (period) =>
    period ? `${digits(period.bs_year)} ${bsMonthName(Number(period.bs_month), locale)}` : '—';

  const upcoming = openPeriod
    ? nextBsMonth(Number(openPeriod.bs_year), Number(openPeriod.bs_month))
    : null;

  const onClose = async () => {
    try {
      const result = await runClose();
      setSummary(result);
      setClosing(false);
      toast.success(t('period.closed'));
      reload();
    } catch (caught) {
      setClosing(false);
      toast.error(caught.message);
    }
  };

  const onReopen = async () => {
    // Req 4.8 — the reason is mandatory and the API enforces a 3-character
    // minimum; checking here keeps the modal open instead of closing on a 422.
    if (reason.trim().length < 3) {
      toast.error(t('error.min_length'));
      return;
    }

    try {
      await runReopen({ id: reopenTarget.id, text: reason });
      toast.success(t('period.reopened'));
      setReopenTarget(null);
      setReason('');
      reload();
    } catch (caught) {
      toast.error(caught.message);
    }
  };

  if (loading && !periods) return <LoadingState />;

  const columns = [
    {
      key: 'bs_year',
      label: t('savings.period'),
      render: (_value, row) => label(row),
    },
    {
      key: 'period_status',
      label: t('member.status'),
      align: 'center',
      render: (value) => (
        <Badge status={value}>{value === 'OPEN' ? t('status.open') : t('status.closed')}</Badge>
      ),
    },
    { key: 'closed_at', label: t('backup.created_at') },
    ...(isSuperAdmin
      ? [
          {
            key: 'actions',
            label: t('table.actions'),
            align: 'right',
            sortable: false,
            className: 'no-print',
            render: (_value, row) =>
              row.period_status === 'OPEN' ? (
                '—'
              ) : (
                <Button
                  variant="ghost"
                  size="sm"
                  icon={RotateCcw}
                  onClick={() => setReopenTarget(row)}
                >
                  {t('button.reopen_period')}
                </Button>
              ),
          },
        ]
      : []),
  ];

  return (
    <>
      <PageHeader
        title={t('period.month_close')}
        actions={
          <Button icon={CalendarCheck} disabled={!openPeriod} onClick={() => setClosing(true)}>
            {t('button.close_month')}
          </Button>
        }
      />

      {error && (
        <Alert variant="error" className="mb-4" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      )}

      {!openPeriod && !loading && (
        <Alert variant="warning" className="mb-4">
          {t('error.no_open_period')}
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <StatCard label={t('period.current')} value={label(openPeriod)} />
        <StatCard
          label={t('savings.period')}
          value={upcoming ? `${digits(upcoming.year)} ${bsMonthName(upcoming.month, locale)}` : '—'}
        />
      </div>

      {summary && (
        <Card className="mt-4" title={t('period.closed')}>
          <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">{t('period.members_affected')}</dt>
              <dd className="mt-1 font-medium tabular-nums">{formatNumber(summary.saving_count ?? 0, locale)}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">{t('member.total_savings')}</dt>
              <dd className="mt-1 font-medium tabular-nums">{formatNPR(summary.total_savings ?? 0, locale)}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">{t('period.interest_credited')}</dt>
              <dd className="mt-1 font-medium tabular-nums text-brand-700">
                {formatNPR(summary.total_interest ?? 0, locale)}
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">{t('cashbank.cash_in_hand')}</dt>
              <dd className="mt-1 font-medium tabular-nums">
                {formatNPR(summary.closing_cash_in_hand ?? 0, locale)}
              </dd>
            </div>
          </dl>
        </Card>
      )}

      <div className="mt-4">
        <Table
          title={t('period.title')}
          columns={columns}
          rows={rows}
          loading={loading}
          searchable={false}
          emptyMessage={t('table.no_data')}
          pageSize={24}
          printable
        />
      </div>

      <ConfirmModal
        open={closing}
        onClose={() => setClosing(false)}
        onConfirm={onClose}
        loading={closingBusy}
        title={t('button.close_month')}
        message={t('period.close_confirm')}
        confirmLabel={t('button.close_month')}
      >
        <p className="mt-2 text-sm font-medium text-slate-900">{label(openPeriod)}</p>
      </ConfirmModal>

      <ConfirmModal
        open={Boolean(reopenTarget)}
        onClose={() => {
          setReopenTarget(null);
          setReason('');
        }}
        onConfirm={onReopen}
        loading={reopening}
        variant="danger"
        title={t('period.reopen')}
        message={reopenTarget ? label(reopenTarget) : ''}
        confirmLabel={t('button.reopen_period')}
      >
        <TextAreaField
          label={t('period.reopen_reason')}
          required
          rows={3}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          containerClassName="mt-3"
        />
      </ConfirmModal>
    </>
  );
}
