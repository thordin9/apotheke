import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import { readRelease } from './scripts/release-stamp.mjs';
import { spaBase } from './scripts/spa-base.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const release = readRelease(resolve(here, '..'));
const appBase = spaBase();

// Multi-page build. HTML output follows each input path:
//   index.html, privacy/index.html, 404.html, app/index.html
// Asset URLs stay root-absolute (base '/') so /404.html works at any depth.
// Fonts stay at /fonts/ because tokens.css uses those root-absolute urls.
export default defineConfig({
    base: '/',
    appType: 'mpa',
    server: { host: '127.0.0.1', port: 4173 },
    preview: { host: '127.0.0.1', port: 4173 },
    plugins: [
        {
            name: 'apotheke-html',
            transformIndexHtml(html) {
                return html
                    .replaceAll('{version}', release.version)
                    .replaceAll('{date}', release.date)
                    .replaceAll('__APOTHEKE_SPA_BASE__', appBase);
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
