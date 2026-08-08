import { Link } from 'react-router-dom';
import {
  Banknote,
  FileText,
  HandCoins,
  Landmark,
  PiggyBank,
  Percent,
  ScrollText,
  Share2,
  Wallet,
} from 'lucide-react';

import PageHeader from '../../components/layout/PageHeader.jsx';
import { REPORT_TYPES } from '../../services/reportService.js';
import { useI18n } from '../../hooks/useI18n.jsx';

/**
 * Report index (Req 11.1) — a launcher into ReportViewer, which does the actual
 * fetching, filtering and exporting for whichever type is picked.
 */

const ICONS = {
  'member-statement': FileText,
  monthly: Banknote,
  loans: HandCoins,
  'cash-book': Wallet,
  'bank-book': Landmark,
  savings: PiggyBank,
  interest: Percent,
  distribution: Share2,
  audit: ScrollText,
};

export default function Reports() {
  const { t } = useI18n();

  return (
    <>
      <PageHeader title={t('report.title')} />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {REPORT_TYPES.map((report) => {
          const Icon = ICONS[report.value] || FileText;

          return (
            <Link
              key={report.value}
              to={`/reports/${report.value}`}
              className="card flex items-center gap-3 px-4 py-4 transition hover:border-brand-300 hover:shadow"
            >
              <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700">
                <Icon className="h-5 w-5" aria-hidden="true" />
              </span>

              <span className="text-sm font-medium text-slate-800">{t(report.labelKey)}</span>
            </Link>
          );
        })}
      </div>
    </>
  );
}
