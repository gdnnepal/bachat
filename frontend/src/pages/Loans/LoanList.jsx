import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { HandCoins } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Badge from '../../components/ui/Badge.jsx';
import Button from '../../components/ui/Button.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import Table from '../../components/ui/Table.jsx';
import loanService from '../../services/loanService.js';
import { useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { formatNPR, formatPercent } from '../../utils/currency.js';
import { bsMonthName, toNepaliNumeral } from '../../utils/bsDate.js';

/**
 * Loan register (Req 7.10).
 *
 * Status is filtered server-side because the backend indexes on it; search and
 * paging stay client-side like the member roster.
 */

const STATUSES = ['Outstanding', 'Completed', 'Cancelled'];

export default function LoanList() {
  const { t, locale } = useI18n();
  const navigate = useNavigate();

  const [status, setStatus] = useState('');

  const { data, loading, error, reload } = useFetch(
    () => loanService.list(status ? { status } : {}),
    [status],
  );

  const rows = useMemo(() => data?.rows || [], [data]);

  const digits = (value) => (locale === 'ne' ? toNepaliNumeral(value) : String(value));

  const columns = [
    { key: 'member_code', label: t('member.member_id') },
    { key: 'full_name', label: t('loan.member') },
    {
      key: 'loan_date_ad',
      label: t('loan.date'),
      render: (_value, row) =>
        row.loan_date_bs_year
          ? `${digits(row.loan_date_bs_year)} ${bsMonthName(Number(row.loan_date_bs_month), locale)}`
          : '—',
    },
    { key: 'loan_amount', label: t('loan.amount'), type: 'money' },
    {
      key: 'interest_rate',
      label: t('loan.interest_rate'),
      align: 'right',
      render: (value) => formatPercent(value, locale),
    },
    { key: 'outstanding_principal', label: t('loan.outstanding_principal'), type: 'money' },
    { key: 'accrued_interest', label: t('loan.accrued_interest'), type: 'money' },
    {
      key: 'total_due',
      label: t('loan.total_due'),
      type: 'money',
      render: (_value, row) =>
        formatNPR(Number(row.outstanding_principal || 0) + Number(row.accrued_interest || 0), locale),
    },
    {
      key: 'loan_status',
      label: t('loan.status'),
      align: 'center',
      render: (value) => <Badge status={value}>{t(`status.${String(value).toLowerCase()}`)}</Badge>,
    },
  ];

  return (
    <>
      <PageHeader
        title={t('loan.title')}
        actions={
          <Button as={Link} to="/loans/new" icon={HandCoins}>
            {t('button.new_loan')}
          </Button>
        }
      />

      {error && (
        <Alert variant="error" className="mb-4" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      )}

      <Table
        columns={columns}
        rows={rows}
        loading={loading}
        emptyMessage={t('loan.none_found')}
        onRowClick={(row) => navigate(`/loans/${row.id}`)}
        printable
        toolbar={
          <SelectField
            name="loan_status"
            includeAll
            value={status}
            onChange={(event) => setStatus(event.target.value)}
            aria-label={t('loan.status')}
            className="w-40"
            options={STATUSES.map((value) => ({ value, label: t(`status.${value.toLowerCase()}`) }))}
          />
        }
      />
    </>
  );
}
