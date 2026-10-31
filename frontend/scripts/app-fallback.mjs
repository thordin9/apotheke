// Same rule as the Worker in edge/app/src/index.ts.
// Bare /app is a 301 to /app/. An /app/* path with no file extension is the
// shell. A trailing extension (/\.[A-Za-z0-9]+$/) is a real file and is left
// alone, so a missing one stays a 404.

const FILE_EXT = /\.[A-Za-z0-9]+$/;

export function appFallback(url, mount = '/app/') {
    const raw = String(url || '/');
    const q = raw.indexOf('?');
    const path = q < 0 ? raw : raw.slice(0, q);
    const search = q < 0 ? '' : raw.slice(q);
    // The Worker tests url.pathname, which keeps %2E and a bad %E0. Decoding here
    // would treat /app/x%2Ejs as a file and drop /app/%E0. Test the raw path.
    const base = mount.endsWith('/') ? mount : mount + '/';
    const bare = base.slice(0, -1) || '/app';
    if (path === bare) return { kind: 'redirect', status: 301, location: base + search };
    if ((path === base || path.startsWith(base)) && !FILE_EXT.test(path)) {
        return { kind: 'shell', url: base + 'index.html' + search };
    }
    return { kind: 'pass' };
}

export function rewriteAppShell(req, res, next, mount = '/app/') {
    const decision = appFallback(req.url || '/', mount);
    if (decision.kind === 'redirect') {
        res.statusCode = decision.status;
        res.setHeader('Location', decision.location);
        res.end();
        return;
    }
    if (decision.kind === 'shell') req.url = decision.url;
    next();
}
