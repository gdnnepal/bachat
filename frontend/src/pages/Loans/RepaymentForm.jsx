import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router-dom';
import { Banknote } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card, { StatCard } from '../../components/ui/Card.jsx';
import DatePickerBS from '../../components/forms/DatePickerBS.jsx';
import FormField, { TextAreaField } from '../../components/forms/FormField.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import loanService from '../../services/loanService.js';
import { applyFieldErrors, useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { bsToAd, currentBsDate } from '../../utils/bsDate.js';
import {
  formatNPR,
  formatPercent,
  hasAtMostTwoDecimals,
  parseAmount,
  roundHalfUp,
} from '../../utils/currency.js';

/**
 * Record a repayment against an outstanding loan (Req 7.4–7.7).
 *
 * Principal is the only figure the operator enters, and it is capped at the
 * outstanding balance so an overpayment is caught before submit (Req 7.8).
 * Interest is one month's charge on whatever principal remains after that
 * payment, recalculated live as the amount is typed and shown read-only.
 *
 * When the balance reaches zero the API marks the loan Completed and says so —
 * that notice is surfaced as a toast (Req 7.7).
 */

const TYPES = ['PrincipalOnly', 'InterestOnly', 'Both'];

export default function RepaymentForm() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const { id } = useParams();

  const [repayBs, setRepayBs] = useState(() => currentBsDate());

  const { data, loading: loadingLoan, error: loadError } = useFetch(() => loanService.find(id), [id]);

  const loan = data?.loan;

  const form = useForm({
    defaultValues: { repayment_type: 'Both', principal: '', remarks: '' },
  });

  const {
    register,
    handleSubmit,
    watch,
    setValue,
    formState: { errors },
  } = form;

  const type = watch('repayment_type');

  const outstandingPrincipal = Number(loan?.outstanding_principal || 0);
  const annualRate = Number(loan?.interest_rate || 0);

  const wantsPrincipal = type === 'PrincipalOnly' || type === 'Both';
  const wantsInterest = type === 'InterestOnly' || type === 'Both';

  // Clear principal when the selected type does not apply to it, so a value
  // typed under one type is never silently submitted under another.
  useEffect(() => {
    if (!wantsPrincipal) setValue('principal', '');
  }, [wantsPrincipal, setValue]);

  /**
   * One month's interest on the principal the member currently holds — the same
   * formula LoanService::monthlyInterestDue() applies server-side, mirrored here
   * only so the secretary sees the figure before saving. The server recomputes
   * it and ignores whatever the client sends.
   *
   * It is deliberately independent of the principal typed below: a 7,000
   * balance at 12% owes 70 whatever the member chooses to hand over today. Once
   * that payment drops the balance to 5,000, the next repayment shows 50.
   */
  const interestDue = useMemo(() => {
    if (!wantsInterest || annualRate <= 0 || outstandingPrincipal <= 0) return 0;

    return roundHalfUp((outstandingPrincipal * annualRate) / 12 / 100, 2);
  }, [wantsInterest, outstandingPrincipal, annualRate]);

  const { run, loading: saving, error } = useApi((payload) => loanService.recordRepayment(id, payload));

  const onSubmit = async (values) => {
    const payload = {
      repayment_type: values.repayment_type,
      remarks: values.remarks || '',
      repayment_date_bs_year: repayBs.year,
      repayment_date_bs_month: repayBs.month,
      repayment_date_bs_day: repayBs.day,
    };

    // Interest is derived server-side; it is sent only so the request records
    // what the operator was shown at the time.
    if (wantsPrincipal) payload.principal = parseAmount(values.principal);
    if (wantsInterest) payload.interest = interestDue;

    try {
      const result = await run(payload);
      toast.success(result?.loan_completed ? t('repayment.loan_completed') : t('repayment.recorded'));
      navigate(`/loans/${id}`);
    } catch (caught) {
      applyFieldErrors(form, caught);
    }
  };

  if (loadingLoan) return <LoadingState />;

  if (loadError || !loan) {
    return (
      <>
        <PageHeader title={t('repayment.title')} backTo="/loans" backLabel={t('loan.title')} />
        <Alert variant="error">{loadError?.message || t('error.not_found')}</Alert>
      </>
    );
  }

  if (loan.loan_status !== 'Outstanding') {
    return (
      <>
        <PageHeader title={t('repayment.title')} backTo={`/loans/${id}`} backLabel={t('loan.title')} />
        <Alert variant="warning">{t('error.conflict')}</Alert>
      </>
    );
  }

  /** Shared amount rules; `cap` is whatever that component of the loan still owes. */
  const amountRules = (cap) => ({
    required: t('error.required'),
    validate: {
      numeric: (value) => Number.isFinite(parseAmount(value)) || t('error.invalid_number'),
      positive: (value) => parseAmount(value) > 0 || t('error.positive_amount'),
      precision: (value) => hasAtMostTwoDecimals(value) || t('error.two_decimals'),
      withinDue: (value) => parseAmount(value) <= cap + 0.005 || t('repayment.overpayment'),
    },
  });

  return (
    <div className="mx-auto max-w-2xl">
      <PageHeader
        title={t('repayment.title')}
        subtitle={`${loan.member_code} — ${loan.full_name}`}
        backTo={`/loans/${id}`}
        backLabel={t('loan.title')}
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard
          label={t('loan.outstanding_principal')}
          value={formatNPR(outstandingPrincipal, locale)}
          tone="warning"
        />
        <StatCard
          label={t('loan.accrued_interest')}
          value={formatNPR(interestDue, locale)}
          hint={formatPercent(annualRate, locale)}
        />
        <StatCard
          label={t('loan.total_due')}
          value={formatNPR(outstandingPrincipal + interestDue, locale)}
          tone="danger"
        />
      </div>

      <Card className="mt-4">
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          {error && !Object.keys(error.fields || {}).length && (
            <Alert variant="error">{error.message}</Alert>
          )}

          <SelectField
            label={t('repayment.type')}
            required
            error={errors.repayment_type}
            options={TYPES.map((value) => ({
              value,
              label: t(
                value === 'PrincipalOnly'
                  ? 'repayment.principal_only'
                  : value === 'InterestOnly'
                    ? 'repayment.interest_only'
                    : 'repayment.both',
              ),
            }))}
            {...register('repayment_type', { required: t('error.required') })}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {wantsPrincipal && (
              <FormField
                label={t('loan.outstanding_principal')}
                required
                inputMode="decimal"
                prefix={t('common.currency')}
                hint={formatNPR(outstandingPrincipal, locale)}
                error={errors.principal}
                {...register('principal', amountRules(outstandingPrincipal))}
              />
            )}

            {/* Read-only: one month's interest on the outstanding principal,
                recomputed server-side before anything is written. */}
            {wantsInterest && (
              <FormField
                label={t('loan.accrued_interest')}
                readOnly
                disabled
                prefix={t('common.currency')}
                value={formatNPR(interestDue, locale, { symbol: false })}
                hint={`${formatPercent(annualRate, locale)} — ${formatNPR(outstandingPrincipal, locale)}`}
              />
            )}
          </div>

          <DatePickerBS
            label={t('repayment.date')}
            name="repayment_date"
            required
            value={bsToAd(repayBs.year, repayBs.month, repayBs.day)}
            onChange={(_ad, bs) => setRepayBs(bs)}
          />

          <TextAreaField
            label={t('loan.remarks')}
            rows={2}
            error={errors.remarks}
            {...register('remarks', { maxLength: { value: 255, message: t('error.max_length') } })}
          />

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => navigate(`/loans/${id}`)}>
              {t('button.cancel')}
            </Button>
            <Button type="submit" icon={Banknote} loading={saving}>
              {t('button.record_repayment')}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
