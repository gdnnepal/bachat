import { Loader2 } from 'lucide-react';

/**
 * Button with variant/size styling and a built-in loading state.
 * `as` lets a link render with button styling (e.g. React Router <Link>).
 */

const VARIANTS = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 focus:ring-brand-500 disabled:bg-brand-300',
  secondary: 'bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 focus:ring-slate-400',
  danger: 'bg-red-600 text-white hover:bg-red-700 focus:ring-red-500 disabled:bg-red-300',
  ghost: 'bg-transparent text-slate-600 hover:bg-slate-100 focus:ring-slate-300',
  subtle: 'bg-brand-50 text-brand-700 hover:bg-brand-100 focus:ring-brand-400',
};

const SIZES = {
  sm: 'px-2.5 py-1.5 text-xs gap-1',
  md: 'px-4 py-2 text-sm gap-2',
  lg: 'px-5 py-2.5 text-base gap-2',
};

export default function Button({
  as: Component = 'button',
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled = false,
  icon: Icon,
  className = '',
  children,
  ...rest
}) {
  const isDisabled = disabled || loading;

  const classes = [
    'inline-flex items-center justify-center rounded-md font-medium shadow-sm transition',
    'focus:outline-none focus:ring-2 focus:ring-offset-1',
    'disabled:cursor-not-allowed disabled:opacity-70',
    VARIANTS[variant] || VARIANTS.primary,
    SIZES[size] || SIZES.md,
    className,
  ].join(' ');

  // Anchors and Links ignore `disabled`, so drop the handler instead.
  const extraProps = Component === 'button' ? { disabled: isDisabled } : {};

  return (
    <Component className={classes} aria-busy={loading || undefined} {...extraProps} {...rest}>
      {loading ? (
        <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
      ) : (
        Icon && <Icon className="h-4 w-4" aria-hidden="true" />
      )}
      {children}
    </Component>
  );
}
