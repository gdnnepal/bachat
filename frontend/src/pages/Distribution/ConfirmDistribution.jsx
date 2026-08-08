import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { CheckCircle2, Download } from 'lucide-react';

import Alert from '../../components/ui/Alert.jsx';
import Button from '../../components/ui/Button.jsx';
import Card, { StatCard } from '../../components/ui/Card.jsx';
import PageHeader from '../../components/layout/PageHeader.jsx';
import { ConfirmModal } from '../../components/ui/Modal.jsx';
import { LoadingState } from '../../components/ui/Spinner.jsx';
import distributionService from '../../services/distributionService.js';
import { useApi, useFetch } from '../../hooks/useApi.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';
import { formatNPR, formatNumber } from '../../utils/currency.js';

/**
 * Distribution phase 2 — the irreversible payout (Req 10.4–10.7).
 *
 * This screen deliberately does nothing except explain what confirming will do
 * and gate it behind a typed acknowledgement. By the time it is used the money
 * has already been handed out at the meeting and the members have signed the
 * printed ledger; confirming records that in the books, closes the cycle and
 * opens the next one.
 */

const ACK = 'CONFIRM';

export default function ConfirmDistribution() {
  const { t, locale } = useI18n();
  const toast = useToast();
  const navigate = useNavigate();

  const [acknowledgement, setAcknowledgement] = useState('');
  const [confirming, setConfirming] = useState(false);

  const { data, loading, error, reload } = useFetch(() => distributionService.current(), []);

  const { run, loading: submitting } = useApi(() => distributionService.confirm());

  const cycle = data?.cycle;
  const totals = data?.totals || {};
  const items = data?.items || [];
  const pdfReady = Boolean(data?.pdf_ready);

  const onConfirm = async () => {
    try {
      await run();
      toast.success(t('distribution.completed'));
      setConfirming(false);
      navigate('/dashboard');
    } catch (caught) {
      toast.error(caught.message);
      setConfirming(false);
    }
  };

  if (loading && !data) return <LoadingState />;

  if (error) {
    return (
      <>
        <PageHeader title={t('button.confirm_distribution')} backTo="/distribution" backLabel={t('distribution.title')} />
        <Alert variant="error" actions={<Button size="sm" onClick={reload}>{t('button.retry')}</Button>}>
          {error.message}
        </Alert>
      </>
    );
  }

  return (
    <div className="mx-auto max-w-2xl">
      <PageHeader
        title={t('button.confirm_distribution')}
        subtitle={cycle ? cycle.name || `#${cycle.cycle_number}` : undefined}
        backTo="/distribution"
        backLabel={t('distribution.title')}
      />

      <Alert variant="warning" className="mb-4" title={t('common.confirm_title')}>
        {t('distribution.confirm_warning')}
      </Alert>

      {!pdfReady && (
        <Alert
          variant="error"
          className="mb-4"
          actions={
            <Button as={Link} size="sm" to="/distribution" variant="secondary" icon={Download}>
              {t('button.generate_pdf')}
            </Button>
          }
        >
          {t('distribution.pdf_required')}
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <StatCard label={t('distribution.member_count')} value={formatNumber(items.length, locale)} />
        <StatCard
          label={t('distribution.total_disbursed')}
          value={formatNPR(totals.final_payable ?? 0, locale)}
          tone="danger"
        />
        <StatCard
          label={t('distribution.shortfall')}
          value={formatNumber(totals.shortfall_count ?? 0, locale)}
          tone={totals.shortfall_count ? 'warning' : 'default'}
        />
      </div>

      <Card className="mt-4">
        <p className="text-sm text-slate-700">{t('distribution.confirm_warning')}</p>

        <label htmlFor="ack" className="form-label mt-4">
          {`${t('common.required')} — ${ACK}`}
        </label>
        <input
          id="ack"
          type="text"
          value={acknowledgement}
          onChange={(event) => setAcknowledgement(event.target.value)}
          placeholder={ACK}
          autoComplete="off"
          className="form-input"
        />

        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => navigate('/distribution')}>
            {t('button.cancel')}
          </Button>
          <Button
            variant="danger"
            icon={CheckCircle2}
            disabled={!pdfReady || acknowledgement.trim().toUpperCase() !== ACK}
            onClick={() => setConfirming(true)}
          >
            {t('button.confirm_distribution')}
          </Button>
        </div>
      </Card>

      <ConfirmModal
        open={confirming}
        onClose={() => setConfirming(false)}
        onConfirm={onConfirm}
        loading={submitting}
        variant="danger"
        title={t('button.confirm_distribution')}
        message={t('distribution.confirm_warning')}
        confirmLabel={t('button.confirm')}
      />
    </div>
  );
}
