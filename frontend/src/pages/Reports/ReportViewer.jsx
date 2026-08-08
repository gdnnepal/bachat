import { useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Download, FileSpreadsheet, Filter, Printer } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card from '../../components/ui/Card.jsx';
import FormField from '../../components/forms/FormField.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import SelectField from '../../components/forms/SelectField.jsx';
import Table from '../../components/ui/Table.jsx';
import memberService from '../../services/memberService.js';
import reportService, { REPORT_TYPES } from '../../services/reportService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { normaliseColumns } from './reportColumns.js';
import { bsMonthName, currentBsDate } from '../../utils/bsDate.js';

/**
 * Generic report viewer (Req 11.1, 11.3–11.5).
 *
 * Every report answers with the same `{title, columns, rows, totals, meta}`
 * envelope, so one component renders all nine — the only per-type branching is
 * which filters make sense (member statements need a member; the audit report
 * has its own filter set and is handled by the dedicated Audit page).
 */

const MONTHS = Array.from({ length: 12 }, (_, index) => index + 1);

export default function ReportViewer() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const { type } = useParams();

  const definition = REPORT_TYPES.find((report) => report.value === type);
  const needsMember = Boolean(definition?.needsMember);

  const thisYear = useMemo(() => currentBsDate().year, []);

  // `applied` is what the last fetch used; `draft` is what the filter bar holds.
  // Keeping them apart stops every keystroke from triggering a request.
  const [draft, setDraft] = useState({
    member_id: '',
    bs_year_from: '',
    bs_month_from: '',
    bs_year_to: '',
    bs_month_to: '',
  });
  const [applied, setApplied] = useState(draft);

  const { data: memberPage } = useFetch(
    () => memberService.list({ per_page: 500 }),
    [],
    { skip: !needsMember },
  );

  const filters = useMemo(
    () => Object.fromEntries(Object.entries(applied).filter(([, value]) => value !== '')),
    [applied],
  );

  // A member statement without a member selected has nothing to fetch yet.
  const skip = needsMember && !filters.member_id;

  const { data, loading, error, reload } = useFetch(
    () => reportService.fetch(type, filters),
    [type, filters],
    { skip },
  );

  const { run: runExport, loading: exporting } = useApi((format) =>
    reportService.export(type, format, filters),
  );

  const onExport = async (format) => {
    try {
      await runExport(format);
    } catch (caught) {
      toast.error(caught.message);
    }
  };

  const memberOptions = (memberPage?.rows || []).map((member) => ({
    value: String(member.id),
    label: `${member.member_id} — ${member.full_name}`,
  }));

  const monthOptions = MONTHS.map((month) => ({ value: String(month), label: bsMonthName(month, locale) }));

  const columns = useMemo(() => normaliseColumns(data?.columns, t), [data, t]);
  const totals = data?.totals || {};

  const setField = (name) => (event) => setDraft((current) => ({ ...current, [name]: event.target.value }));

  if (!definition) {
    return (
      <>
        <PageHeader title={t('report.title')} backTo="/reports" backLabel={t('report.title')} />
        <Alert variant="error">{t('error.page_not_found')}</Alert>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={data?.title || t(definition.labelKey)}
        backTo="/reports"
        backLabel={t('report.title')}
        actions={
          <>
            <Button variant="secondary" size="sm" icon={Printer} onClick={() => window.print()} disabled={skip}>
              {t('button.print')}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              icon={FileSpreadsheet}
              loading={exporting}
              disabled={skip}
              onClick={() => onExport('xlsx')}
            >
              {t('button.export_excel')}
            </Button>
            <Button
              variant="secondary"
              size="sm"
              icon={Download}
              loading={exporting}
              disabled={skip}
              onClick={() => onExport('pdf')}
            >
              {t('button.export_pdf')}
            </Button>
          </>
        }
      />

      <Card className="mb-4 no-print">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          {needsMember && (
            <SelectField
              label={t('loan.member')}
              required
              placeholder={t('common.select')}
              options={memberOptions}
              value={draft.member_id}
              onChange={setField('member_id')}
              containerClassName="lg:col-span-2"
            />
          )}

          <FormField
            label={`${t('report.from')} — ${t('report.year')}`}
            type="number"
            inputMode="numeric"
            placeholder={String(thisYear)}
            value={draft.bs_year_from}
            onChange={setField('bs_year_from')}
          />

          <SelectField
            label={`${t('report.from')} — ${t('report.month')}`}
            includeAll
            options={monthOptions}
            value={draft.bs_month_from}
            onChange={setField('bs_month_from')}
          />

          <FormField
            label={`${t('report.to')} — ${t('report.year')}`}
            type="number"
            inputMode="numeric"
            placeholder={String(thisYear)}
            value={draft.bs_year_to}
            onChange={setField('bs_year_to')}
          />

          <SelectField
            label={`${t('report.to')} — ${t('report.month')}`}
            includeAll
            options={monthOptions}
            value={draft.bs_month_to}
            onChange={setField('bs_month_to')}
          />
        </div>

        <div className="mt-3 flex justify-end gap-2">
          <Button
            variant="secondary"
            size="sm"
            onClick={() => {
              const cleared = {
                member_id: '',
                bs_year_from: '',
                bs_month_from: '',
                bs_year_to: '',
                bs_month_to: '',
              };
              setDraft(cleared);
              setApplied(cleared);
            }}
          >
            {t('button.reset')}
          </Button>
          <Button size="sm" icon={Filter} onClick={() => setApplied(draft)}>
            {t('button.search')}
          </Button>
        </div>
      </Card>

      {error && (
        <Alert variant="error" className="mb-4" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      )}

      {skip ? (
        <Alert variant="info">{t('common.select')}</Alert>
      ) : (
        <Table
          columns={columns}
          rows={data?.rows || []}
          loading={loading}
          emptyMessage={t('report.no_rows')}
          totals={Object.keys(totals).length ? totals : null}
          pageSize={50}
          printable
        />
      )}
    </>
  );
}
