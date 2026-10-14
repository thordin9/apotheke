import { spawn, ChildProcess } from 'node:child_process';
import * as fs from 'node:fs';
import * as net from 'node:net';
import * as os from 'node:os';
import * as path from 'node:path';
import { ROOT } from './fixtures';

/** The autofill-service stand-in (tests/stubs/autofill-stub.php): an echo backend with ok/error/malformed/slow… modes. */
export class AutofillStub {
  base = '';
  private proc?: ChildProcess;
  private log = path.join(os.tmpdir(), `af-stub-${process.pid}-${Math.random().toString(36).slice(2)}.json`);

  async start() {
    const port = await new Promise<number>((res, rej) => {
      const s = net.createServer();
      s.listen(0, '127.0.0.1', () => { const p = (s.address() as net.AddressInfo).port; s.close(() => res(p)); });
      s.on('error', rej);
    });
    this.base = `http://127.0.0.1:${port}`;
    this.proc = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests/stubs/autofill-stub.php')],
      { env: { ...process.env, PHP_CLI_SERVER_WORKERS: '4', STUB_LOG: this.log, STUB_SLOW_S: '8' }, stdio: 'ignore' });
    for (let i = 0; i < 100; i++) {
      try { if ((await fetch(this.base + '/healthz')).ok) return this; } catch { /* not yet */ }
      await new Promise(r => setTimeout(r, 100));
    }
    throw new Error('autofill stub did not start');
  }
  /** The last /v1/suggest request (auth header, context, image type, size, width, height). */
  last(): any { try { const s = fs.readFileSync(this.log, 'utf8'); return s ? JSON.parse(s) : null; } catch { return null; } }
  clear() { try { fs.writeFileSync(this.log, ''); } catch { /* ignore */ } }
  stop() { this.proc?.kill(); try { fs.unlinkSync(this.log); } catch { /* ignore */ } }
}
