import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';

import App from './App.jsx';
import ErrorBoundary from './components/ui/ErrorBoundary.jsx';
import { I18nProvider } from './hooks/useI18n.jsx';
import { ToastProvider } from './hooks/useToast.jsx';
import { AuthProvider } from './hooks/useAuth.jsx';
import './index.css';

// Provider order matters:
//   ErrorBoundary  — catches render errors from everything below it
//   I18nProvider   — t() must exist before any component renders a label
//   ToastProvider  — AuthProvider and the API layer push toasts
//   AuthProvider   — route guards read auth state
ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ErrorBoundary>
      <I18nProvider>
        <ToastProvider>
          <BrowserRouter>
            <AuthProvider>
              <App />
            </AuthProvider>
          </BrowserRouter>
        </ToastProvider>
      </I18nProvider>
    </ErrorBoundary>
  </React.StrictMode>,
);
