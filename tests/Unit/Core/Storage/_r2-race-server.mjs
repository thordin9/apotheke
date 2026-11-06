import http from 'node:http';
const state = { body: 'first', etag: 'one' };
http.createServer((req, res) => {
  const url = new URL(req.url, 'http://r2.internal');
  let result = { status: 500, body: '' };
  if (req.method === 'GET') result = { status: 200, body: JSON.stringify({ ok: true, found: true, bytes: Buffer.from(state.body).toString('base64'), etag: state.etag }) };
  if (req.method === 'PUT') {
    const chunks = [];
    req.on('data', (chunk) => chunks.push(chunk));
    req.on('end', () => {
      if (url.searchParams.get('if_match') !== state.etag) { res.writeHead(412); res.end(); return; }
      const body = Buffer.concat(chunks).toString();
      const tag = state.etag;
      setTimeout(() => { if (state.etag !== tag) { res.writeHead(412); res.end(); return; } state.body = body; state.etag = body === 'second' ? 'two' : 'three'; res.writeHead(200, {'content-type':'application/json'}); res.end('{"ok":true}'); }, 100);
    });
    return;
  }
  if (req.url === '/stop') res.end(() => process.exit(0));
  res.writeHead(result.status, {'content-type':'application/json'});
  res.end(result.body);
}).listen(Number(process.argv[2] || 0), '127.0.0.1', function() { console.log(this.address().port); });
