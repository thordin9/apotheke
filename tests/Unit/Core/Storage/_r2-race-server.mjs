import net from 'node:net';
const state = { body: 'first', etag: 'one' };
net.createServer((socket) => {
  socket.once('data', (request) => {
    const [method, rawPath] = request.toString().split('\r\n')[0].split(' ');
    const url = new URL(rawPath, 'http://r2.internal');
    let status = 200;
    let body = '';
    if (rawPath === '/stop') { socket.end('HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n'); process.exit(0); }
    if (method === 'GET') {
      const snapshot = { body: state.body, etag: state.etag };
      body = JSON.stringify({ ok: true, found: true, bytes: Buffer.from(snapshot.body).toString('base64'), etag: snapshot.etag });
    }
    if (method === 'HEAD') body = JSON.stringify({ ok: true, found: true, modified: 0, etag: state.etag });
    if (method === 'PUT') {
      if (url.searchParams.get('if_match') !== state.etag) status = 412;
      else {
        const input = request.subarray(request.indexOf('\r\n\r\n') + 4).toString();
        const chunk = /^([0-9a-f]+)/.exec(input);
        const bytes = chunk ? input.slice(chunk[0].length + 2, chunk[0].length + 2 + Number.parseInt(chunk[1], 16)) : input;
        state.body = bytes;
        state.etag = bytes === 'second' ? 'two' : 'three';
        body = '{"ok":true}';
      }
    }
    const response = Buffer.from(body);
    socket.end(`HTTP/1.1 ${status} Fixture\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n${response.length.toString(16)}\r\n${body}\r\n0\r\n\r\n`);
  });
}).listen(Number(process.argv[2] || 0), '127.0.0.1', function() { console.log(this.address().port); });
