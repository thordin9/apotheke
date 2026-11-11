// A local d1.internal for PHP tests: the real Installation object (edge/src/installation.ts) on a node:sqlite
// stand-in for its storage, in storage mode, on 127.0.0.1 only. Prints the port, then serves until killed.
// Run: node --experimental-strip-types edge/tests/do-bridge-server.mjs
import { createServer } from 'node:http';
import { register } from 'node:module';
import { doStorage } from './do-storage.mjs';

const stub = 'export class DurableObject { constructor(ctx, env) { this.ctx = ctx; this.env = env; } } export class WorkerEntrypoint { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }';
register('data:text/javascript,' + encodeURIComponent(`export async function resolve(s, c, next) {
  if (s === 'cloudflare:workers') return { url: 'data:text/javascript,${encodeURIComponent(stub)}', shortCircuit: true };
  if (s.startsWith('./') && !s.endsWith('.ts') && c.parentURL && c.parentURL.includes('/edge/src/')) return next(s + '.ts', c);
  return next(s, c);
}`));
const { Installation } = await import(new URL('../src/installation.ts', import.meta.url).href);
const object = new Installation({ storage: doStorage() }, { STORAGE: {}, DB: {}, INSTALLATION: {} });

const server = createServer(async (req, res) => {
  const chunks = [];
  for await (const c of req) chunks.push(c);
  const r = await object.fetch(new Request(`http://d1.internal${req.url}`, { method: req.method, headers: { 'content-type': 'application/json' }, body: req.method === 'POST' ? Buffer.concat(chunks) : undefined }));
  res.writeHead(r.status, { 'content-type': 'application/json' });
  res.end(await r.text());
});
server.listen(0, '127.0.0.1', () => console.log(server.address().port));
