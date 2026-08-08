import React from 'react';

/**
 * Top-level render error trap.
 *
 * Mounted above I18nProvider in main.jsx, so it cannot call t() — the fallback
 * copy is deliberately plain English.
 */
export default class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error) {
    return { error };
  }

  componentDidCatch(error, info) {
    // No remote logging endpoint in this deployment; console is the audit trail.
    console.error('Unhandled render error:', error, info?.componentStack);
  }

  handleReload = () => {
    window.location.href = '/';
  };

  render() {
    const { error } = this.state;

    if (!error) return this.props.children;

    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-100 p-6">
        <div className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
          <h1 className="text-lg font-semibold text-slate-900">Something went wrong</h1>

          <p className="mt-2 text-sm text-slate-600">
            The page failed to load. Your data has not been changed. Reload and try again — if the problem
            continues, note what you were doing and contact the administrator.
          </p>

          {import.meta.env?.DEV && (
            <pre className="mt-4 max-h-40 overflow-auto rounded bg-slate-900 p-3 text-xs text-red-200">
              {String(error?.stack || error?.message || error)}
            </pre>
          )}

          <div className="mt-5 flex gap-2">
            <button
              type="button"
              onClick={this.handleReload}
              className="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-700"
            >
              Back to home
            </button>

            <button
              type="button"
              onClick={() => window.location.reload()}
              className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
            >
              Reload page
            </button>
          </div>
        </div>
      </div>
    );
  }
}
