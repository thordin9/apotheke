import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { PAGE_SECURITY, PERMISSIONS_POLICY, headersFor, offSiteHosts, parseHeaders } from './check-dist.mjs';

const rules = parseHeaders(readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../public/_headers'), 'utf8'));
const pages = ['/', '/privacy/', '/404.html', '/app/', '/app/index.html'];

test('pages set HSTS, nosniff, Referrer-Policy, X-Frame-Options and Permissions-Policy', () => {
    for (const path of pages) {
        const headers = headersFor(rules, path);
        for (const [name, value] of Object.entries(PAGE_SECURITY)) {
            assert.equal(headers.get(name), value, path + ' ' + name);
        }
        assert.equal(headers.get('permissions-policy'), PERMISSIONS_POLICY, path);
    }
});

test('a missing /assets file is not cached as immutable', () => {
    assert.equal(headersFor(rules, '/assets/missing.js').get('cache-control'), 'no-cache');
    assert.equal(headersFor(rules, '/assets/missing.css').get('cache-control'), 'no-cache');
});

test('only a hashed Vite js or css file is immutable', () => {
    assert.equal(headersFor(rules, '/assets/index-Ab12Cd34.js').get('cache-control'), 'public, max-age=31536000, immutable');
    assert.equal(headersFor(rules, '/assets/style-Ab12Cd34.css').get('cache-control'), 'public, max-age=31536000, immutable');
    for (const path of ['/assets/brand/mark.svg', '/assets/mascot/pose-asleep.svg', '/assets/shots/home.png', '/assets/icons.svg']) {
        assert.equal(headersFor(rules, path).get('cache-control'), 'public, max-age=86400', path);
    }
});

test('off-site https subresources fail; anchor links and on-site URLs do not', () => {
    assert.deepEqual(offSiteHosts('<script src="https://cdn.example/app.js"></script>', false), ['cdn.example']);
    assert.deepEqual(offSiteHosts('<link rel="stylesheet" href="https://cdn.example/a.css">', false), ['cdn.example']);
    assert.deepEqual(offSiteHosts('<img src="https://images.example/a.png" alt="">', false), ['images.example']);
    assert.deepEqual(offSiteHosts('<link rel="preload" href="https://fonts.example/a.woff2" as="font">', false), ['fonts.example']);
    assert.deepEqual(offSiteHosts('a{background:url(https://cdn.example/a.png)}', true), ['cdn.example']);
    assert.deepEqual(offSiteHosts('@import "https://cdn.example/a.css";', true), ['cdn.example']);
    assert.deepEqual(offSiteHosts('<a href="https://github.com/thordin9/apotheke">GitHub</a>', false), []);
    assert.deepEqual(offSiteHosts('<code>git clone https://github.com/thordin9/apotheke.git</code>', false), []);
    assert.deepEqual(offSiteHosts('<link rel="canonical" href="https://getapotheke.com/">', false), []);
    assert.deepEqual(offSiteHosts('<script type="application/ld+json">{"license":"https://opensource.org/licenses/MIT"}</script>', false), []);
    assert.deepEqual(offSiteHosts('/* see https://example.com/note */', true), []);
});
