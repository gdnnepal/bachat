import { useState } from 'react';
import { Outlet } from 'react-router-dom';

import Header from '../components/layout/Header.jsx';
import Sidebar from '../components/layout/Sidebar.jsx';
import ToastContainer from '../components/ui/ToastContainer.jsx';
import { useFetch } from '../hooks/useApi.jsx';
import settingsService from '../services/settingsService.js';

/**
 * Authenticated shell: sidebar + header + routed page content.
 *
 * The cooperative name comes from settings so the header reflects whatever the
 * Super Admin configured; a failed fetch just falls back to the app name.
 */
export default function AppLayout() {
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const { data: settings } = useFetch(() => settingsService.fetch(), []);

  return (
    <div className="flex h-screen overflow-hidden bg-slate-100">
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      <div className="flex min-w-0 flex-1 flex-col">
        {/* GET /settings answers with { settings, writable } — the name is nested. */}
        <Header onMenuClick={() => setSidebarOpen(true)} cooperativeName={settings?.settings?.cooperative_name} />

        <main className="flex-1 overflow-y-auto p-4 print-full lg:p-6">
          <Outlet />
        </main>
      </div>

      <ToastContainer />
    </div>
  );
}
