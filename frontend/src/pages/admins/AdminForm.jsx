import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useParams } from 'react-router-dom';
import { Save } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card from '../../components/ui/Card.jsx';
import FormField from '../../components/forms/FormField.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import adminService from '../../services/adminService.js';
import { applyFieldErrors, useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';

/**
 * Create and edit admin accounts (Req 2.1–2.5).
 *
 * A password is only ever set at creation — the update endpoint does not accept
 * one, since admins change their own via /change-password. So the field is
 * dropped entirely when editing rather than shown and ignored.
 */
export default function AdminForm() {
  const { t } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const { id } = useParams();

  const isEdit = Boolean(id);

  const form = useForm({
    defaultValues: { name: '', username: '', password: '', phone: '', role: 'Admin', status: '1' },
  });

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = form;

  const { data: admin, loading: loadingAdmin, error: loadError } = useFetch(
    () => adminService.find(id),
    [id],
    { skip: !isEdit },
  );

  useEffect(() => {
    if (!admin) return;

    reset({
      name: admin.name || '',
      username: admin.username || '',
      password: '',
      phone: admin.phone || '',
      role: admin.role || 'Admin',
      status: String(admin.status ?? 1),
    });
  }, [admin, reset]);

  const { run, loading: saving, error } = useApi((payload) =>
    isEdit ? adminService.update(id, payload) : adminService.create(payload),
  );

  const onSubmit = async (values) => {
    const payload = {
      name: values.name,
      username: values.username,
      phone: values.phone || '',
      role: values.role,
      status: Number(values.status),
    };

    if (!isEdit) payload.password = values.password;

    try {
      await run(payload);
      toast.success(isEdit ? t('admin.updated') : t('admin.created'));
      navigate('/admin-management');
    } catch (caught) {
      applyFieldErrors(form, caught);
    }
  };

  if (isEdit && loadingAdmin) return <LoadingState />;

  if (isEdit && loadError) {
    return (
      <>
        <PageHeader title={t('admin.edit')} backTo="/admin-management" backLabel={t('admin.title')} />
        <Alert variant="error">{loadError.message}</Alert>
      </>
    );
  }

  return (
    <div className="mx-auto max-w-2xl">
      <PageHeader
        title={isEdit ? t('admin.edit') : t('admin.new')}
        backTo="/admin-management"
        backLabel={t('admin.title')}
      />

      <Card>
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
          {error && !Object.keys(error.fields || {}).length && (
            <Alert variant="error">{error.message}</Alert>
          )}

          <FormField
            label={t('admin.name')}
            required
            autoFocus={!isEdit}
            error={errors.name}
            {...register('name', {
              required: t('error.required'),
              maxLength: { value: 100, message: t('error.max_length') },
            })}
          />

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <FormField
              label={t('admin.username')}
              required
              autoComplete="off"
              error={errors.username}
              {...register('username', {
                required: t('error.required'),
                minLength: { value: 3, message: t('error.min_length') },
                maxLength: { value: 50, message: t('error.max_length') },
              })}
            />

            <FormField
              label={t('admin.phone')}
              inputMode="numeric"
              error={errors.phone}
              {...register('phone', {
                pattern: { value: /^\d{7,15}$/, message: t('error.invalid_phone') },
              })}
            />
          </div>

          {!isEdit && (
            <FormField
              label={t('admin.password')}
              type="password"
              required
              autoComplete="new-password"
              error={errors.password}
              {...register('password', {
                required: t('error.required'),
                minLength: { value: 6, message: t('error.min_length') },
                maxLength: { value: 255, message: t('error.max_length') },
              })}
            />
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <SelectField
              label={t('admin.role')}
              required
              error={errors.role}
              options={[
                { value: 'Admin', label: t('admin.role_admin') },
                { value: 'Super_Admin', label: t('admin.role_super') },
              ]}
              {...register('role', { required: t('error.required') })}
            />

            <SelectField
              label={t('admin.status')}
              error={errors.status}
              options={[
                { value: '1', label: t('status.active') },
                { value: '0', label: t('status.inactive') },
              ]}
              {...register('status')}
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => navigate('/admin-management')}>
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
