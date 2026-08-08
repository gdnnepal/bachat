import { Suspense, lazy } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';

import PrivateRoute from './components/routing/PrivateRoute.jsx';
import SuperAdminRoute from './components/routing/SuperAdminRoute.jsx';
import AppLayout from './layouts/AppLayout.jsx';
import AuthLayout from './layouts/AuthLayout.jsx';
import { LoadingState } from './components/ui/Spinner.jsx';
import { useI18n } from './hooks/useI18n.jsx';

/**
 * Route table.
 *
 * Every page is code-split: the login screen and the dashboard are all a
 * signing-in user downloads, and heavier screens (reports, distribution,
 * backup) only load when they are actually opened.
 */

const Login = lazy(() => import('./pages/Login.jsx'));
const ChangePassword = lazy(() => import('./pages/ChangePassword.jsx'));
const Dashboard = lazy(() => import('./pages/Dashboard.jsx'));

const MemberList = lazy(() => import('./pages/members/MemberList.jsx'));
const MemberForm = lazy(() => import('./pages/members/MemberForm.jsx'));
const MemberStatement = lazy(() => import('./pages/members/MemberStatement.jsx'));

const BulkSavings = lazy(() => import('./pages/savings/BulkSavings.jsx'));

const LoanList = lazy(() => import('./pages/loans/LoanList.jsx'));
const LoanForm = lazy(() => import('./pages/loans/LoanForm.jsx'));
const LoanDetail = lazy(() => import('./pages/loans/LoanDetail.jsx'));
const RepaymentForm = lazy(() => import('./pages/loans/RepaymentForm.jsx'));

const CashBank = lazy(() => import('./pages/cashbank/CashBank.jsx'));

const Distribution = lazy(() => import('./pages/distribution/Distribution.jsx'));
const ConfirmDistribution = lazy(() => import('./pages/distribution/ConfirmDistribution.jsx'));

const Reports = lazy(() => import('./pages/reports/Reports.jsx'));
const ReportViewer = lazy(() => import('./pages/reports/ReportViewer.jsx'));
const AuditLog = lazy(() => import('./pages/reports/AuditLog.jsx'));

const Settings = lazy(() => import('./pages/settings/Settings.jsx'));
const MonthClose = lazy(() => import('./pages/settings/MonthClose.jsx'));
const Backup = lazy(() => import('./pages/settings/Backup.jsx'));

const AdminList = lazy(() => import('./pages/admins/AdminList.jsx'));
const AdminForm = lazy(() => import('./pages/admins/AdminForm.jsx'));

function NotFound() {
  const { t } = useI18n();

  return (
    <div className="flex h-full min-h-[60vh] items-center justify-center text-center text-slate-400">
      <div>
        <p className="text-8xl font-bold text-slate-200">404</p>
        <p className="mt-3 text-xl">{t('error.page_not_found')}</p>
      </div>
    </div>
  );
}

export default function App() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center bg-slate-100">
          <LoadingState />
        </div>
      }
    >
      <Routes>
        {/* Public */}
        <Route element={<AuthLayout />}>
          <Route path="/login" element={<Login />} />
        </Route>

        {/* Protected */}
        <Route element={<PrivateRoute><AppLayout /></PrivateRoute>}>
          <Route index element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/change-password" element={<ChangePassword />} />

          {/* Members */}
          <Route path="/members" element={<MemberList />} />
          <Route path="/members/new" element={<MemberForm />} />
          <Route path="/members/:id" element={<MemberForm />} />
          <Route path="/members/:id/statement" element={<MemberStatement />} />

          {/* Savings */}
          <Route path="/savings/bulk" element={<BulkSavings />} />

          {/* Loans */}
          <Route path="/loans" element={<LoanList />} />
          <Route path="/loans/new" element={<LoanForm />} />
          <Route path="/loans/:id" element={<LoanDetail />} />
          <Route path="/loans/:id/repay" element={<RepaymentForm />} />

          {/* Cash & Bank */}
          <Route path="/cash-bank" element={<CashBank />} />

          {/* Distribution */}
          <Route path="/distribution" element={<Distribution />} />
          <Route path="/distribution/confirm" element={<ConfirmDistribution />} />

          {/* Reports */}
          <Route path="/reports" element={<Reports />} />
          <Route path="/audit" element={<AuditLog />} />
          <Route path="/reports/audit" element={<Navigate to="/audit" replace />} />
          <Route path="/reports/:type" element={<ReportViewer />} />

          {/* Settings */}
          <Route path="/settings" element={<Settings />} />
          <Route path="/month-close" element={<MonthClose />} />
          <Route path="/backup" element={<SuperAdminRoute><Backup /></SuperAdminRoute>} />

          {/* Super_Admin only */}
          <Route path="/admin-management" element={<SuperAdminRoute><AdminList /></SuperAdminRoute>} />
          <Route path="/admin-management/new" element={<SuperAdminRoute><AdminForm /></SuperAdminRoute>} />
          <Route path="/admin-management/:id" element={<SuperAdminRoute><AdminForm /></SuperAdminRoute>} />

          {/* 404 inside the app shell, so the user keeps their navigation */}
          <Route path="*" element={<NotFound />} />
        </Route>
      </Routes>
    </Suspense>
  );
}
