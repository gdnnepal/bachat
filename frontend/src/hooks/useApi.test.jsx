import { StrictMode } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { useApi, useFetch } from './useApi.jsx';
import { I18nProvider } from './useI18n.jsx';

/**
 * Regression cover for the StrictMode "mounted" flag.
 *
 * Both hooks guard their state updates with a `mounted` ref. React 18's
 * StrictMode mounts, unmounts and remounts every component in development, so a
 * ref that is only cleared in the effect cleanup stays false after the remount
 * and silently swallows every subsequent setState — the request resolves, the
 * data is thrown away and the page spins forever.
 *
 * These tests render inside <StrictMode> deliberately; without it they pass
 * even against the broken implementation.
 */

// I18nProvider fetches its translation map on mount. Stub the service so these
// tests exercise the data hooks rather than the network.
vi.mock('../services/langService.js', () => ({
  default: {
    fetch: () => Promise.resolve({ translations: {} }),
    setPreference: () => Promise.resolve(),
  },
}));

function withProviders(ui) {
  return (
    <StrictMode>
      <I18nProvider>{ui}</I18nProvider>
    </StrictMode>
  );
}

function FetchProbe() {
  const { data, loading } = useFetch(() => Promise.resolve({ total_members: 7 }), []);

  if (loading) return <p>spinner</p>;

  return <p>members: {data?.total_members ?? 'none'}</p>;
}

function RunProbe() {
  const { run, loading } = useApi(() => Promise.resolve('done'));

  return (
    <>
      <button type="button" onClick={() => run()}>
        go
      </button>
      <p>{loading ? 'busy' : 'idle'}</p>
    </>
  );
}

describe('useFetch', () => {
  it('renders resolved data instead of spinning forever under StrictMode', async () => {
    render(withProviders(<FetchProbe />));

    expect(screen.getByText('spinner')).toBeInTheDocument();

    await waitFor(() => expect(screen.getByText('members: 7')).toBeInTheDocument());
    expect(screen.queryByText('spinner')).not.toBeInTheDocument();
  });
});

describe('useApi', () => {
  it('clears its loading flag after run() settles under StrictMode', async () => {
    const user = userEvent.setup();

    render(withProviders(<RunProbe />));

    expect(screen.getByText('idle')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'go' }));

    await waitFor(() => expect(screen.getByText('idle')).toBeInTheDocument());
  });
});
