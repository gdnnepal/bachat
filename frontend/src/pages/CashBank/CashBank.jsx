import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { ArrowLeftRight, Landmark, Wallet } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import { StatCard } from '../../components/ui/Card.jsx';
import FormField, { TextAreaField } from '../../components/forms/FormField.jsx';
import Modal from '../../components/ui/Modal.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import Table from '../../components/ui/Table.jsx';
import cashBankService from '../../services/cashBankService.js';
import { applyFieldErrors, useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { formatNPR, hasAtMostTwoDecimals, parseAmount } from '../../utils/currency.js';
import { bsMonthName, toNepaliNumeral } from '../../utils/bsDate.js';

/**
 * Cash box and bank account (Req 8.1–8.6).
 *
 * One ledger component serves both books — the `view` toggle asks the API for
 * the matching transaction types and it returns a running balance for that
 * view, so the same table is correct for either.
 */

const VIEWS = ['cash', 'bank', 'all'];

export default function CashBank() {
  const { t, locale } = useI18n();
  const toast = useToast();

  const [view, setView] = useState('cash');
  const [transferOpen, setTransferOpen] = useState(false);

  const { data, loading, error, reload } = useFetch(() => cashBankService.transactions(view), [view]);

  const form = useForm({
    defaultValues: { direction: 'CashToBank', amount: '', description: '' },
  });

  const {
    register,
    handleSubmit,
    watch,
    reset,
    formState: { errors },
  } = form;

  const { run, loading: transferring, error: transferError } = useApi((payload) =>
    cashBankService.transfer(payload),
  );

  const balances = data?.balances || {};
  const rows = data?.rows || [];

  const direction = watch('direction');
  const sourceBalance =
    direction === 'CashToBank' ? Number(balances.cash_in_hand || 0) : Number(balances.bank_balance || 0);

  const onTransfer = async (values) => {
    try {
      await run({
        direction: values.direction,
        amount: parseAmount(values.amount),
        description: values.description || '',
      });

      toast.success(t('cashbank.transferred'));
      setTransferOpen(false);
      reset();
      reload();
    } catch (caught) {
      applyFieldErrors(form, caught);
    }
  };

  const digits = (value) => (locale === 'ne' ? toNepaliNumeral(value) : String(value));

  // Which signed delta the running balance follows depends on the book shown.
  const deltaKey = view === 'bank' ? 'bank_delta' : 'cash_delta';

  const columns = [
    {
      key: 'transaction_date_ad',
      label: t('common.date'),
      render: (_value, row) =>
        row.transaction_date_bs_year
          ? `${digits(row.transaction_date_bs_year)} ${bsMonthName(Number(row.transaction_date_bs_month), locale)}`
          : '—',
    },
    { key: 'transaction_type', label: t('common.type') },
    { key: 'description', label: t('cashbank.description') },
    {
      key: 'debit',
      label: '+',
      type: 'money',
      sortable: false,
      render: (_value, row) => {
        const delta = Number(row[deltaKey] || 0);
        return delta > 0 ? formatNPR(delta, locale, { symbol: false }) : '—';
      },
    },
    {
      key: 'credit',
      label: '−',
      type: 'money',
      sortable: false,
      render: (_value, row) => {
        const delta = Number(row[deltaKey] || 0);
        return delta < 0 ? formatNPR(Math.abs(delta), locale, { symbol: false }) : '—';
      },
    },
    ...(view === 'all'
      ? []
      : [{ key: 'running_balance', label: t('common.balance'), type: 'money' }]),
  ];

  return (
    <>
      <PageHeader
        title={t('cashbank.title')}
        actions={
          <Button icon={ArrowLeftRight} onClick={() => setTransferOpen(true)}>
            {t('cashbank.transfer')}
          </Button>
        }
      />

      {error && (
        <Alert variant="error" className="mb-4" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard
          label={t('cashbank.cash_in_hand')}
          value={formatNPR(balances.cash_in_hand ?? 0, locale)}
          icon={Wallet}
          tone="positive"
        />
        <StatCard
          label={t('cashbank.bank_balance')}
          value={formatNPR(balances.bank_balance ?? 0, locale)}
          icon={Landmark}
          tone="positive"
        />
        <StatCard label={t('cashbank.total')} value={formatNPR(balances.total ?? 0, locale)} />
      </div>

      <div className="mt-4">
        <Table
          title={view === 'bank' ? t('cashbank.bank_book') : t('cashbank.cash_book')}
          columns={columns}
          rows={rows}
          loading={loading}
          emptyMessage={t('table.no_data')}
          pageSize={25}
          printable
          toolbar={
            <SelectField
              name="view"
              value={view}
              onChange={(event) => setView(event.target.value)}
              aria-label={t('common.type')}
              className="w-36"
              options={VIEWS.map((value) => ({
                value,
                label:
                  value === 'cash'
                    ? t('cashbank.cash_book')
                    : value === 'bank'
                      ? t('cashbank.bank_book')
                      : t('common.all'),
              }))}
            />
          }
        />
      </div>

      <Modal
        open={transferOpen}
        onClose={() => setTransferOpen(false)}
        title={t('cashbank.transfer')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setTransferOpen(false)} disabled={transferring}>
              {t('button.cancel')}
            </Button>
            <Button onClick={handleSubmit(onTransfer)} loading={transferring} icon={ArrowLeftRight}>
              {t('cashbank.transfer')}
            </Button>
          </>
        }
      >
        <form onSubmit={handleSubmit(onTransfer)} noValidate className="space-y-4">
          {transferError && !Object.keys(transferError.fields || {}).length && (
            <Alert variant="error">{transferError.message}</Alert>
          )}

          <SelectField
            label={t('cashbank.direction')}
            required
            error={errors.direction}
            options={[
              { value: 'CashToBank', label: t('cashbank.cash_to_bank') },
              { value: 'BankToCash', label: t('cashbank.bank_to_cash') },
            ]}
            {...register('direction', { required: t('error.required') })}
          />

          <FormField
            label={t('cashbank.amount')}
            required
            inputMode="decimal"
            prefix={t('common.currency')}
            hint={`${t('common.balance')}: ${formatNPR(sourceBalance, locale)}`}
            error={errors.amount}
            {...register('amount', {
              required: t('error.required'),
              validate: {
                numeric: (value) => Number.isFinite(parseAmount(value)) || t('error.invalid_number'),
                positive: (value) => parseAmount(value) > 0 || t('error.positive_amount'),
                precision: (value) => hasAtMostTwoDecimals(value) || t('error.two_decimals'),
                sufficient: (value) => parseAmount(value) <= sourceBalance || t('cashbank.insufficient'),
              },
            })}
          />

          <TextAreaField
            label={t('cashbank.description')}
            rows={2}
            error={errors.description}
            {...register('description', { maxLength: { value: 255, message: t('error.max_length') } })}
          />
        </form>
      </Modal>
    </>
  );
}
