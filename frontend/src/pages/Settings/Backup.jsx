import { useRef, useState } from 'react';
import { Archive, Database, RotateCcw, Upload } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card from '../../components/ui/Card.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import Table from '../../components/ui/Table.jsx';
import { ConfirmModal } from '../../components/ui/Modal.jsx';
import backupService from '../../services/backupService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';

/**
 * Database backup and restore (Req 13.1–13.5).
 *
 * Restore is deliberately two-step: the file is validated server-side first and
 * the summary of what it contains is shown, and only then can the destructive
 * overwrite be confirmed. Nothing touches the database until that second call.
 */

const ACK = 'RESTORE';

/**
 * The validation summary reports raw bytes; the listing already carries a
 * formatted size, so only this path needs its own formatter.
 */
function humanSize(bytes) {
  const value = Number(bytes);
  if (!Number.isFinite(value) || value <= 0) return '—';

  const units = ['B', 'KB', 'MB', 'GB'];
  let size = value;
  let unit = 0;

  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024;
    unit += 1;
  }

  return `${size.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

export default function Backup() {
  const { t } = useI18n();
  const toast = useToast();
  const fileInput = useRef(null);

  // Candidate = the file we have validated and are offering to restore.
  const [candidate, setCandidate] = useState(null);
  const [validation, setValidation] = useState(null);
  const [acknowledgement, setAcknowledgement] = useState('');
  const [confirming, setConfirming] = useState(false);

  const { data: backups, loading, error, reload } = useFetch(() => backupService.list(), []);

  const { run: runCreate, loading: creating } = useApi(() => backupService.create());
  const { run: runValidate, loading: validating } = useApi((filename) => backupService.validate(filename));
  const { run: runRestore, loading: restoring } = useApi((filename) => backupService.restore(filename));
  const { run: runUpload, loading: uploading } = useApi((file) => backupService.upload(file));

  const rows = Array.isArray(backups) ? backups : [];

  const onCreate = async () => {
    try {
      const result = await runCreate();
      toast.success(`${t('backup.created_ok')} ${result?.filename || ''}`.trim());
      reload();
    } catch (caught) {
      toast.error(caught.message);
    }
  };

  const startRestore = async (filename) => {
    setCandidate(filename);
    setValidation(null);
    setAcknowledgement('');

    try {
      const result = await runValidate(filename);
      setValidation(result?.summary || {});
    } catch (caught) {
      toast.error(caught.message);
      setCandidate(null);
    }
  };

  const onUpload = async (event) => {
    const file = event.target.files?.[0];
    if (!file) return;

    if (!file.name.endsWith('.sql.gz')) {
      toast.error(t('backup.invalid_file'));
      event.target.value = '';
      return;
    }

    try {
      // The upload endpoint validates without writing, and answers with the
      // stored filename we then restore from.
      const body = await runUpload(file);
      const stored = body?.data?.summary?.filename || file.name;

      setCandidate(stored);
      setValidation(body?.data?.summary || {});
      setAcknowledgement('');
      reload();
    } catch (caught) {
      toast.error(caught.message);
    } finally {
      event.target.value = '';
    }
  };

  const onConfirmRestore = async () => {
    try {
      await runRestore(candidate);
      toast.success(t('backup.restored'));
      setConfirming(false);
      setCandidate(null);
      setValidation(null);
      setAcknowledgement('');
      reload();
    } catch (caught) {
      toast.error(caught.message);
      setConfirming(false);
    }
  };

  const columns = [
    { key: 'filename', label: t('backup.filename') },
    { key: 'size_human', label: t('backup.size'), align: 'right' },
    { key: 'created_at', label: t('backup.created_at') },
    {
      key: 'actions',
      label: t('table.actions'),
      align: 'right',
      sortable: false,
      className: 'no-print',
      render: (_value, row) => (
        <Button
          variant="ghost"
          size="sm"
          icon={RotateCcw}
          loading={validating && candidate === row.filename}
          onClick={() => startRestore(row.filename)}
        >
          {t('button.restore')}
        </Button>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t('backup.title')}
        actions={
          <>
            <input
              ref={fileInput}
              type="file"
              accept=".gz,.sql.gz,application/gzip"
              onChange={onUpload}
              className="hidden"
            />
            <Button variant="secondary" icon={Upload} loading={uploading} onClick={() => fileInput.current?.click()}>
              {t('button.upload')}
            </Button>
            <Button icon={Archive} loading={creating} onClick={onCreate}>
              {t('button.create_backup')}
            </Button>
          </>
        }
      />

      <Alert variant="warning" className="mb-4">
        {t('backup.restore_warning')}
      </Alert>

      {error && (
        <Alert variant="error" className="mb-4" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      )}

      {/* ─── Validated candidate awaiting the destructive confirmation ──── */}
      {candidate && validation && (
        <Card className="mb-4" title={t('backup.restore')} subtitle={candidate}>
          <p className="text-sm text-slate-700">{t('backup.validate_first')}</p>

          <dl className="mt-3 grid grid-cols-2 gap-4 text-sm sm:grid-cols-3">
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">{t('backup.tables')}</dt>
              <dd className="mt-1 font-medium tabular-nums">
                {Array.isArray(validation.tables) ? validation.tables.length : (validation.tables ?? '—')}
              </dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">{t('backup.statements')}</dt>
              <dd className="mt-1 font-medium tabular-nums">{validation.statement_count ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-slate-500">{t('backup.size')}</dt>
              <dd className="mt-1 font-medium tabular-nums">{humanSize(validation.size_bytes)}</dd>
            </div>
          </dl>

          <label htmlFor="restore-ack" className="form-label mt-4">
            {`${t('common.required')} — ${ACK}`}
          </label>
          <input
            id="restore-ack"
            type="text"
            value={acknowledgement}
            onChange={(event) => setAcknowledgement(event.target.value)}
            placeholder={ACK}
            autoComplete="off"
            className="form-input max-w-xs"
          />

          <div className="mt-4 flex justify-end gap-2">
            <Button
              variant="secondary"
              onClick={() => {
                setCandidate(null);
                setValidation(null);
                setAcknowledgement('');
              }}
            >
              {t('button.cancel')}
            </Button>
            <Button
              variant="danger"
              icon={Database}
              disabled={acknowledgement.trim().toUpperCase() !== ACK}
              onClick={() => setConfirming(true)}
            >
              {t('button.restore')}
            </Button>
          </div>
        </Card>
      )}

      <Table
        columns={columns}
        rows={rows}
        rowKey="filename"
        loading={loading}
        searchable={false}
        emptyMessage={t('backup.none')}
        pageSize={25}
      />

      <ConfirmModal
        open={confirming}
        onClose={() => setConfirming(false)}
        onConfirm={onConfirmRestore}
        loading={restoring}
        variant="danger"
        title={t('backup.restore')}
        message={t('backup.restore_warning')}
        confirmLabel={t('button.restore')}
      >
        <p className="mt-2 font-mono text-sm text-slate-900">{candidate}</p>
      </ConfirmModal>
    </>
  );
}
