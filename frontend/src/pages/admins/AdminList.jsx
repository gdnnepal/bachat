import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Pencil, UserPlus } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Badge from '../../components/ui/Badge.jsx';
import Button from '../../components/ui/Button.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import Table from '../../components/ui/Table.jsx';
import { ConfirmModal } from '../../components/ui/Modal.jsx';
import adminService from '../../services/adminService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useAuth } from '../../hooks/useAuth.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';

/**
 * Admin accounts — Super Admin only (Req 2.1–2.6).
 *
 * Accounts are deactivated rather than deleted so their audit history keeps a
 * resolvable author. The backend refuses to deactivate the last active Super
 * Admin; the signed-in admin's own row also hides the toggle so nobody locks
 * themselves out mid-session.
 */
export default function AdminList() {
  const { t } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();
  const { admin: currentAdmin } = useAuth();

  const [pendingToggle, setPendingToggle] = useState(null);

  const { data, loading, error, reload } = useFetch(() => adminService.list(), []);

  const { run: runSetStatus, loading: toggling } = useApi(({ id, status }) =>
    adminService.setStatus(id, status),
  );

  const rows = Array.isArray(data) ? data : [];

  const onConfirmToggle = async () => {
    const nextStatus = Number(pendingToggle.status) === 1 ? 0 : 1;

    try {
      await runSetStatus({ id: pendingToggle.id, status: nextStatus });
      toast.success(t('admin.updated'));
      setPendingToggle(null);
      reload();
    } catch (caught) {
      toast.error(caught.message);
      setPendingToggle(null);
    }
  };

  const columns = [
    { key: 'name', label: t('admin.name') },
    { key: 'username', label: t('admin.username') },
    { key: 'phone', label: t('admin.phone') },
    {
      key: 'role',
      label: t('admin.role'),
      render: (value) => (
        <Badge tone={value === 'Super_Admin' ? 'info' : 'neutral'}>
          {value === 'Super_Admin' ? t('admin.role_super') : t('admin.role_admin')}
        </Badge>
      ),
    },
    {
      key: 'status',
      label: t('admin.status'),
      align: 'center',
      render: (value) => (
        <Badge tone={Number(value) === 1 ? 'success' : 'neutral'}>
          {Number(value) === 1 ? t('status.active') : t('status.inactive')}
        </Badge>
      ),
    },
    {
      key: 'actions',
      label: t('table.actions'),
      align: 'right',
      sortable: false,
      className: 'no-print',
      render: (_value, row) => (
        <div className="flex justify-end gap-1">
          <Button
            as={Link}
            to={`/admin-management/${row.id}`}
            variant="ghost"
            size="sm"
            icon={Pencil}
            title={t('button.edit')}
            onClick={(event) => event.stopPropagation()}
          />

          {row.id !== currentAdmin?.id && (
            <Button
              variant="ghost"
              size="sm"
              onClick={(event) => {
                event.stopPropagation();
                setPendingToggle(row);
              }}
            >
              {Number(row.status) === 1 ? t('status.inactive') : t('status.active')}
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t('admin.title')}
        actions={
          <Button as={Link} to="/admin-management/new" icon={UserPlus}>
            {t('admin.new')}
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
        emptyMessage={t('table.no_data')}
        onRowClick={(row) => navigate(`/admin-management/${row.id}`)}
      />

      <ConfirmModal
        open={Boolean(pendingToggle)}
        onClose={() => setPendingToggle(null)}
        onConfirm={onConfirmToggle}
        loading={toggling}
        variant={pendingToggle && Number(pendingToggle.status) === 1 ? 'danger' : 'primary'}
        title={t('admin.status')}
      >
        {pendingToggle && (
          <p className="text-sm text-slate-700">
            {pendingToggle.name} ({pendingToggle.username}) —{' '}
            {Number(pendingToggle.status) === 1 ? t('status.inactive') : t('status.active')}
          </p>
        )}
      </ConfirmModal>
    </>
  );
}
