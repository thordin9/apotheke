import net from 'node:net';

const objects = new Map();
let seq = 0;
let swapNext = false;

function send(socket, status, body) {
  const payload = Buffer.from(body);
  socket.end(`HTTP/1.1 ${status} Fixture\r\nContent-Type: application/json\r\nContent-Length: ${payload.length}\r\nConnection: close\r\n\r\n${body}`);
}

function readBody(buffer, headerEnd, headers) {
  const raw = buffer.subarray(headerEnd + 4);
  if (!/transfer-encoding:\s*chunked/i.test(headers)) {
    const length = /content-length:\s*(\d+)/i.exec(headers);
    const size = length ? Number(length[1]) : 0;
    return raw.length < size ? null : raw.subarray(0, size).toString();
  }
  const encoded = raw.toString('binary');
  let body = '';
  let offset = 0;
  while (offset < encoded.length) {
    const line = encoded.indexOf('\r\n', offset);
    if (line < 0) return null;
    const size = Number.parseInt(encoded.slice(offset, line), 16);
    if (Number.isNaN(size)) return null;
    const start = line + 2;
    if (encoded.length < start + size + 2) return null;
    if (size === 0) return body;
    body += encoded.slice(start, start + size);
    offset = start + size + 2;
  }
  return null;
}

const server = net.createServer((socket) => {
  const chunks = [];
  const done = (buffer) => {
    const headerEnd = buffer.indexOf('\r\n\r\n');
    if (headerEnd < 0) return false;
    const head = buffer.subarray(0, headerEnd).toString();
    const [start, ...rest] = head.split('\r\n');
    const [method, rawPath] = start.split(' ');
    const headers = rest.join('\r\n');
    const url = new URL(rawPath, 'http://127.0.0.1');
    if (url.pathname === '/stop') {
      send(socket, 200, '');
      server.close();
      process.exit(0);
    }
    if (url.pathname === '/reset') {
      objects.clear();
      swapNext = false;
      seq = 0;
      send(socket, 200, '{"ok":true}');
      return true;
    }
    if (url.pathname === '/arm') {
      swapNext = true;
      send(socket, 200, '{"ok":true}');
      return true;
    }
    if (method === 'PUT') {
      const body = readBody(buffer, headerEnd, headers);
      if (body === null) return false;
      const key = decodeURIComponent(url.pathname.slice(1));
      const current = objects.get(key);
      if (url.searchParams.get('if_none_match') === '*' && current) {
        send(socket, 412, '');
        return true;
      }
      const match = url.searchParams.get('if_match');
      if (match !== null && (current === undefined || match !== current.etag)) {
        send(socket, 412, '');
        return true;
      }
      objects.set(key, { body, etag: `etag-${++seq}`, modified: 1_700_000_000 + seq });
      send(socket, 200, '{"ok":true}');
      return true;
    }
    const key = decodeURIComponent(url.pathname.slice(1));
    const current = objects.get(key);
    if (method === 'DELETE') {
      objects.delete(key);
      send(socket, 200, '{"ok":true}');
      return true;
    }
    if (method === 'HEAD' || method === 'GET') {
      if (!current) {
        send(socket, 200, JSON.stringify({ ok: true, found: false }));
        return true;
      }
      if (method === 'HEAD') {
        send(socket, 200, JSON.stringify({ ok: true, found: true, modified: current.modified, etag: current.etag }));
        return true;
      }
      const snapshot = { body: current.body, etag: current.etag };
      if (swapNext) {
        swapNext = false;
        objects.set(key, { body: 'replaced-after-read', etag: 'etag-swapped', modified: current.modified });
      }
      send(socket, 200, JSON.stringify({
        ok: true,
        found: true,
        bytes: Buffer.from(snapshot.body).toString('base64'),
        etag: snapshot.etag,
      }));
      return true;
    }
    send(socket, 400, '{"ok":false}');
    return true;
  };
  socket.on('data', (chunk) => {
    chunks.push(chunk);
    done(Buffer.concat(chunks));
  });
});

server.listen(0, '127.0.0.1', () => {
  const address = server.address();
  process.stdout.write(`${address.port}\n`);
});
