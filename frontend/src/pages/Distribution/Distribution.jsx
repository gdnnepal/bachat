import { useState } from 'react';
import { Link } from 'react-router-dom';
import { CheckCircle2, Download, FileText, ShieldAlert } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Badge from '../../components/ui/Badge.jsx';
import Button from '../../components/ui/Button.jsx';
import { StatCard } from '../../components/ui/Card.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import Table from '../../components/ui/Table.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import distributionService from '../../services/distributionService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { formatNPR, formatNumber } from '../../utils/currency.js';

/**
 * Distribution ledger — phase 1 of the end-of-cycle payout (Req 10.1–10.3).
 *
 * The ledger here is a live preview: nothing is written until Generate PDF, and
 * no money moves until the separate confirmation screen. Members whose
 * outstanding loan exceeds their savings show a shortfall and are paid nothing,
 * which the secretary needs to see before printing signature sheets.
 */
export default function Distribution() {
  const { t, locale } = useI18n();
  const toast = useToast();

  const [downloading, setDownloading] = useState(false);

  const { data, loading, error, reload } = useFetch(() => distributionService.current(), []);

  const { run: runGenerate, loading: generating } = useApi(() => distributionService.generatePdf());

  const cycle = data?.cycle;
  const items = data?.items || [];
  const totals = data?.totals || {};
  const pdfReady = Boolean(data?.pdf_ready);
  const balances = data?.balances || {};

  const availableFunds = Number(balances.cash_in_hand || 0) + Number(balances.bank_balance || 0);
  const required = Number(totals.final_payable || 0);
  const underfunded = required > availableFunds;

  const onGenerate = async () => {
    try {
      await runGenerate();
      toast.success(t('distribution.pdf_generated'));
      reload();
    } catch (caught) {
      toast.error(caught.message);
    }
  };

  const onDownload = async () => {
    setDownloading(true);
    try {
      await distributionService.downloadPdf(cycle.id);
    } catch {
      toast.error(t('error.generic'));
    } finally {
      setDownloading(false);
    }
  };

  if (loading && !data) return <LoadingState />;

  if (error) {
    return (
      <>
        <PageHeader title={t('distribution.title')} />
        <Alert variant="error" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      </>
    );
  }

  const columns = [
    { key: 'member_code', label: t('member.member_id') },
    { key: 'full_name', label: t('loan.member') },
    { key: 'total_savings', label: t('distribution.savings'), type: 'money' },
    { key: 'total_interest', label: t('distribution.interest'), type: 'money' },
    { key: 'total_outstanding_loan', label: t('distribution.outstanding_loan'), type: 'money' },
    { key: 'final_payable', label: t('distribution.final_payable'), type: 'money' },
    {
      key: 'is_shortfall',
      label: t('distribution.shortfall'),
      align: 'center',
      render: (value, row) =>
        value ? <Badge tone="danger">{formatNPR(row.shortfall_amount, locale)}</Badge> : '—',
    },
  ];

  return (
    <>
      <PageHeader
        title={t('distribution.title')}
        subtitle={cycle ? cycle.name || `#${cycle.cycle_number}` : undefined}
        actions={
          <>
            <Button variant="secondary" icon={FileText} loading={generating} onClick={onGenerate}>
              {t('button.generate_pdf')}
            </Button>

            {pdfReady && (
              <Button variant="secondary" icon={Download} loading={downloading} onClick={onDownload}>
                {t('button.download')}
              </Button>
            )}

            <Button as={Link} to="/distribution/confirm" icon={CheckCircle2} variant={pdfReady ? 'primary' : 'secondary'}>
              {t('button.confirm_distribution')}
            </Button>
          </>
        }
      />

      {!pdfReady && (
        <Alert variant="info" className="mb-4">
          {t('distribution.pdf_required')}
        </Alert>
      )}

      {underfunded && (
        <Alert variant="warning" className="mb-4" title={t('cashbank.insufficient')}>
          {`${t('distribution.total_disbursed')}: ${formatNPR(required, locale)} — ${t('cashbank.total')}: ${formatNPR(
            availableFunds,
            locale,
          )}`}
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <StatCard label={t('distribution.member_count')} value={formatNumber(items.length, locale)} />
        <StatCard label={t('distribution.savings')} value={formatNPR(totals.total_savings ?? 0, locale)} />
        <StatCard label={t('distribution.interest')} value={formatNPR(totals.total_interest ?? 0, locale)} />
        <StatCard
          label={t('distribution.outstanding_loan')}
          value={formatNPR(totals.total_outstanding_loan ?? 0, locale)}
          tone="warning"
        />
        <StatCard
          label={t('distribution.total_disbursed')}
          value={formatNPR(required, locale)}
          tone={underfunded ? 'danger' : 'positive'}
          hint={
            totals.shortfall_count
              ? `${formatNumber(totals.shortfall_count, locale)} ${t('distribution.shortfall')}`
              : undefined
          }
        />
      </div>

      <div className="mt-4">
        <Table
          title={t('distribution.ledger')}
          columns={columns}
          rows={items}
          rowKey="member_id"
          loading={loading}
          emptyMessage={t('table.no_data')}
          totals={{
            total_savings: totals.total_savings,
            total_interest: totals.total_interest,
            total_outstanding_loan: totals.total_outstanding_loan,
            final_payable: totals.final_payable,
          }}
          pageSize={50}
          printable
        />
      </div>

      <p className="mt-4 flex items-center gap-2 text-xs text-slate-500 no-print">
        <ShieldAlert className="h-4 w-4" aria-hidden="true" />
        {t('distribution.confirm_warning')}
      </p>
    </>
  );
}
