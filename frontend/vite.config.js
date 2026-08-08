import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

// In development we proxy `/api/v1` requests to the PHP backend so the browser
// sees a same-origin request and the session cookie is preserved.
//
// The values come from loadEnv() rather than process.env: Vite reads .env files
// into import.meta.env for client code, but it does NOT populate process.env
// here in the config, so reading process.env silently ignores .env and always
// falls through to the defaults.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');

  const API_TARGET = env.VITE_API_TARGET || 'http://127.0.0.1:8000';
  const API_BASE_PATH = env.VITE_API_BASE_PATH || '/api/v1';

  return {
    plugins: [react()],

    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },

    server: {
      port: 5173,
      proxy: {
        '/api/v1': {
          target: API_TARGET,
          changeOrigin: true,
          rewrite: (path) => path.replace(/^\/api\/v1/, API_BASE_PATH),
        },
      },
    },

    build: {
      outDir: 'dist',
      sourcemap: false,
    },

    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: './src/test/setup.js',
      css: false,
    },
  };
});
