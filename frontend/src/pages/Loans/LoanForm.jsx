import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { HandCoins } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card, { StatCard } from '../../components/ui/Card.jsx';
import DatePickerBS from '../../components/forms/DatePickerBS.jsx';
import FormField, { TextAreaField } from '../../components/forms/FormField.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import cashBankService from '../../services/cashBankService.js';
import loanService from '../../services/loanService.js';
import memberService from '../../services/memberService.js';
import settingsService from '../../services/settingsService.js';
import { applyFieldErrors, useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { bsToAd, currentBsDate } from '../../utils/bsDate.js';
import { formatNPR, formatPercent, hasAtMostTwoDecimals, parseAmount } from '../../utils/currency.js';

/**
 * Disburse a loan to a member (Req 7.1–7.3).
 *
 * Cash in hand is shown alongside the amount field and checked before submit —
 * the backend enforces the same rule, but catching it here avoids a round trip
 * and tells the secretary the shortfall while they are still typing.
 */
export default function LoanForm() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();

  const [loanBs, setLoanBs] = useState(() => currentBsDate());

  const { data: memberPage, loading: loadingMembers } = useFetch(
    () => memberService.list({ per_page: 500, status: 1 }),
    [],
  );

  const { data: balances } = useFetch(() => cashBankService.balances(), []);
  const { data: settingsPayload, loading: loadingSettings } = useFetch(() => settingsService.fetch(), []);

  // The rate is set by the Super Admin in Settings and applies to every loan
  // (Req 7.1 — "Interest Rate, fixed by the cooperative"). It is displayed
  // read-only and never registered with the form, so it cannot be edited here
  // and is taken straight from settings when the payload is built.
  const cooperativeRate = parseAmount(settingsPayload?.settings?.interest_rate_annual);
  const rateAvailable = Number.isFinite(cooperativeRate);

  const form = useForm({
    defaultValues: {
      member_id: searchParams.get('member_id') || '',
      loan_amount: '',
      remarks: '',
    },
  });

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = form;

  const { run, loading: saving, error } = useApi((payload) => loanService.disburse(payload));

  const cashInHand = Number(balances?.cash_in_hand || 0);
  const amountEntered = parseAmount(watch('loan_amount'));

  const memberOptions = useMemo(
    () =>
      (memberPage?.rows || [])
        .filter((member) => Number(member.status) === 1)
        .map((member) => ({ value: String(member.id), label: `${member.member_id} — ${member.full_name}` })),
    [memberPage],
  );

  const onSubmit = async (values) => {
    const payload = {
      member_id: Number(values.member_id),
      loan_amount: parseAmount(values.loan_amount),
      interest_rate: cooperativeRate,
      remarks: values.remarks || '',
      loan_date_bs_year: loanBs.year,
      loan_date_bs_month: loanBs.month,
      loan_date_bs_day: loanBs.day,
    };

    try {
      const loan = await run(payload);
      toast.success(t('loan.disbursed'));
      navigate(loan?.id ? `/loans/${loan.id}` : '/loans');
    } catch (caught) {
      applyFieldErrors(form, caught);
    }
  };

  if (loadingMembers || loadingSettings) return <LoadingState />;

  const exceedsCash = Number.isFinite(amountEntered) && amountEntered > cashInHand;

  return (
    <div className="mx-auto max-w-2xl">
      <PageHeader title={t('loan.new')} backTo="/loans" backLabel={t('loan.title')} />

      <StatCard
        label={t('cashbank.cash_in_hand')}
        value={formatNPR(cashInHand, locale)}
        tone={exceedsCash ? 'danger' : 'positive'}
      />

      <Card className="mt-4">
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          {error && !Object.keys(error.fields || {}).length && (
            <Alert variant="error">{error.message}</Alert>
          )}

          {exceedsCash && <Alert variant="warning">{t('loan.insufficient_cash')}</Alert>}

          {/* Without a configured rate there is nothing to disburse against —
              the API would reject the payload, so block it here instead. */}
          {!rateAvailable && (
            <Alert variant="error">
              {`${t('settings.interest_rate_annual')} — ${t('error.required')}`}
            </Alert>
          )}

          <SelectField
            label={t('loan.member')}
            required
            placeholder={t('common.select')}
            options={memberOptions}
            error={errors.member_id}
            {...register('member_id', { required: t('error.required') })}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
              label={t('loan.amount')}
              required
              inputMode="decimal"
              prefix={t('common.currency')}
              error={errors.loan_amount}
              {...register('loan_amount', {
                required: t('error.required'),
                validate: {
                  numeric: (value) => Number.isFinite(parseAmount(value)) || t('error.invalid_number'),
                  positive: (value) => parseAmount(value) > 0 || t('error.positive_amount'),
                  precision: (value) => hasAtMostTwoDecimals(value) || t('error.two_decimals'),
                },
              })}
            />

            {/* Read-only: the cooperative's single rate, from Settings.
                formatPercent already appends "%", so no suffix prop here. */}
            <FormField
              label={t('loan.interest_rate')}
              readOnly
              disabled
              value={rateAvailable ? formatPercent(cooperativeRate, locale) : '—'}
              hint={t('settings.interest_rate_annual')}
            />
          </div>

          <DatePickerBS
            label={t('loan.date')}
            name="loan_date"
            required
            value={bsToAd(loanBs.year, loanBs.month, loanBs.day)}
            onChange={(_ad, bs) => setLoanBs(bs)}
          />

          <TextAreaField
            label={t('loan.remarks')}
            rows={3}
            error={errors.remarks}
            {...register('remarks', { maxLength: { value: 1000, message: t('error.max_length') } })}
          />

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => navigate('/loans')}>
              {t('button.cancel')}
            </Button>
            <Button type="submit" icon={HandCoins} loading={saving} disabled={!rateAvailable}>
              {t('button.new_loan')}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
