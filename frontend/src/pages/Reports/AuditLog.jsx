import { useMemo, useState } from 'react';
import { ChevronLeft, ChevronRight, Download, Filter } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card from '../../components/ui/Card.jsx';
import FormField from '../../components/forms/FormField.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import Table from '../../components/ui/Table.jsx';
import reportService from '../../services/reportService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';

/**
 * Audit log viewer (Req 12.4).
 *
 * The audit table is append-only and can grow past what a browser should hold,
 * so paging is server-driven here: the API returns 200 rows per page with the
 * pagination block in meta, and Table's own pagination is switched off.
 */

const EMPTY_FILTERS = { date_from: '', date_to: '', admin_username: '', action_type: '' };

export default function AuditLog() {
  const { t } = useI18n();
  const toast = useToast();

  const [draft, setDraft] = useState(EMPTY_FILTERS);
  const [applied, setApplied] = useState(EMPTY_FILTERS);
  const [page, setPage] = useState(1);

  const query = useMemo(
    () => ({
      ...Object.fromEntries(Object.entries(applied).filter(([, value]) => value !== '')),
      page,
    }),
    [applied, page],
  );

  const { data, loading, error, reload } = useFetch(() => reportService.audit(query), [query]);

  const { run: runExport, loading: exporting } = useApi((format) =>
    reportService.export('audit', format, applied),
  );

  const pagination = data?.meta?.pagination || { page: 1, total_pages: 1, total: 0 };
  const actionTypes = data?.meta?.action_types || [];

  const setField = (name) => (event) => setDraft((current) => ({ ...current, [name]: event.target.value }));

  const applyFilters = () => {
    setApplied(draft);
    setPage(1); // A new filter set invalidates the current page number.
  };

  const onExport = async (format) => {
    try {
      await runExport(format);
    } catch (caught) {
      toast.error(caught.message);
    }
  };

  const columns = [
    { key: 'logged_at', label: t('audit.datetime') },
    { key: 'admin_username', label: t('audit.admin') },
    {
      key: 'action_type',
      label: t('audit.action'),
      render: (value) => String(value || '').replace(/_/g, ' '),
    },
    { key: 'description', label: t('audit.description') },
    { key: 'ip_address', label: t('audit.ip') },
    {
      key: 'user_agent',
      label: t('audit.user_agent'),
      render: (value) => (
        <span className="block max-w-xs truncate" title={value || ''}>
          {value || '—'}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t('audit.title')}
        actions={
          <Button variant="secondary" size="sm" icon={Download} loading={exporting} onClick={() => onExport('pdf')}>
            {t('button.export_pdf')}
          </Button>
        }
      />

      <Card className="mb-4 no-print">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <FormField label={t('report.from')} type="date" value={draft.date_from} onChange={setField('date_from')} />
          <FormField label={t('report.to')} type="date" value={draft.date_to} onChange={setField('date_to')} />

          <FormField
            label={t('audit.admin')}
            placeholder={t('audit.all_admins')}
            value={draft.admin_username}
            onChange={setField('admin_username')}
          />

          <SelectField
            label={t('audit.action')}
            includeAll
            options={actionTypes.map((value) => ({ value, label: String(value).replace(/_/g, ' ') }))}
            value={draft.action_type}
            onChange={setField('action_type')}
          />
        </div>

        <div className="mt-3 flex justify-end gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => {
              setDraft(EMPTY_FILTERS);
              setApplied(EMPTY_FILTERS);
              setPage(1);
            }}
          >
            {t('button.reset')}
          </Button>
          <Button size="sm" icon={Filter} onClick={applyFilters}>
            {t('button.search')}
          </Button>
        </div>
      </Card>

      {error && (
        <Alert variant="error" className="mb-4" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      )}

      <Table
        columns={columns}
        rows={data?.rows || []}
        loading={loading}
        searchable={false}
        paginated={false}
        emptyMessage={t('report.no_rows')}
        printable
      />

      <div className="mt-3 flex items-center justify-between text-xs text-slate-600 no-print">
        <span>
          {t('table.range', {
            from: (pagination.page - 1) * (pagination.per_page || 0) + 1,
            to: Math.min(pagination.page * (pagination.per_page || 0), pagination.total),
            total: pagination.total,
          })}
        </span>

        <div className="flex items-center gap-1">
          <Button
            variant="ghost"
            size="sm"
            icon={ChevronLeft}
            disabled={page <= 1 || loading}
            onClick={() => setPage((current) => current - 1)}
          >
            {t('table.previous')}
          </Button>

          <span className="px-2">
            {t('table.page_of', { page: pagination.page, pages: pagination.total_pages || 1 })}
          </span>

          <Button
            variant="ghost"
            size="sm"
            disabled={page >= (pagination.total_pages || 1) || loading}
            onClick={() => setPage((current) => current + 1)}
          >
            {t('table.next')}
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          </Button>
        </div>
      </div>
    </>
  );
}
