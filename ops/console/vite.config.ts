import { defineConfig, type ViteDevServer } from 'vite';
import { handleMock } from './preview/mock-api.ts';

// Dev server only. apply: 'serve' keeps this middleware out of the production build.
function previewFault(cookie: string | undefined): string {
  const match = /(?:^|; )ops_preview_fault=([^;]+)/.exec(cookie ?? '');
  return match ? decodeURIComponent(match[1]) : '';
}

function mockPlugin() {
  return {
    name: 'ops-console-mock',
    apply: 'serve' as const,
    configureServer(server: ViteDevServer) {
      server.middlewares.use((req, res, next) => {
        const url = new URL(req.url ?? '/', 'http://127.0.0.1');
        if (!url.pathname.startsWith('/api/ops/v1')) {
          next();
          return;
        }
        const fault = previewFault(req.headers.cookie);
        const result = handleMock(req.method ?? 'GET', url, fault);
        res.statusCode = result.status;
        res.setHeader('content-type', 'application/json; charset=utf-8');
        res.setHeader('cache-control', 'no-store');
        res.end(JSON.stringify(result.body));
      });
    },
  };
}

export default defineConfig({
  server: { host: '127.0.0.1', port: 4181, strictPort: true },
  preview: { host: '127.0.0.1', port: 4181, strictPort: true },
  build: { sourcemap: false },
  plugins: [mockPlugin()],
});
