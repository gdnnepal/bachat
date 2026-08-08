import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { FileText, Pencil, Trash2, UserPlus } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Badge from '../../components/ui/Badge.jsx';
import Button from '../../components/ui/Button.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import Table from '../../components/ui/Table.jsx';
import { ConfirmModal } from '../../components/ui/Modal.jsx';
import memberService from '../../services/memberService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { formatBsDate } from '../../utils/bsDate.js';

/**
 * Member roster (Req 3.4, 3.7, 3.8).
 *
 * The cooperative tops out in the low hundreds of members, so the full roster
 * is fetched once and Table handles search, sort and paging in the browser —
 * that keeps search instant (Req 3.4) with no round trip per keystroke.
 */

const PER_PAGE = 500;

export default function MemberList() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();

  const [pendingDelete, setPendingDelete] = useState(null);

  const { data, loading, error, reload } = useFetch(() => memberService.list({ per_page: PER_PAGE }), []);

  const { run: runDelete, loading: deleting } = useApi((id) => memberService.remove(id));

  const rows = data?.rows || [];

  const onConfirmDelete = async () => {
    try {
      await runDelete(pendingDelete.id);
      toast.success(t('member.deleted'));
      setPendingDelete(null);
      reload();
    } catch (caught) {
      // A member with financial history comes back as 409 (Req 3.9).
      toast.error(caught.message);
      setPendingDelete(null);
    }
  };

  const columns = [
    { key: 'member_id', label: t('member.member_id') },
    { key: 'full_name', label: t('member.full_name') },
    { key: 'phone', label: t('member.phone') },
    { key: 'address', label: t('member.address') },
    {
      key: 'join_date_bs_year',
      label: t('member.join_date'),
      sortable: true,
      render: (_value, row) =>
        row.join_date_bs_year
          ? formatBsDate(row.join_date_bs_year, row.join_date_bs_month, row.join_date_bs_day, locale)
          : '—',
    },
    {
      key: 'status',
      label: t('member.status'),
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
            to={`/members/${row.id}/statement`}
            variant="ghost"
            size="sm"
            icon={FileText}
            title={t('member.statement')}
            onClick={(event) => event.stopPropagation()}
          />
          <Button
            as={Link}
            to={`/members/${row.id}`}
            variant="ghost"
            size="sm"
            icon={Pencil}
            title={t('button.edit')}
            onClick={(event) => event.stopPropagation()}
          />
          <Button
            variant="ghost"
            size="sm"
            icon={Trash2}
            title={t('button.delete')}
            className="text-red-600 hover:bg-red-50"
            onClick={(event) => {
              event.stopPropagation();
              setPendingDelete(row);
            }}
          />
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t('member.title')}
        actions={
          <Button as={Link} to="/members/new" icon={UserPlus}>
            {t('button.add_member')}
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
        searchPlaceholder={t('member.search_placeholder')}
        emptyMessage={t('member.none_found')}
        onRowClick={(row) => navigate(`/members/${row.id}/statement`)}
        printable
      />

      <ConfirmModal
        open={Boolean(pendingDelete)}
        onClose={() => setPendingDelete(null)}
        onConfirm={onConfirmDelete}
        loading={deleting}
        variant="danger"
        title={t('button.delete')}
        message={t('member.delete_confirm')}
        confirmLabel={t('button.delete')}
      >
        {pendingDelete && (
          <p className="mt-2 text-sm font-medium text-slate-900">
            {pendingDelete.member_id} — {pendingDelete.full_name}
          </p>
        )}
      </ConfirmModal>
    </>
  );
}
