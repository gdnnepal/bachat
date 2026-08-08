import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router-dom';
import { Save } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card from '../../components/ui/Card.jsx';
import DatePickerBS from '../../components/forms/DatePickerBS.jsx';
import FormField, { TextAreaField } from '../../components/forms/FormField.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import memberService from '../../services/memberService.js';
import { applyFieldErrors, useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { bsToAd, currentBsDate } from '../../utils/bsDate.js';

/**
 * Create and edit a member (Req 3.1, 3.5, 3.6).
 *
 * Member_ID is server-generated and immutable, so it is shown read-only when
 * editing and not sent in either payload.
 *
 * The join date is captured in Bikram Sambat — the API takes the three BS parts
 * and derives the AD date itself — so the BS triple lives in component state
 * while the rest of the form is managed by React Hook Form.
 */
export default function MemberForm() {
  const { t } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const { id } = useParams();

  const isEdit = Boolean(id);

  const [joinBs, setJoinBs] = useState(() => currentBsDate());

  const form = useForm({
    defaultValues: { full_name: '', phone: '', address: '', notes: '', status: '1' },
  });

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = form;

  const { data: member, loading: loadingMember, error: loadError } = useFetch(
    () => memberService.find(id),
    [id],
    { skip: !isEdit },
  );

  // Populate the form once the record arrives.
  useEffect(() => {
    if (!member) return;

    reset({
      full_name: member.full_name || '',
      phone: member.phone || '',
      address: member.address || '',
      notes: member.notes || '',
      status: String(member.status ?? 1),
    });

    if (member.join_date_bs_year) {
      setJoinBs({
        year: Number(member.join_date_bs_year),
        month: Number(member.join_date_bs_month),
        day: Number(member.join_date_bs_day) || 1,
      });
    }
  }, [member, reset]);

  const { run, loading: saving, error } = useApi((payload) =>
    isEdit ? memberService.update(id, payload) : memberService.create(payload),
  );

  const onSubmit = async (values) => {
    const payload = {
      ...values,
      status: Number(values.status),
      join_date_bs_year: joinBs.year,
      join_date_bs_month: joinBs.month,
      join_date_bs_day: joinBs.day,
    };

    try {
      await run(payload);
      toast.success(isEdit ? t('member.updated') : t('member.created'));
      navigate('/members');
    } catch (caught) {
      applyFieldErrors(form, caught);
    }
  };

  if (isEdit && loadingMember) return <LoadingState />;

  if (isEdit && loadError) {
    return (
      <>
        <PageHeader title={t('member.edit')} backTo="/members" backLabel={t('member.title')} />
        <Alert variant="error">{loadError.message}</Alert>
      </>
    );
  }

  return (
    <div className="mx-auto max-w-2xl">
      <PageHeader
        title={isEdit ? t('member.edit') : t('member.new')}
        subtitle={isEdit ? member?.member_id : undefined}
        backTo="/members"
        backLabel={t('member.title')}
      />

      <Card>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          {error && !Object.keys(error.fields || {}).length && (
            <Alert variant="error">{error.message}</Alert>
          )}

          {isEdit && (
            <FormField
              label={t('member.member_id')}
              value={member?.member_id || ''}
              readOnly
              disabled
              hint={t('common.required')}
            />
          )}

          <FormField
            label={t('member.full_name')}
            required
            autoFocus={!isEdit}
            error={errors.full_name}
            {...register('full_name', {
              required: t('error.required'),
              maxLength: { value: 100, message: t('error.max_length') },
            })}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
              label={t('member.phone')}
              required
              inputMode="numeric"
              error={errors.phone}
              {...register('phone', {
                required: t('error.required'),
                pattern: { value: /^\d{7,15}$/, message: t('error.invalid_phone') },
              })}
            />

            <SelectField
              label={t('member.status')}
              error={errors.status}
              options={[
                { value: '1', label: t('status.active') },
                { value: '0', label: t('status.inactive') },
              ]}
              {...register('status')}
            />
          </div>

          <FormField
            label={t('member.address')}
            error={errors.address}
            {...register('address', { maxLength: { value: 255, message: t('error.max_length') } })}
          />

          <DatePickerBS
            label={t('member.join_date')}
            name="join_date"
            required
            value={bsToAd(joinBs.year, joinBs.month, joinBs.day)}
            onChange={(_ad, bs) => setJoinBs(bs)}
          />

          <TextAreaField
            label={t('member.notes')}
            rows={3}
            error={errors.notes}
            {...register('notes', { maxLength: { value: 500, message: t('error.max_length') } })}
          />

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => navigate('/members')}>
              {t('button.cancel')}
            </Button>
            <Button type="submit" icon={Save} loading={saving}>
              {isEdit ? t('button.update') : t('button.create')}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
