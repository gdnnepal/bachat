import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { Save } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card from '../../components/ui/Card.jsx';
import FormField from '../../components/forms/FormField.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import settingsService from '../../services/settingsService.js';
import { applyFieldErrors, useApi, useFetch } from '../../hooks/useApi.jsx';
import { useAuth } from '../../hooks/useAuth.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { hasAtMostTwoDecimals, parseAmount } from '../../utils/currency.js';

/**
 * Cooperative settings (Req 12.1, 14.3).
 *
 * Any admin can read these; only a Super Admin can write, so the form renders
 * read-only for everyone else rather than letting them submit into a 403.
 */
export default function Settings() {
  const { t } = useI18n();
  const toast = useToast();
  const { isSuperAdmin } = useAuth();

  const { data, loading, error, reload } = useFetch(() => settingsService.fetch(), []);

  const form = useForm({
    defaultValues: {
      cooperative_name: '',
      fixed_monthly_saving: '',
      interest_rate_annual: '',
      default_language: 'en',
    },
  });

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = form;

  useEffect(() => {
    const settings = data?.settings;
    if (!settings) return;

    reset({
      cooperative_name: settings.cooperative_name ?? '',
      fixed_monthly_saving: settings.fixed_monthly_saving ?? '',
      interest_rate_annual: settings.interest_rate_annual ?? '',
      default_language: settings.default_language ?? 'en',
    });
  }, [data, reset]);

  const { run, loading: saving, error: saveError } = useApi((payload) => settingsService.update(payload));

  const onSubmit = async (values) => {
    try {
      await run(values);
      toast.success(t('settings.saved'));
      reload();
    } catch (caught) {
      applyFieldErrors(form, caught);
    }
  };

  if (loading && !data) return <LoadingState />;

  return (
    <div className="mx-auto max-w-2xl">
      <PageHeader title={t('settings.title')} />

      {error && (
        <Alert variant="error" className="mb-4" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      )}

      {!isSuperAdmin && (
        <Alert variant="info" className="mb-4">
          {t('settings.super_admin_only')}
        </Alert>
      )}

      <Card>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          {saveError && !Object.keys(saveError.fields || {}).length && (
            <Alert variant="error">{saveError.message}</Alert>
          )}

          <FormField
            label={t('settings.cooperative_name')}
            required
            disabled={!isSuperAdmin}
            error={errors.cooperative_name}
            {...register('cooperative_name', {
              required: t('error.required'),
              minLength: { value: 2, message: t('error.min_length') },
              maxLength: { value: 150, message: t('error.max_length') },
            })}
          />

          <FormField
            label={t('settings.fixed_monthly_saving')}
            required
            inputMode="decimal"
            prefix={t('common.currency')}
            disabled={!isSuperAdmin}
            error={errors.fixed_monthly_saving}
            {...register('fixed_monthly_saving', {
              required: t('error.required'),
              validate: {
                numeric: (value) => Number.isFinite(parseAmount(value)) || t('error.invalid_number'),
                positive: (value) => parseAmount(value) > 0 || t('error.positive_amount'),
                precision: (value) => hasAtMostTwoDecimals(value) || t('error.two_decimals'),
              },
            })}
          />

          <FormField
            label={t('settings.interest_rate_annual')}
            required
            inputMode="decimal"
            suffix="%"
            disabled={!isSuperAdmin}
            error={errors.interest_rate_annual}
            {...register('interest_rate_annual', {
              required: t('error.required'),
              validate: {
                numeric: (value) => Number.isFinite(parseAmount(value)) || t('error.invalid_number'),
                range: (value) => {
                  const rate = parseAmount(value);
                  return (rate >= 0 && rate <= 100) || t('error.invalid_number');
                },
              },
            })}
          />

          <SelectField
            label={t('settings.default_language')}
            required
            disabled={!isSuperAdmin}
            error={errors.default_language}
            options={[
              { value: 'en', label: 'English' },
              { value: 'ne', label: 'नेपाली' },
            ]}
            {...register('default_language', { required: t('error.required') })}
          />

          {isSuperAdmin && (
            <div className="flex justify-end pt-2">
              <Button type="submit" icon={Save} loading={saving}>
                {t('button.save')}
              </Button>
            </div>
          )}
        </form>
      </Card>
    </div>
  );
}
