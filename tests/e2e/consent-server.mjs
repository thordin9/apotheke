// Serves frontend/dist on 127.0.0.1 and applies dist/_headers the way Cloudflare does.
import { createServer } from 'node:http';
import { existsSync, readFileSync, statSync } from 'node:fs';
import { extname, join, normalize } from 'node:path';
import { appFallback } from '../../frontend/scripts/app-fallback.mjs';
import { parseHeaders, headersFor } from '../../frontend/scripts/check-dist.mjs';

const root = join(import.meta.dirname, '../../frontend/dist');
const host = '127.0.0.1';
const port = 4175;
const rules = parseHeaders(readFileSync(join(root, '_headers'), 'utf8'));
const types = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.woff2': 'font/woff2',
    '.webmanifest': 'application/manifest+json',
    '.xml': 'application/xml; charset=utf-8',
    '.txt': 'text/plain; charset=utf-8',
    '.ico': 'image/x-icon',
};

function appMount() {
    const html = readFileSync(join(root, 'app/index.html'), 'utf8');
    const key = 'data-spa-base="';
    const start = html.indexOf(key);
    if (start < 0) return '/app';
    const value = html.slice(start + key.length, html.indexOf('"', start + key.length));
    if (!value.startsWith('/')) return '/app/';
    return value.endsWith('/') ? value : value + '/';
}

const mount = appMount();

function fileFor(urlPath) {
    const decoded = decodeURIComponent(urlPath.split('?')[0]);
    if (decoded.includes('\0') || decoded.split('/').includes('..')) return null;
    const rel = decoded.replace(/^\/+/, '');
    const candidates = [];
    if (rel === '' || rel.endsWith('/')) candidates.push(join(root, rel, 'index.html'));
    candidates.push(join(root, rel));
    candidates.push(join(root, rel, 'index.html'));
    for (const file of candidates) {
        const norm = normalize(file);
        if (!norm.startsWith(root)) continue;
        if (existsSync(norm) && statSync(norm).isFile()) return norm;
    }
    return null;
}

const server = createServer((req, res) => {
    const requestUrl = new URL(req.url || '/', 'http://' + host);
    const decision = appFallback(req.url || '/', mount);
    if (decision.kind === 'redirect') {
        for (const [name, value] of headersFor(rules, requestUrl.pathname)) res.setHeader(name, value);
        res.setHeader('Location', decision.location);
        res.writeHead(decision.status);
        res.end();
        return;
    }
    const path = decision.kind === 'shell' ? decision.url.split('?')[0] : requestUrl.pathname;
    const found = fileFor(path);
    const file = found || join(root, '404.html');
    const status = found ? 200 : 404;
    for (const [name, value] of headersFor(rules, requestUrl.pathname)) res.setHeader(name, value);
    if (!res.getHeader('content-type')) {
        res.setHeader('content-type', types[extname(file)] || 'application/octet-stream');
    }
    res.writeHead(status);
    res.end(readFileSync(file));
});

server.listen(port, host, () => {
    console.log('consent site http://' + host + ':' + port + '/');
});
