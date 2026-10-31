#!/usr/bin/env node
// Checks frontend/dist after `npm run build`. Dependency-free (plain Node 22).
//   node frontend/scripts/check-dist.mjs
// Mirrors Cloudflare _headers: matching rules are inherited, a repeated header
// is joined with a comma, and `! Header-Name` detaches the header so far.

import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { appFallback } from './app-fallback.mjs';

const frontend = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(frontend, 'dist');
const failures = [];
const fail = (msg) => failures.push(msg);

const LANDING_CSP = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'";
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

export const PAGE_SECURITY = {
    'strict-transport-security': 'max-age=31536000',
    'x-content-type-options': 'nosniff',
    'referrer-policy': 'strict-origin-when-cross-origin',
    'x-frame-options': 'DENY',
};
export const PERMISSIONS_POLICY = "camera=(), microphone=(), geolocation=(), interest-cohort=(), accelerometer=(), gyroscope=(), magnetometer=(), payment=(), usb=(), bluetooth=(), serial=(), hid=(), midi=(), display-capture=(), browsing-topics=()";

function expectedCache(path) {
    if (path === '/assets/icons.svg' || path.startsWith('/assets/brand/') || path.startsWith('/assets/mascot/') || path.startsWith('/assets/shots/')) {
        return 'public, max-age=86400';
    }
    // Only Vite's hashed name-hash.js / name-hash.css files. A missing /assets/missing.js does not match.
    if (/^\/assets\/[^/]+-[^/]+\.(?:js|css)$/.test(path)) return 'public, max-age=31536000, immutable';
    return 'no-cache';
}

function httpsHost(text, at) {
    const start = at + 'https://'.length;
    let end = start;
    while (end < text.length) {
        const c = text[end];
        if (c === '/' || c === '?' || c === '#' || c === '"' || c === "'" || c === ')' || c === ' ' || c === '>' || c === '\\' || c === '\n' || c === '\r' || c === '\t') break;
        end++;
    }
    return text.slice(start, end).toLowerCase().replace(/:\d+$/, '');
}

function tagAround(text, index) {
    const open = text.lastIndexOf('<', index);
    if (open < 0) return '';
    const close = text.indexOf('>', open);
    if (close < 0 || close < index) return '';
    return text.slice(open, close + 1);
}

function insideJsonLd(html, index) {
    const lower = html.toLowerCase();
    let from = 0;
    while (from < index) {
        const open = lower.indexOf('<script', from);
        if (open < 0 || open > index) return false;
        const openEnd = html.indexOf('>', open);
        const close = lower.indexOf('</script', openEnd < 0 ? open : openEnd);
        if (openEnd > 0 && close > openEnd && index > openEnd && index < close) {
            return lower.slice(open, openEnd).includes('ld+json');
        }
        from = open + 7;
    }
    return false;
}

function onSiteHost(host) {
    return host === 'getapotheke.com' || host === 'www.getapotheke.com';
}

function isSpace(c) {
    return c === ' ' || c === '\t' || c === '\n' || c === '\r';
}

function isNameChar(c) {
    const code = c.charCodeAt(0);
    return (code >= 48 && code <= 57) || (code >= 65 && code <= 90) || (code >= 97 && code <= 122) || c === '_' || c === ':' || c === '-';
}

function looksLikeUrlFn(text, at) {
    let i = at - 1;
    while (i >= 0 && (isSpace(text[i]) || text[i] === '"' || text[i] === "'")) i--;
    if (text[i] !== '(') return false;
    i--;
    while (i >= 0 && (text[i] === ' ' || text[i] === '\t')) i--;
    return i >= 2 && text.slice(i - 2, i + 1).toLowerCase() === 'url';
}

function looksLikeImport(text, at) {
    let i = at - 1;
    while (i >= 0 && (isSpace(text[i]) || text[i] === '"' || text[i] === "'")) i--;
    return i >= 6 && text.slice(i - 6, i + 1).toLowerCase() === '@import';
}

function attrNameBefore(text, at) {
    let i = at - 1;
    while (i >= 0 && (isSpace(text[i]) || text[i] === '"' || text[i] === "'")) i--;
    if (text[i] !== '=') return '';
    i--;
    while (i >= 0 && (text[i] === ' ' || text[i] === '\t')) i--;
    const end = i + 1;
    while (i >= 0 && isNameChar(text[i])) i--;
    return text.slice(i + 1, end).toLowerCase();
}

function tagNameOf(tag) {
    let i = 1;
    if (tag[i] === '/') i++;
    const start = i;
    while (i < tag.length && isNameChar(tag[i])) i++;
    return tag.slice(start, i).toLowerCase();
}

const RESOURCE_TAGS = new Set(['script', 'link', 'img', 'source', 'video', 'audio', 'iframe', 'embed', 'object', 'track', 'image', 'use']);
const RESOURCE_ATTRS = new Set(['src', 'href', 'srcset', 'poster', 'data']);

function isResourceRef(text, at, css) {
    if (looksLikeUrlFn(text, at) || looksLikeImport(text, at)) {
        if (css) return true;
        return !insideJsonLd(text, at);
    }
    if (css) return false;
    if (insideJsonLd(text, at)) return false;
    const tag = tagAround(text, at);
    if (tag === '' || tagNameOf(tag) === 'a') return false;
    if (!RESOURCE_TAGS.has(tagNameOf(tag))) return false;
    return RESOURCE_ATTRS.has(attrNameBefore(text, at));
}

// Hosts of https: subresources (script, style, img, font, link, url(), @import).
// An <a href> and JSON-LD text are not subresources. getapotheke.com is this site.
export function offSiteHosts(text, css) {
    const found = [];
    const lower = text.toLowerCase();
    let from = 0;
    while (from < text.length) {
        const at = lower.indexOf('https://', from);
        if (at < 0) break;
        const host = httpsHost(text, at);
        if (host && !onSiteHost(host) && isResourceRef(text, at, css)) found.push(host);
        from = at + 8;
    }
    return found;
}

function checkOffSite(rel, text, css) {
    for (const host of offSiteHosts(text, css)) fail(rel + ' references off-site https://' + host);
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

let spaMount = '/app';

function resolves(url) {
    const path = url.split('#')[0].split('?')[0];
    if (path === '' || /^(https?:|mailto:)/i.test(path)) return { ok: true };
    if (path.startsWith('//') || !path.startsWith('/')) return { ok: false, why: 'not a root-absolute path' };
    const decision = appFallback(path, spaMount.endsWith('/') ? spaMount : spaMount + '/');
    if (decision.kind === 'shell' || decision.kind === 'redirect') {
        const shell = join(dist, 'app/index.html');
        const ok = existsSync(shell) && statSync(shell).isFile();
        return { ok, why: ok ? '' : 'missing app shell' };
    }
    const rel = path.replace(/^\/+/, '');
    const candidates = rel === '' ? [join(dist, 'index.html')] : [join(dist, rel), join(dist, rel, 'index.html')];
    const found = candidates.some((file) => {
        return existsSync(file) && statSync(file).isFile() && (file === dist || file.startsWith(dist + '/'));
    });
    return { ok: found, why: found ? '' : 'missing in dist' };
}

function scriptOpenAt(html, index) {
    if (html.slice(index, index + 7).toLowerCase() !== '<script') return false;
    const next = html[index + 7];
    return next === '>' || next === '/' || next === ' ' || next === '\n' || next === '\t' || next === '\r';
}

function checkScripts(rel, external) {
    const html = readFileSync(join(dist, rel), 'utf8');
    const lower = html.toLowerCase();
    let files = 0;
    let index = 0;
    while (index < html.length) {
        const start = lower.indexOf('<script', index);
        if (start < 0) break;
        if (!scriptOpenAt(html, start)) {
            index = start + 7;
            continue;
        }
        const openEnd = html.indexOf('>', start);
        const close = lower.indexOf('</script', start + 7);
        if (openEnd < 0 || close < 0 || close < openEnd) {
            fail(rel + ' has a script tag that does not end');
            break;
        }
        const attrs = html.slice(start + 7, openEnd);
        const body = html.slice(openEnd + 1, close).trim();
        const type = attrs.toLowerCase();
        if (type.includes('application/ld+json')) {
            if (type.includes('src')) fail(rel + ' JSON-LD must stay inline');
        } else if (!type.includes('src') || body !== '') {
            fail(rel + ' has an inline script');
        } else {
            files += 1;
        }
        const closeEnd = html.indexOf('>', close);
        index = closeEnd < 0 ? close + 9 : closeEnd + 1;
    }
    if (files !== external) fail(rel + ' has ' + files + ' external scripts, want ' + external);
}

function sliceBlock(html, tag) {
    const open = '<' + tag;
    const close = '</' + tag + '>';
    const start = html.indexOf(open);
    const end = html.indexOf(close, start);
    if (start < 0 || end < 0) return '';
    return html.slice(start, end + close.length);
}

function innerNav(header) {
    const open = '<ul class="slab-actions">';
    const start = header.indexOf(open);
    const end = header.indexOf('</ul>', start);
    if (start < 0 || end < 0) return '';
    return header.slice(start, end + 5);
}

function withoutNav(header) {
    const nav = innerNav(header);
    if (!nav) return '';
    return header.replace(nav, '');
}

function checkChrome() {
    const pages = ['index.html', 'privacy/index.html', '404.html', 'app/index.html'];
    const htmls = [];
    for (const rel of pages) {
        const file = join(dist, rel);
        if (!existsSync(file)) return;
        htmls.push({ rel, html: readFileSync(file, 'utf8') });
    }
    if (htmls.length !== pages.length) return;
    const footers = htmls.map((page) => sliceBlock(page.html, 'footer'));
    const headers = htmls.map((page) => sliceBlock(page.html, 'header'));
    if (footers.some((footer) => footer === '')) fail('a page is missing its footer');
    for (const footer of footers) {
        if (footer !== footers[0]) fail('footers differ');
    }
    if (!footers[0].includes('© 2026 Stathis Papandreou')) fail('footer lost the operator line');
    if (!footers[0].includes('href="/privacy/"') || !footers[0].includes('>Privacy<')) fail('footer lost Privacy');
    const shells = headers.map(withoutNav);
    if (shells.some((shell) => shell === '')) fail('a page is missing the shared header');
    for (const shell of shells) {
        if (shell !== shells[0]) fail('header chrome differs aside from the nav');
    }
    const marketing = htmls.filter((page) => page.rel !== 'app/index.html').map((page) => innerNav(sliceBlock(page.html, 'header')));
    for (const nav of marketing) {
        if (nav !== marketing[0]) fail('landing, privacy and 404 headers differ');
    }
    if (!marketing[0].includes('>Open the app<') || !marketing[0].includes('href="/app/"') || !marketing[0].includes('href="/app/sign-in"')) {
        fail('marketing nav is missing Open the app or Sign in');
    }
    if (marketing[0].includes('>Privacy<')) fail('marketing header still offers Privacy');
    for (const header of headers) {
        if (header.includes('href="/privacy/"')) fail('a header still links to Privacy');
    }
    const appNav = innerNav(sliceBlock(htmls[3].html, 'header'));
    if (!appNav.includes('href="/"') || !appNav.includes('>Home<')) fail('app nav is missing Home');
    if (appNav.includes('>Privacy<') || appNav.includes('href="/privacy/"')) fail('app nav still offers Privacy');
    if (appNav.includes('Open the app')) fail('app nav still offers Open the app');
    for (const page of htmls) {
        if (page.html.includes('<!-- apotheke:')) fail(page.rel + ' still has an uninjected partial marker');
    }
    const appHtml = htmls[3].html;
    const key = 'data-spa-base="';
    const at = appHtml.indexOf(key);
    const base = at < 0 ? '' : appHtml.slice(at + key.length, appHtml.indexOf('"', at + key.length));
    spaMount = base.replace(/\/$/, '') || '/app';
    if (base === '') fail('app shell is missing data-spa-base');
    if (!appHtml.includes('href="https://getapotheke.com' + base + '"')) fail('app canonical does not use the SPA base');
}

function run() {
    if (!existsSync(dist)) fail('frontend/dist is missing (run npm run build)');
    for (const rel of ['index.html', 'privacy/index.html', '404.html', 'app/index.html']) {
        if (!existsSync(join(dist, rel))) fail('missing ' + rel);
    }
    const assetsDir = join(dist, 'assets');
    if (!existsSync(assetsDir) || !statSync(assetsDir).isDirectory()) fail('missing assets/');
    if (existsSync(join(dist, 'index.html'))) checkScripts('index.html', 1);
    if (existsSync(join(dist, 'privacy/index.html'))) checkScripts('privacy/index.html', 1);
    if (existsSync(join(dist, 'app/index.html'))) checkScripts('app/index.html', 1);
    if (existsSync(join(dist, '404.html'))) checkScripts('404.html', 1);
    checkChrome();

    if (existsSync(join(dist, '_headers'))) {
        const rules = parseHeaders(readFileSync(join(dist, '_headers'), 'utf8'));
        const landingPaths = ['/', '/index.html', '/privacy/', '/privacy/index.html', '/404.html'];
        for (const path of landingPaths) {
            const headers = headersFor(rules, path);
            const csp = headers.get('content-security-policy');
            if (csp !== LANDING_CSP) fail(path + ' CSP is ' + JSON.stringify(csp));
            if (csp && (csp.match(/script-src/g) || []).length !== 1) fail(path + ' CSP must name script-src once');
        }
        for (const path of ['/app/', '/app/index.html']) {
            const csp = headersFor(rules, path).get('content-security-policy');
            if (csp !== SPA_CSP) fail(path + ' CSP is ' + JSON.stringify(csp));
        }
        for (const path of ['/', '/privacy/', '/404.html', '/app/', '/app/index.html']) {
            const headers = headersFor(rules, path);
            for (const [name, value] of Object.entries(PAGE_SECURITY)) {
                if (headers.get(name) !== value) fail(path + ' ' + name + ' is ' + JSON.stringify(headers.get(name)));
            }
            if (headers.get('permissions-policy') !== PERMISSIONS_POLICY) {
                fail(path + ' Permissions-Policy is ' + JSON.stringify(headers.get('permissions-policy')));
            }
        }
        const missingAsset = headersFor(rules, '/assets/missing.js').get('cache-control');
        if (!missingAsset || missingAsset.includes('immutable')) {
            fail('a missing /assets file is cached as ' + JSON.stringify(missingAsset));
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
        for (const rel of walk(dist).filter((name) => name.endsWith('.html') || name.endsWith('.css'))) {
            const body = readFileSync(join(dist, rel), 'utf8');
            checkOffSite(rel, body, rel.endsWith('.css'));
            if (!rel.endsWith('.html')) continue;
            const html = body;
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
}

const entry = process.argv[1];
if (entry && import.meta.url === pathToFileURL(entry).href) run();
