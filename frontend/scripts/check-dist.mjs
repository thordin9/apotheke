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
        const bad = wildcardError(trimmed);
        if (bad) throw new Error(bad);
        current = { pattern: trimmed, ops: [] };
        rules.push(current);
    }
    return rules;
}

function wildcardError(pattern) {
    let stars = 0;
    for (let i = 0; i < pattern.length; i++) if (pattern[i] === '*') stars++;
    if (stars > 1) {
        return 'Only one wildcard is allowed per rule. Use a named placeholder (e.g. :project) instead. Skipping ' + pattern + '.';
    }
    if (stars > 0 && /:splat(?!\w)/.test(pattern)) {
        return 'Cannot combine a wildcard * with a :splat placeholder because wildcards are converted to :splat at runtime. Skipping ' + pattern + '.';
    }
    return '';
}

function isLetter(c) {
    const code = c.charCodeAt(0);
    return (code >= 65 && code <= 90) || (code >= 97 && code <= 122);
}

function isWordChar(c) {
    const code = c.charCodeAt(0);
    return (code >= 48 && code <= 57) || isLetter(c) || c === '_';
}

function patternRegExp(pattern) {
    let body = '';
    let i = 0;
    while (i < pattern.length) {
        const c = pattern[i];
        if (c === '*') {
            body += '(.*)';
            i++;
            continue;
        }
        if (c === ':' && isLetter(pattern[i + 1] || '')) {
            let j = i + 1;
            while (j < pattern.length && isWordChar(pattern[j])) j++;
            const name = pattern.slice(i + 1, j);
            body += name === 'splat' ? '(.*)' : '([^/]+)';
            i = j;
            continue;
        }
        body += escapeRegExp(c);
        i++;
    }
    return new RegExp('^' + body + '$');
}

function matches(pattern, path) {
    return patternRegExp(pattern).test(path);
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

function onSiteHost(host) {
    return host === 'getapotheke.com' || host === 'www.getapotheke.com';
}

function hostFromUrl(url) {
    const trimmed = url.trim();
    const lower = trimmed.toLowerCase();
    let rest = '';
    if (lower.startsWith('https://')) rest = trimmed.slice('https://'.length);
    else if (lower.startsWith('http://')) rest = trimmed.slice('http://'.length);
    else if (lower.startsWith('//')) rest = trimmed.slice(2);
    else return '';
    let end = 0;
    while (end < rest.length) {
        const c = rest[end];
        if (c === '/' || c === '?' || c === '#' || c === '"' || c === "'" || c === ')' || c === ' ' || c === '>' || c === '\\' || c === '\n' || c === '\r' || c === '\t' || c === ',') break;
        end++;
    }
    return rest.slice(0, end).toLowerCase().replace(/:\d+$/, '');
}

function decodeHtmlEntities(text) {
    let out = '';
    for (let i = 0; i < text.length; i++) {
        if (text[i] !== '&') {
            out += text[i];
            continue;
        }
        const semi = text.indexOf(';', i + 1);
        if (semi < 0 || semi - i > 32) {
            out += '&';
            continue;
        }
        const body = text.slice(i + 1, semi);
        let ch = '';
        if (body.startsWith('#')) {
            const hex = body[1] === 'x' || body[1] === 'X';
            const digits = hex ? body.slice(2) : body.slice(1);
            const ok = digits.length > 0 && (hex ? /^[0-9a-f]+$/i.test(digits) : /^[0-9]+$/.test(digits));
            if (ok) {
                const code = parseInt(digits, hex ? 16 : 10);
                if (code > 0 && code <= 0x10ffff && (code < 0xd800 || code > 0xdfff)) ch = String.fromCodePoint(code);
            }
        } else if (body === 'amp') ch = '&';
        else if (body === 'lt') ch = '<';
        else if (body === 'gt') ch = '>';
        else if (body === 'quot') ch = '"';
        else if (body === 'apos') ch = "'";
        else if (body === 'colon') ch = ':';
        else if (body === 'sol') ch = '/';
        if (!ch) {
            out += '&';
            continue;
        }
        out += ch;
        i = semi;
    }
    return out;
}

function isHex(c) {
    const code = c.charCodeAt(0);
    return (code >= 48 && code <= 57) || (code >= 65 && code <= 70) || (code >= 97 && code <= 102);
}

function decodeCssEscapes(text) {
    let out = '';
    for (let i = 0; i < text.length; i++) {
        if (text[i] !== '\\' || i + 1 >= text.length) {
            out += text[i];
            continue;
        }
        const n = text[i + 1];
        if (n === '\n') {
            i++;
            continue;
        }
        if (n === '\r') {
            i++;
            if (text[i + 1] === '\n') i++;
            continue;
        }
        if (isHex(n)) {
            let j = i + 1;
            let hex = '';
            while (j < text.length && hex.length < 6 && isHex(text[j])) {
                hex += text[j];
                j++;
            }
            const code = parseInt(hex, 16);
            if (code > 0 && code <= 0x10ffff && (code < 0xd800 || code > 0xdfff)) out += String.fromCodePoint(code);
            else out += '\uFFFD';
            if (text[j] === ' ' || text[j] === '\t' || text[j] === '\n') j++;
            else if (text[j] === '\r') {
                j++;
                if (text[j] === '\n') j++;
            }
            i = j - 1;
            continue;
        }
        out += n;
        i++;
    }
    return out;
}

function isSpace(c) {
    return c === ' ' || c === '\t' || c === '\n' || c === '\r';
}

function isNameChar(c) {
    const code = c.charCodeAt(0);
    return (code >= 48 && code <= 57) || (code >= 65 && code <= 90) || (code >= 97 && code <= 122) || c === '_' || c === ':' || c === '-';
}

function isIdentChar(c) {
    if (!c) return false;
    const code = c.charCodeAt(0);
    return (code >= 48 && code <= 57) || (code >= 65 && code <= 90) || (code >= 97 && code <= 122) || c === '_' || c === '-';
}

function tagNameOf(tag) {
    let i = 1;
    if (tag[i] === '/') i++;
    const start = i;
    while (i < tag.length && isNameChar(tag[i])) i++;
    return tag.slice(start, i).toLowerCase();
}

const RESOURCE_TAGS = new Set(['script', 'link', 'img', 'source', 'video', 'audio', 'iframe', 'embed', 'object', 'track', 'image', 'use']);
const RESOURCE_ATTRS = new Set(['src', 'href', 'srcset', 'imagesrcset', 'poster', 'data']);
const LINK_ATTRS = new Set(['href', 'src', 'srcset', 'imagesrcset']);

function srcsetCandidates(value) {
    const urls = [];
    for (const part of value.split(',')) {
        const url = part.trim().split(/\s+/)[0];
        if (url) urls.push(url);
    }
    return urls;
}

function tagEnd(html, open) {
    let quote = '';
    for (let i = open + 1; i < html.length; i++) {
        const c = html[i];
        if (quote) {
            if (c === quote) quote = '';
            continue;
        }
        if (c === '"' || c === "'") {
            quote = c;
            continue;
        }
        if (c === '>') return i;
    }
    return -1;
}

function attributes(tag) {
    const attrs = [];
    let i = 1;
    while (i < tag.length && !isSpace(tag[i]) && tag[i] !== '>' && tag[i] !== '/') i++;
    while (i < tag.length) {
        while (i < tag.length && isSpace(tag[i])) i++;
        if (i >= tag.length || tag[i] === '>' || tag[i] === '/') break;
        const nameStart = i;
        while (i < tag.length && isNameChar(tag[i])) i++;
        const name = tag.slice(nameStart, i).toLowerCase();
        while (i < tag.length && isSpace(tag[i])) i++;
        if (tag[i] !== '=') continue;
        i++;
        while (i < tag.length && isSpace(tag[i])) i++;
        let value = '';
        if (tag[i] === '"' || tag[i] === "'") {
            const q = tag[i];
            i++;
            const start = i;
            while (i < tag.length && tag[i] !== q) i++;
            value = tag.slice(start, i);
            if (tag[i] === q) i++;
        } else {
            const start = i;
            while (i < tag.length && !isSpace(tag[i]) && tag[i] !== '>') i++;
            value = tag.slice(start, i);
        }
        if (name) attrs.push({ name, value });
    }
    return attrs;
}

function eachStartTag(html, fn) {
    let i = 0;
    while (i < html.length) {
        const open = html.indexOf('<', i);
        if (open < 0) break;
        const next = html[open + 1];
        if (!next || next === '/' || next === '!' || next === '?') {
            i = open + 1;
            continue;
        }
        const end = tagEnd(html, open);
        if (end < 0) break;
        const skipTo = fn(html.slice(open, end + 1), end);
        i = typeof skipTo === 'number' ? skipTo : end + 1;
    }
}

function readCssUrl(text, paren) {
    let i = paren + 1;
    while (i < text.length && isSpace(text[i])) i++;
    let quote = '';
    if (text[i] === '"' || text[i] === "'") {
        quote = text[i];
        i++;
    }
    const start = i;
    if (quote) {
        while (i < text.length && text[i] !== quote) i++;
        const url = text.slice(start, i);
        if (text[i] === quote) i++;
        while (i < text.length && text[i] !== ')') i++;
        return { url, end: i };
    }
    while (i < text.length && text[i] !== ')') i++;
    return { url: text.slice(start, i).trim(), end: i };
}

function readBalanced(text, paren) {
    let depth = 0;
    let quote = '';
    for (let i = paren; i < text.length; i++) {
        const c = text[i];
        if (quote) {
            if (c === quote) quote = '';
            continue;
        }
        if (c === '"' || c === "'") {
            quote = c;
            continue;
        }
        if (c === '(') depth++;
        else if (c === ')') {
            depth--;
            if (depth === 0) return { start: paren + 1, end: i };
        }
    }
    return { start: paren + 1, end: text.length };
}

function urlsInImageSet(text, start, end) {
    const urls = [];
    const lower = text.toLowerCase();
    let i = start;
    while (i < end) {
        if (lower.startsWith('url(', i) && !isIdentChar(text[i - 1])) {
            const got = readCssUrl(text, i + 3);
            urls.push(got.url);
            i = Math.max(got.end + 1, i + 4);
            continue;
        }
        if (text[i] === '"' || text[i] === "'") {
            const q = text[i];
            let j = i + 1;
            while (j < end && text[j] !== q) j++;
            urls.push(text.slice(i + 1, j).trim());
            i = j + 1;
            continue;
        }
        i++;
    }
    return urls;
}

function cssResourceUrls(text) {
    const decoded = decodeCssEscapes(text);
    const urls = [];
    const lower = decoded.toLowerCase();
    let i = 0;
    while (i < decoded.length) {
        const urlAt = lower.indexOf('url(', i);
        const setAt = lower.indexOf('image-set(', i);
        const importAt = lower.indexOf('@import', i);
        let kind = '';
        let at = -1;
        if (urlAt >= 0) {
            kind = 'url';
            at = urlAt;
        }
        if (setAt >= 0 && (at < 0 || setAt < at)) {
            kind = 'set';
            at = setAt;
        }
        if (importAt >= 0 && (at < 0 || importAt < at)) {
            kind = 'import';
            at = importAt;
        }
        if (at < 0) break;
        if (kind === 'url') {
            if (isIdentChar(decoded[at - 1])) {
                i = at + 4;
                continue;
            }
            const got = readCssUrl(decoded, at + 3);
            urls.push(got.url);
            i = Math.max(got.end + 1, at + 4);
            continue;
        }
        if (kind === 'set') {
            if (isIdentChar(decoded[at - 1])) {
                i = at + 10;
                continue;
            }
            const body = readBalanced(decoded, at + 9);
            urls.push(...urlsInImageSet(decoded, body.start, body.end));
            i = Math.max(body.end + 1, at + 10);
            continue;
        }
        let j = at + 7;
        while (j < decoded.length && isSpace(decoded[j])) j++;
        if (lower.startsWith('url(', j) && !isIdentChar(decoded[j - 1])) {
            const got = readCssUrl(decoded, j + 3);
            urls.push(got.url);
            i = Math.max(got.end + 1, j + 4);
            continue;
        }
        if (decoded[j] === '"' || decoded[j] === "'") {
            const q = decoded[j];
            const close = decoded.indexOf(q, j + 1);
            urls.push(decoded.slice(j + 1, close < 0 ? decoded.length : close));
            i = (close < 0 ? decoded.length : close) + 1;
            continue;
        }
        i = at + 7;
    }
    return urls;
}

function htmlResourceUrls(html) {
    const urls = [];
    eachStartTag(html, (tag, end) => {
        const name = tagNameOf(tag);
        if (name === 'style') {
            const lower = html.toLowerCase();
            const close = lower.indexOf('</style', end + 1);
            const bodyEnd = close < 0 ? html.length : close;
            urls.push(...cssResourceUrls(decodeHtmlEntities(html.slice(end + 1, bodyEnd))));
            return close < 0 ? html.length : close;
        }
        for (const attr of attributes(tag)) {
            if (attr.name === 'style') {
                urls.push(...cssResourceUrls(decodeHtmlEntities(attr.value)));
                continue;
            }
            if (!RESOURCE_TAGS.has(name) || !RESOURCE_ATTRS.has(attr.name)) continue;
            const value = decodeHtmlEntities(attr.value);
            if (attr.name === 'srcset' || attr.name === 'imagesrcset') {
                for (const candidate of srcsetCandidates(value)) urls.push(candidate);
            } else urls.push(value.trim());
        }
    });
    return urls;
}

// Hosts of off-site subresources. An <a href> and JSON-LD text are not subresources.
// getapotheke.com is this site.
export function offSiteHosts(text, css) {
    const found = [];
    const urls = css ? cssResourceUrls(text) : htmlResourceUrls(text);
    for (const url of urls) {
        const host = hostFromUrl(url);
        if (host && !onSiteHost(host)) found.push(host);
    }
    return found;
}

function checkOffSite(rel, text, css) {
    for (const host of offSiteHosts(text, css)) fail(rel + ' references off-site ' + host);
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
    eachStartTag(html, (tag) => {
        for (const attr of attributes(tag)) {
            if (!LINK_ATTRS.has(attr.name)) continue;
            const value = decodeHtmlEntities(attr.value);
            if (attr.name === 'srcset' || attr.name === 'imagesrcset') {
                for (const candidate of srcsetCandidates(value)) urls.push(candidate);
            } else urls.push(value.trim());
        }
    });
    return urls;
}

let spaMount = '/app';

export function resolves(url) {
    const trimmed = String(url || '').trim();
    if (/^http:/i.test(trimmed)) return { ok: false, why: 'not https' };
    const path = trimmed.split('#')[0].split('?')[0];
    if (path === '' || /^(https:|mailto:)/i.test(path)) return { ok: true };
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
