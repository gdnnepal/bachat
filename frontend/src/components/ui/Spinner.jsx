import { Loader2 } from 'lucide-react';

import { useI18n } from '../../hooks/useI18n.jsx';

const SIZES = { sm: 'h-4 w-4', md: 'h-6 w-6', lg: 'h-9 w-9' };

export default function Spinner({ size = 'md', className = '' }) {
  return <Loader2 className={`animate-spin text-brand-600 ${SIZES[size] || SIZES.md} ${className}`} aria-hidden="true" />;
}

/** Centred spinner with a label — for whole-page and whole-card loading states. */
export function LoadingState({ label, className = '' }) {
  const { t } = useI18n();

  return (
    <div className={`flex flex-col items-center justify-center gap-2 py-10 text-sm text-slate-500 ${className}`} role="status">
      <Spinner size="lg" />
      <span>{label || t('common.loading')}</span>
    </div>
  );
}
