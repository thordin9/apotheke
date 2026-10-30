#!/usr/bin/env node
// Checks frontend/dist after `npm run build`. Dependency-free (plain Node 22).
//   node frontend/scripts/check-dist.mjs
// Mirrors Cloudflare _headers: matching rules are inherited, a repeated header
// is joined with a comma, and `! Header-Name` detaches the header so far.

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(frontend, 'dist');
const failures = [];
const fail = (msg) => failures.push(msg);

const LANDING_CSP = "default-src 'none'; style-src 'self'; img-src 'self' data:; font-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'";
const SPA_CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' blob: data: https://api.services.getapotheke.com; connect-src 'self' https://api.services.getapotheke.com; font-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'";

function escapeRegExp(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function parseHeaders(text) {
    const rules = [];
    let current = null;
    for (const raw of text.split('\n')) {
        const line = raw.replace(/\r$/, '');
        const trimmed = line.trim();
        if (!trimmed || trimmed.startsWith('#')) continue;
        if (line.startsWith(' ') || line.startsWith('\t')) {
            if (!current) throw new Error('header without a path: ' + trimmed);
            if (trimmed.startsWith('! ')) {
                current.ops.push({ detach: trimmed.slice(2).trim().toLowerCase() });
            } else {
                const i = trimmed.indexOf(':');
                if (i < 0) throw new Error('bad header line: ' + trimmed);
                current.ops.push({
                    name: trimmed.slice(0, i).trim().toLowerCase(),
                    value: trimmed.slice(i + 1).trim(),
                });
            }
            continue;
        }
        current = { pattern: trimmed, ops: [] };
        rules.push(current);
    }
    return rules;
}

function matches(pattern, path) {
    const body = pattern.split('*').map(escapeRegExp).join('(.*)');
    return new RegExp('^' + body + '$').test(path);
}

export function headersFor(rules, path) {
    const out = new Map();
    for (const rule of rules) {
        if (!matches(rule.pattern, path)) continue;
        for (const op of rule.ops) {
            if (op.detach) {
                out.delete(op.detach);
                continue;
            }
            const prev = out.get(op.name);
            out.set(op.name, prev ? prev + ', ' + op.value : op.value);
        }
    }
    return out;
}

function expectedCache(path) {
    if (path === '/assets/icons.svg' || path.startsWith('/assets/brand/') || path.startsWith('/assets/mascot/') || path.startsWith('/assets/shots/')) {
        return 'public, max-age=86400';
    }
    if (path.startsWith('/assets/')) return 'public, max-age=31536000, immutable';
    return 'no-cache';
}

function walk(dir, rel = '') {
    const out = [];
    for (const ent of readdirSync(dir, { withFileTypes: true })) {
        const child = rel ? rel + '/' + ent.name : ent.name;
        if (ent.isDirectory()) out.push(...walk(join(dir, ent.name), child));
        else out.push(child);
    }
    return out;
}

function htmlUrls(html) {
    const urls = [];
    for (const m of html.matchAll(/\b(?:href|src)\s*=\s*"([^"]*)"/gi)) urls.push(m[1]);
    for (const m of html.matchAll(/\bsrcset\s*=\s*"([^"]*)"/gi)) {
        for (const part of m[1].split(',')) {
            const url = part.trim().split(/\s+/)[0];
            if (url) urls.push(url);
        }
    }
    return urls;
}

function resolves(url) {
    const path = url.split('#')[0].split('?')[0];
    if (path === '' || /^(https?:|mailto:)/i.test(path)) return { ok: true };
    if (path.startsWith('//') || !path.startsWith('/')) return { ok: false, why: 'not a root-absolute path' };
    const rel = path.replace(/^\/+/, '');
    const candidates = rel === '' ? [join(dist, 'index.html')] : [join(dist, rel), join(dist, rel, 'index.html')];
    const found = candidates.some((file) => {
        return existsSync(file) && statSync(file).isFile() && (file === dist || file.startsWith(dist + '/'));
    });
    return { ok: found, why: found ? '' : 'missing in dist' };
}

if (!existsSync(dist)) fail('frontend/dist is missing (run npm run build)');

for (const rel of ['index.html', 'privacy/index.html', '404.html', 'app/index.html']) {
    if (!existsSync(join(dist, rel))) fail('missing ' + rel);
}
const assetsDir = join(dist, 'assets');
if (!existsSync(assetsDir) || !statSync(assetsDir).isDirectory()) fail('missing assets/');

for (const rel of ['index.html', 'privacy/index.html', '404.html']) {
    const html = readFileSync(join(dist, rel), 'utf8');
    for (const m of html.matchAll(/<script\b([^>]*)>/gi)) {
        if (!/\btype\s*=\s*["']application\/ld\+json["']/i.test(m[1])) {
            fail(rel + ' has a script tag other than application/ld+json');
        }
    }
}

if (existsSync(join(dist, '_headers'))) {
    const rules = parseHeaders(readFileSync(join(dist, '_headers'), 'utf8'));
    const landingPaths = ['/', '/index.html', '/privacy/', '/privacy/index.html', '/404.html'];
    for (const path of landingPaths) {
        const headers = headersFor(rules, path);
        const csp = headers.get('content-security-policy');
        if (csp !== LANDING_CSP) fail(path + ' CSP is ' + JSON.stringify(csp));
        if (csp && /\bscript-src\b/.test(csp)) fail(path + ' CSP includes script-src');
    }
    for (const path of ['/app/', '/app/index.html']) {
        const csp = headersFor(rules, path).get('content-security-policy');
        if (csp !== SPA_CSP) fail(path + ' CSP is ' + JSON.stringify(csp));
    }
    const paths = new Set(['/', '/privacy/', '/app/', '/404.html']);
    if (existsSync(dist)) {
        for (const rel of walk(dist)) paths.add('/' + rel);
    }
    for (const path of paths) {
        const cache = headersFor(rules, path).get('cache-control');
        const want = expectedCache(path);
        if (cache !== want) fail(path + ' Cache-Control is ' + JSON.stringify(cache) + ', want ' + want);
    }
} else {
    fail('missing _headers');
}

if (existsSync(dist)) {
    for (const rel of walk(dist).filter((name) => name.endsWith('.html'))) {
        const html = readFileSync(join(dist, rel), 'utf8');
        for (const url of htmlUrls(html)) {
            const result = resolves(url);
            if (!result.ok) fail(rel + ' internal URL ' + url + ' ' + result.why);
        }
    }
}

if (failures.length) {
    for (const msg of failures) console.error('FAIL  ' + msg);
    console.error(failures.length + ' dist check(s) failed');
    process.exit(1);
}
console.log('dist check passed');
