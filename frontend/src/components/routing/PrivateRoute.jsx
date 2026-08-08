import { Navigate, Outlet, useLocation } from 'react-router-dom';

import { LoadingState } from '../ui/Spinner.jsx';
import { useAuth } from '../../hooks/useAuth.jsx';

/**
 * Gate for authenticated routes.
 *
 * While the initial /auth/me probe is in flight we render a spinner rather than
 * redirecting — otherwise a page refresh would bounce a signed-in user to the
 * login screen. The attempted path is stashed in location state so Login can
 * return the user where they were headed.
 */
export default function PrivateRoute({ children }) {
  const { isAuthenticated, checking } = useAuth();
  const location = useLocation();

  if (checking) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-100">
        <LoadingState />
      </div>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  return children || <Outlet />;
}
