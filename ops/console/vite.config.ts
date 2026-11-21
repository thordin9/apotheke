import { defineConfig, type ViteDevServer } from 'vite';
import { handleMock } from './preview/mock-api.ts';
import { OPS_CSP } from './src/csp.ts';

// Dev server only. apply: 'serve' keeps this middleware out of the production build.
function previewFault(cookie: string | undefined): string {
  const match = /(?:^|; )ops_preview_fault=([^;]+)/.exec(cookie ?? '');
  return match ? decodeURIComponent(match[1]) : '';
}

function previewWrites(cookie: string | undefined): boolean {
  return /(?:^|; )ops_preview_writes=1(?:;|$)/.test(cookie ?? '');
}

function mockApi(req: { url?: string; method?: string; headers: { cookie?: string } }, res: { statusCode: number; setHeader(name: string, value: string): void; end(body: string): void }, next: () => void): void {
  const url = new URL(req.url ?? '/', 'http://127.0.0.1');
  if (!url.pathname.startsWith('/api/ops/v1')) {
    next();
    return;
  }
  const fault = previewFault(req.headers.cookie);
  const result = handleMock(req.method ?? 'GET', url, fault, previewWrites(req.headers.cookie));
  res.statusCode = result.status;
  const headers = result.headers ?? { 'content-type': 'application/json; charset=utf-8' };
  for (const [name, value] of Object.entries(headers)) res.setHeader(name, value);
  res.setHeader('cache-control', 'no-store');
  res.end(typeof result.body === 'string' ? result.body : JSON.stringify(result.body));
}

function previewCsp(_req: { url?: string }, res: { setHeader(name: string, value: string): void }, next: () => void): void {
  res.setHeader('content-security-policy', OPS_CSP);
  next();
}

function mockPlugin() {
  return {
    name: 'ops-console-mock',
    apply: 'serve' as const,
    configureServer(server: ViteDevServer) {
      server.middlewares.use(previewCsp);
      server.middlewares.use(mockApi);
    },
    configurePreviewServer(server: ViteDevServer) {
      server.middlewares.use(previewCsp);
      server.middlewares.use(mockApi);
    },
  };
}

export default defineConfig({
  server: { host: '127.0.0.1', port: 4181, strictPort: true },
  preview: { host: '127.0.0.1', port: 4181, strictPort: true },
  build: { sourcemap: false },
  plugins: [mockPlugin()],
});
