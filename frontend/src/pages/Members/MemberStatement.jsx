import { useParams } from 'react-router-dom';
import { Download, FileSpreadsheet, Printer } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card from '../../components/ui/Card.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import Table from '../../components/ui/Table.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import memberService from '../../services/memberService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { normaliseColumns } from '../Reports/reportColumns.js';
import { formatNPR } from '../../utils/currency.js';

/**
 * Per-member ledger: savings, interest credits, loans, repayments and
 * distribution with a running balance (Req 11.2).
 *
 * The API answers with the standard report envelope, so the columns are taken
 * from the response rather than hard-coded here.
 */
export default function MemberStatement() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const { id } = useParams();

  const { data, loading, error, reload } = useFetch(() => memberService.statement(id), [id]);

  const { run: runExport, loading: exporting } = useApi((format) =>
    memberService.exportStatement(id, format),
  );

  const onExport = async (format) => {
    try {
      await runExport(format);
    } catch (caught) {
      toast.error(caught.message);
    }
  };

  if (loading && !data) return <LoadingState />;

  if (error) {
    return (
      <>
        <PageHeader title={t('member.statement')} backTo="/members" backLabel={t('member.title')} />
        <Alert variant="error" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      </>
    );
  }

  const member = data?.meta?.member || {};
  const totals = data?.totals || {};

  return (
    <>
      <PageHeader
        title={t('member.statement')}
        subtitle={member.member_id ? `${member.member_id} — ${member.full_name}` : undefined}
        backTo="/members"
        backLabel={t('member.title')}
        actions={
          <>
            <Button variant="secondary" size="sm" icon={Printer} onClick={() => window.print()}>
              {t('button.print')}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              icon={FileSpreadsheet}
              loading={exporting}
              onClick={() => onExport('xlsx')}
            >
              {t('button.export_excel')}
            </Button>
            <Button variant="secondary" size="sm" icon={Download} loading={exporting} onClick={() => onExport('pdf')}>
              {t('button.export_pdf')}
            </Button>
          </>
        }
      />

      <Card className="mb-4">
        <dl className="grid grid-cols-2 gap-4 text-sm sm:grid-cols-4">
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{t('member.member_id')}</dt>
            <dd className="mt-1 font-medium text-slate-900">{member.member_id || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{t('member.full_name')}</dt>
            <dd className="mt-1 font-medium text-slate-900">{member.full_name || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{t('member.phone')}</dt>
            <dd className="mt-1 font-medium text-slate-900">{member.phone || '—'}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-slate-500">{t('common.balance')}</dt>
            <dd className="mt-1 font-semibold tabular-nums text-brand-700">
              {formatNPR(data?.meta?.final_balance ?? 0, locale)}
            </dd>
          </div>
        </dl>
      </Card>

      <Table
        columns={normaliseColumns(data?.columns)}
        rows={data?.rows || []}
        loading={loading}
        emptyMessage={t('report.no_rows')}
        totals={Object.keys(totals).length ? totals : null}
        pageSize={50}
        printable
      />
    </>
  );
}
