import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Banknote, Ban } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Badge from '../../components/ui/Badge.jsx';
import Button from '../../components/ui/Button.jsx';
import Card, { StatCard } from '../../components/ui/Card.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import Table from '../../components/ui/Table.jsx';
import { ConfirmModal } from '../../components/ui/Modal.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import { TextAreaField } from '../../components/forms/FormField.jsx';
import loanService from '../../services/loanService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { formatNPR, formatPercent } from '../../utils/currency.js';
import { bsMonthName, toNepaliNumeral } from '../../utils/bsDate.js';

/**
 * One loan with its repayment history (Req 7.8, 7.11).
 *
 * Repayment and cancellation are only offered while the loan is Outstanding —
 * the API rejects both otherwise, and hiding them keeps the screen honest about
 * what is still possible.
 */
export default function LoanDetail() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const { id } = useParams();

  const [cancelling, setCancelling] = useState(false);
  const [reason, setReason] = useState('');

  const { data, loading, error, reload } = useFetch(() => loanService.find(id), [id]);

  const { run: runCancel, loading: cancelSaving } = useApi((text) => loanService.cancel(id, text));

  const loan = data?.loan;
  const repayments = data?.repayments || [];

  const onCancel = async () => {
    try {
      await runCancel(reason);
      toast.success(t('loan.cancelled'));
      setCancelling(false);
      setReason('');
      reload();
    } catch (caught) {
      toast.error(caught.message);
      setCancelling(false);
    }
  };

  if (loading && !data) return <LoadingState />;

  if (error || !loan) {
    return (
      <>
        <PageHeader title={t('loan.title')} backTo="/loans" backLabel={t('loan.title')} />
        <Alert variant="error" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error?.message || t('error.not_found')}
        </Alert>
      </>
    );
  }

  const digits = (value) => (locale === 'ne' ? toNepaliNumeral(value) : String(value));

  const isOutstanding = loan.loan_status === 'Outstanding';
  const totalDue = Number(loan.outstanding_principal || 0) + Number(loan.accrued_interest || 0);

  const repaymentColumns = [
    {
      key: 'repayment_date_ad',
      label: t('repayment.date'),
      render: (_value, row) =>
        row.repayment_date_bs_year
          ? `${digits(row.repayment_date_bs_year)} ${bsMonthName(Number(row.repayment_date_bs_month), locale)}`
          : '—',
    },
    {
      key: 'repayment_type',
      label: t('repayment.type'),
      render: (value) => {
        const labels = {
          PrincipalOnly: t('repayment.principal_only'),
          InterestOnly: t('repayment.interest_only'),
          Both: t('repayment.both'),
        };
        return labels[value] || value;
      },
    },
    { key: 'principal_paid', label: t('loan.outstanding_principal'), type: 'money' },
    { key: 'interest_paid', label: t('loan.accrued_interest'), type: 'money' },
    { key: 'amount', label: t('repayment.amount'), type: 'money' },
    { key: 'remarks', label: t('loan.remarks') },
  ];

  return (
    <>
      <PageHeader
        title={`${t('loan.title')} #${digits(loan.id)}`}
        subtitle={`${loan.member_code} — ${loan.full_name}`}
        backTo="/loans"
        backLabel={t('loan.title')}
        actions={
          isOutstanding && (
            <>
              <Button as={Link} to={`/loans/${loan.id}/repay`} icon={Banknote}>
                {t('button.record_repayment')}
              </Button>
              <Button variant="danger" icon={Ban} onClick={() => setCancelling(true)}>
                {t('status.cancelled')}
              </Button>
            </>
          )
        }
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label={t('loan.amount')} value={formatNPR(loan.loan_amount, locale)} />
        <StatCard
          label={t('loan.outstanding_principal')}
          value={formatNPR(loan.outstanding_principal, locale)}
          tone="warning"
        />
        <StatCard label={t('loan.accrued_interest')} value={formatNPR(loan.accrued_interest, locale)} />
        <StatCard label={t('loan.total_due')} value={formatNPR(totalDue, locale)} tone="danger" />
      </div>

      <Card className="mt-4" title={t('loan.title')}>
        <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{t('loan.status')}</dt>
            <dd className="mt-1">
              <Badge status={loan.loan_status}>{t(`status.${String(loan.loan_status).toLowerCase()}`)}</Badge>
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{t('loan.interest_rate')}</dt>
            <dd className="mt-1 font-medium tabular-nums text-slate-900">
              {formatPercent(loan.interest_rate, locale)}
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{t('loan.date')}</dt>
            <dd className="mt-1 font-medium text-slate-900">
              {loan.loan_date_bs_year
                ? `${digits(loan.loan_date_bs_year)} ${bsMonthName(Number(loan.loan_date_bs_month), locale)}`
                : '—'}
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{t('loan.remarks')}</dt>
            <dd className="mt-1 text-slate-700">{loan.remarks || '—'}</dd>
          </div>
        </dl>
      </Card>

      <div className="mt-4">
        <Table
          title={t('repayment.history')}
          columns={repaymentColumns}
          rows={repayments}
          searchable={false}
          emptyMessage={t('table.no_data')}
          printable
        />
      </div>

      <ConfirmModal
        open={cancelling}
        onClose={() => setCancelling(false)}
        onConfirm={onCancel}
        loading={cancelSaving}
        variant="danger"
        title={t('status.cancelled')}
        message={t('loan.cancel_confirm')}
      >
        <TextAreaField
          label={t('loan.remarks')}
          rows={2}
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          containerClassName="mt-3"
        />
      </ConfirmModal>
    </>
  );
}
