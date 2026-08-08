import { useForm } from 'react-hook-form';
import { useNavigate } from 'react-router-dom';
import { KeyRound } from 'lucide-react';

import Alert from '../components/ui/Alert.jsx';
import Button from '../components/ui/Button.jsx';
import Card from '../components/ui/Card.jsx';
import FormField from '../components/forms/FormField.jsx';
import PageHeader from '../components/layout/PageHeader.jsx';
import authService from '../services/authService.js';
import { applyFieldErrors, useApi } from '../hooks/useApi.jsx';
import { useI18n } from '../hooks/useI18n.jsx';
import { useToast } from '../hooks/useToast.jsx';

/**
 * Self-service password change for the signed-in admin (Req 1.7).
 *
 * The confirmation field is validated in the browser only — the API takes
 * current_password and new_password.
 */
export default function ChangePassword() {
  const { t } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();

  const form = useForm({
    defaultValues: { current_password: '', new_password: '', confirm_password: '' },
  });

  const {
    register,
    handleSubmit,
    watch,
    formState: { errors },
  } = form;

  const { run, loading, error } = useApi((payload) => authService.changePassword(payload));

  const newPassword = watch('new_password');

  const onSubmit = async (values) => {
    try {
      await run(values);
      toast.success(t('auth.password_changed'));
      navigate('/dashboard');
    } catch (caught) {
      applyFieldErrors(form, caught);
    }
  };

  return (
    <div className="mx-auto max-w-lg">
      <PageHeader title={t('button.change_password')} backTo="/dashboard" backLabel={t('nav.dashboard')} />

      <Card>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          {error && !Object.keys(error.fields || {}).length && (
            <Alert variant="error">{error.message}</Alert>
          )}

          <FormField
            label={t('auth.current_password')}
            type="password"
            required
            autoComplete="current-password"
            error={errors.current_password}
            {...register('current_password', { required: t('error.required') })}
          />

          <FormField
            label={t('auth.new_password')}
            type="password"
            required
            autoComplete="new-password"
            error={errors.new_password}
            {...register('new_password', {
              required: t('error.required'),
              minLength: { value: 6, message: t('error.min_length') },
              maxLength: { value: 255, message: t('error.max_length') },
            })}
          />

          <FormField
            label={t('auth.confirm_password')}
            type="password"
            required
            autoComplete="new-password"
            error={errors.confirm_password}
            {...register('confirm_password', {
              required: t('error.required'),
              validate: (value) => value === newPassword || t('error.validation'),
            })}
          />

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => navigate(-1)}>
              {t('button.cancel')}
            </Button>
            <Button type="submit" icon={KeyRound} loading={loading}>
              {t('button.save')}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
