import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import { readRelease } from './scripts/release-stamp.mjs';
import { spaBase } from './scripts/spa-base.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const release = readRelease(resolve(here, '..'));
const appBase = spaBase();
const headerPartial = readFileSync(resolve(here, 'src/partials/header.html'), 'utf8');
const footerPartial = readFileSync(resolve(here, 'src/partials/footer.html'), 'utf8');

if (/["'<>&\s]/.test(appBase)) {
    throw new Error('APOTHEKE_SPA_BASE cannot contain HTML or spaces');
}

function navFor(filename) {
    const file = String(filename || '').replaceAll('\\', '/');
    if (file.endsWith('/app/index.html')) {
        return [
            '<li><a class="btn" href="/">Home</a></li>',
            '<li><a class="btn" href="/privacy/">Privacy</a></li>',
        ].join('\n        ');
    }
    return [
        `<li><a class="btn btn-primary" href="${appBase}">Open the app</a></li>`,
        `<li><a class="btn" href="${appBase}sign-in">Sign in</a></li>`,
    ].join('\n        ');
}

// /app and /app/* (no file extension) serve the shell with 200, matching the
// production Worker. /app/index.html itself is a real file and is left alone.
function rewriteAppShell(req, _res, next) {
    const raw = req.url || '/';
    const q = raw.indexOf('?');
    const path = q < 0 ? raw : raw.slice(0, q);
    const search = q < 0 ? '' : raw.slice(q);
    let decoded = path;
    try {
        decoded = decodeURIComponent(path);
    } catch {
        next();
        return;
    }
    const mount = appBase.replace(/\/$/, '') || '/app';
    const last = decoded.split('/').pop() || '';
    const inApp = decoded === mount || (decoded.startsWith(mount + '/') && !last.includes('.'));
    if (inApp) req.url = mount + '/index.html' + search;
    next();
}

// Multi-page build. HTML output follows each input path:
//   index.html, privacy/index.html, 404.html, app/index.html
// Asset URLs stay root-absolute (base '/') so /404.html works at any depth.
// Fonts stay at /fonts/ because tokens.css uses those root-absolute urls.
export default defineConfig({
    base: '/',
    appType: 'mpa',
    server: { host: '127.0.0.1', port: 4173 },
    preview: { host: '127.0.0.1', port: 4173 },
    define: {
        __APOTHEKE_SPA_BASE__: JSON.stringify(appBase),
    },
    plugins: [
        {
            name: 'apotheke-html',
            transformIndexHtml: {
                order: 'pre',
                handler(html, ctx) {
                    const header = headerPartial.replace('<!-- apotheke:nav -->', navFor(ctx.filename));
                    return html
                        .replace('<!-- apotheke:header -->', header.trim())
                        .replace('<!-- apotheke:footer -->', footerPartial.trim())
                        .replaceAll('{version}', release.version)
                        .replaceAll('{date}', release.date)
                        .replaceAll('__APOTHEKE_SPA_BASE__', appBase);
                },
            },
            configureServer(server) {
                server.middlewares.use(rewriteAppShell);
            },
            configurePreviewServer(server) {
                server.middlewares.use(rewriteAppShell);
            },
        },
    ],
    build: {
        outDir: 'dist',
        emptyOutDir: true,
        sourcemap: false,
        cssCodeSplit: false,
        // Vite 8 minifies CSS with lightningcss, which treats U+0000 inside
        // unicode-range as a string terminator. That rewrites the latin Epilogue
        // range (U+0000-00FF) and the polytonic Greek range (U+1F00-1FFF) to "U+??",
        // so those fonts never match. Leave the CSS unminified. esbuild is not a
        // dependency of Vite 8, and this build adds no other package to get it.
        cssMinify: false,
        modulePreload: false,
        rollupOptions: {
            input: [
                resolve(here, 'index.html'),
                resolve(here, 'privacy/index.html'),
                resolve(here, '404.html'),
                resolve(here, 'app/index.html'),
            ],
        },
    },
});
