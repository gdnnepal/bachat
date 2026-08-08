import { useEffect, useRef } from 'react';
import { Navigate, Outlet } from 'react-router-dom';

import { LoadingState } from '../ui/Spinner.jsx';
import { useAuth } from '../../hooks/useAuth.jsx';
import { useI18n } from '../../hooks/useI18n.jsx';
import { useToast } from '../../hooks/useToast.jsx';

/**
 * Gate for Super_Admin-only routes.
 *
 * A non-Super_Admin who reaches one of these URLs gets the 403 message as a
 * toast and is sent back to the dashboard — the server enforces the same rule,
 * this is only so the user sees why.
 */
export default function SuperAdminRoute({ children }) {
  const { isAuthenticated, isSuperAdmin, checking } = useAuth();
  const { t } = useI18n();
  const toast = useToast();

  const denied = !checking && isAuthenticated && !isSuperAdmin;
  const warned = useRef(false);

  useEffect(() => {
    // Ref guard: StrictMode double-invokes effects and would double-toast.
    if (denied && !warned.current) {
      warned.current = true;
      toast.error(t('error.forbidden'));
    }
  }, [denied, toast, t]);

  if (checking) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-100">
        <LoadingState />
      </div>
    );
  }

  if (!isAuthenticated) return <Navigate to="/login" replace />;
  if (denied) return <Navigate to="/dashboard" replace />;

  return children || <Outlet />;
}
