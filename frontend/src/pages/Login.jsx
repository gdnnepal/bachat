import { useForm } from 'react-hook-form';
import { Navigate, useLocation, useNavigate } from 'react-router-dom';
import { LogIn } from 'lucide-react';

import Alert from '../components/ui/Alert.jsx';
import Button from '../components/ui/Button.jsx';
import FormField from '../components/forms/FormField.jsx';
import { LoadingState } from '../components/ui/Spinner.jsx';
import { applyFieldErrors, useApi } from '../hooks/useApi.jsx';
import { useAuth } from '../hooks/useAuth.jsx';
import { useI18n } from '../hooks/useI18n.jsx';

/**
 * Sign-in screen (Req 1.1–1.5).
 *
 * On success the user is sent to whatever page bounced them here — PrivateRoute
 * stashes it in location.state.from — or to the dashboard.
 */
export default function Login() {
  const { t } = useI18n();
  const { login, isAuthenticated, checking } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();

  const form = useForm({
    defaultValues: { username: '', password: '', remember: false },
  });

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = form;

  const { run, loading, error } = useApi((credentials) => login(credentials));

  // Already signed in (e.g. the user typed /login by hand) — skip the form.
  if (checking) return <LoadingState />;
  if (isAuthenticated) return <Navigate to="/dashboard" replace />;

  const onSubmit = async (values) => {
    try {
      await run(values);
      navigate(location.state?.from?.pathname || '/dashboard', { replace: true });
    } catch (caught) {
      // Field-level messages land on the inputs; anything else shows in the Alert.
      applyFieldErrors(form, caught);
    }
  };

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate>
      <h2 className="mb-4 text-base font-semibold text-slate-900">{t('auth.login_title')}</h2>

      {error && !Object.keys(error.fields || {}).length && (
        <Alert variant="error" className="mb-4">
          {error.message}
        </Alert>
      )}

      <div className="space-y-4">
        <FormField
          label={t('auth.username')}
          required
          autoComplete="username"
          autoFocus
          error={errors.username}
          {...register('username', { required: t('error.required') })}
        />

        <FormField
          label={t('auth.password')}
          type="password"
          required
          autoComplete="current-password"
          error={errors.password}
          {...register('password', { required: t('error.required') })}
        />

        <label className="flex items-center gap-2 text-sm text-slate-700">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            {...register('remember')}
          />
          {t('auth.remember_me')}
        </label>
      </div>

      <Button type="submit" icon={LogIn} loading={loading} className="mt-6 w-full" size="lg">
        {loading ? t('auth.logging_in') : t('auth.login')}
      </Button>
    </form>
  );
}
