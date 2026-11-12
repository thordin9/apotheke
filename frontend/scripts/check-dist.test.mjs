import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { PAGE_SECURITY, PERMISSIONS_POLICY, connectSrcHas, expectedCache, headersFor, offSiteHosts, pageUrl, parseHeaders, resolves } from './check-dist.mjs';

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

test('the API host is only in the app connect-src', () => {
    const api = 'https://api.services.getapotheke.com';
    for (const path of ['/', '/privacy/', '/404.html']) {
        const csp = headersFor(rules, path).get('content-security-policy') || '';
        assert.equal(connectSrcHas(csp, api), false, path);
    }
    for (const path of ['/app/', '/app/index.html']) {
        const csp = headersFor(rules, path).get('content-security-policy') || '';
        assert.equal(connectSrcHas(csp, api), true, path);
    }
    assert.equal(connectSrcHas("connect-src https://not" + api.slice('https://'.length), api), false);
});

test('a missing /assets file is not cached as immutable', () => {
    assert.equal(headersFor(rules, '/assets/missing.js').get('cache-control'), 'no-cache');
    assert.equal(headersFor(rules, '/assets/missing.css').get('cache-control'), 'no-cache');
});

test('two wildcards are rejected and a placeholder matches a hashed name', () => {
    assert.throws(
        () => parseHeaders('/assets/*-*.js\n  Cache-Control: public, max-age=31536000, immutable\n'),
        /Only one wildcard is allowed/
    );
    assert.throws(
        () => parseHeaders('/assets/*/:splat.js\n  Cache-Control: no-cache\n'),
        /:splat/
    );
    const hashed = parseHeaders(
        '/assets/:name-:hash.js\n  Cache-Control: public, max-age=31536000, immutable\n'
        + '/assets/:name-:hash.css\n  Cache-Control: public, max-age=31536000, immutable\n'
    );
    assert.equal(headersFor(hashed, '/assets/index-Ab12Cd34.js').get('cache-control'), 'public, max-age=31536000, immutable');
    assert.equal(headersFor(hashed, '/assets/style-Ab12Cd34.css').get('cache-control'), 'public, max-age=31536000, immutable');
    assert.equal(headersFor(hashed, '/assets/missing.js').get('cache-control'), undefined);
    assert.equal(headersFor(hashed, '/assets/a/b-c.js').get('cache-control'), undefined);
});

test('only a hashed Vite js or css file is immutable', () => {
    assert.equal(headersFor(rules, '/assets/index-Ab12Cd34.js').get('cache-control'), 'public, max-age=31536000, immutable');
    assert.equal(headersFor(rules, '/assets/style-Ab12Cd34.css').get('cache-control'), 'public, max-age=31536000, immutable');
    assert.equal(headersFor(rules, '/assets/a/b-c.js').get('cache-control'), 'no-cache');
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

test('every srcset and imagesrcset candidate is checked', () => {
    assert.deepEqual(offSiteHosts('<img srcset="/a.png 1x, https://cdn.example/b.png 2x">', false), ['cdn.example']);
    assert.deepEqual(
        offSiteHosts('<link imagesrcset="/a.png 1x, //cdn.example/b.png 2x, https://other.example/c.png 3x">', false),
        ['cdn.example', 'other.example']
    );
});

test('http and protocol-relative URLs are off-site unless the host is this site', () => {
    assert.deepEqual(offSiteHosts('<script src="http://cdn.example/app.js"></script>', false), ['cdn.example']);
    assert.deepEqual(offSiteHosts('<img src="//cdn.example/a.png">', false), ['cdn.example']);
    assert.deepEqual(offSiteHosts('<img src="http://getapotheke.com/a.png">', false), []);
    assert.deepEqual(offSiteHosts('<img src="//www.getapotheke.com/a.png">', false), []);
    assert.deepEqual(offSiteHosts('<a href="http://github.com/thordin9/apotheke">GitHub</a>', false), []);
    assert.deepEqual(offSiteHosts('<a href="//github.com/thordin9/apotheke">GitHub</a>', false), []);
});

test('CSS url and image-set off-site references are checked', () => {
    assert.deepEqual(offSiteHosts('a{background:url(//cdn.example/a.png)}', true), ['cdn.example']);
    assert.deepEqual(offSiteHosts('a{background:url(http://cdn.example/a.png)}', true), ['cdn.example']);
    assert.deepEqual(offSiteHosts('a{background:image-set("https://cdn.example/a.png" 1x)}', true), ['cdn.example']);
    assert.deepEqual(offSiteHosts('a{background:image-set(url(//cdn.example/a.png) 1x)}', true), ['cdn.example']);
    assert.deepEqual(offSiteHosts('a{background:image-set(url("https://cdn.example/a.png"))}', true), ['cdn.example']);
    assert.deepEqual(offSiteHosts('a{background:url(https://getapotheke.com/a.png)}', true), []);
});

test('decoded resource URLs resolve to their host', () => {
    const host = 'cdn.example';
    assert.deepEqual(offSiteHosts('<img src="' + String.raw`https:\\\\${host}/x` + '">', false), [host]);
    assert.deepEqual(offSiteHosts('<video poster="' + String.raw`\\\\${host}/x` + '">', false), [host]);
    assert.deepEqual(offSiteHosts('a{background:' + String.raw`url(/\5c ${host}/x)` + '}', true), [host]);
    assert.deepEqual(offSiteHosts('a{background:' + String.raw`url("\\\\\\\\${host}/x")` + '}', true), [host]);
    assert.deepEqual(offSiteHosts('a{background:' + String.raw`url("ht\9 tps://${host}/x")` + '}', true), [host]);
});

test('encoded schemes in attributes and stylesheets are checked', () => {
    assert.deepEqual(offSiteHosts('<img src="https&#58;//cdn.example/a.png">', false), ['cdn.example']);
    assert.deepEqual(offSiteHosts('<script src="&#104;ttps://cdn.example/a.js"></script>', false), ['cdn.example']);
    assert.deepEqual(offSiteHosts('a{background:url(\\68 ttps://cdn.example/a.png)}', true), ['cdn.example']);
    assert.deepEqual(offSiteHosts('a{background:url(https\\3a //cdn.example/a.png)}', true), ['cdn.example']);
});

test('resolves rejects http and allows https and mailto', () => {
    assert.equal(resolves('http://cdn.example/a.png').ok, false);
    assert.equal(resolves('HTTP://cdn.example/a.png').ok, false);
    assert.equal(resolves('https://getapotheke.com/').ok, true);
    assert.equal(resolves('mailto:ops@example').ok, true);
});

test('pageUrl is the public address of a built file', () => {
    assert.equal(pageUrl('index.html'), 'https://getapotheke.com/');
    assert.equal(pageUrl('privacy/index.html'), 'https://getapotheke.com/privacy/');
    assert.equal(pageUrl('404.html'), 'https://getapotheke.com/404.html');
});

test('a link to another host must name https', () => {
    const from = pageUrl('privacy/index.html');
    for (const url of [
        '//getapotheke.com/privacy/',
        '//www.getapotheke.com/privacy/',
        '\\\\getapotheke.com/privacy/',
        '/\\getapotheke.com/privacy/',
        'https:\\\\getapotheke.com/privacy/',
        '//getapotheke.com:8443/privacy/',
        '//www.getapotheke.com:8443/privacy/',
        '//getapotheke%2ecom/privacy/',
        '//www%2egetapotheke.com/privacy/',
        '//%67etapotheke.com/privacy/',
        '//cdn.example/x',
        '\\\\cdn.example/x',
        '/\\cdn.example/x',
        '/\t/getapotheke.com/privacy/',
        '/\r/getapotheke.com/privacy/',
        '/\n/getapotheke.com/privacy/',
        '/\t\n\r/getapotheke.com/privacy/',
        '/\tprivacy/',
        'ht\ttps://cdn.example/x',
        'ht\t\tps://cdn.example/x',
        'https://getapotheke.com/pri\tvacy/',
        'https://getapotheke%2Ecom/',
        'https://getapotheke%2ecom/privacy/',
        'HTTPS://getapotheke%2Ecom/',
    ]) {
        assert.equal(resolves(url, from).ok, false, url);
    }
    assert.equal(resolves('https://cdn.example/x', from).ok, true);
    assert.equal(resolves('/privacy/', from).ok, true);
    assert.equal(resolves('\t/privacy/', from).ok, true);
    assert.equal(resolves('  /privacy/\n', from).ok, true);
    const interior = resolves('/\tprivacy/', from);
    assert.equal(interior.ok, false);
    assert.equal(interior.why, 'contains a tab or line break');
    assert.equal(resolves('/\t/getapotheke.com/privacy/', from).why, 'contains a tab or line break');
    assert.equal(resolves('https://getapotheke.com/privacy/', from).ok, true);
    assert.equal(resolves('https://GETAPOTHEKE.com/', from).ok, true);
    assert.equal(resolves('https://getapotheke.com:8443/', from).ok, true);
    assert.equal(resolves('https://getapotheke.com:8443/missing-page', from).ok, true);
    assert.equal(resolves('https://getapotheke.com/missing-page', from).ok, false);
    const query = resolves('https://getapotheke.com/missing-page?q=\\', from);
    assert.equal(query.ok, false);
    assert.equal(query.why, 'missing in dist');
    assert.equal(resolves('https://getapotheke.com/privacy/?q=\\', from).ok, true);
    assert.equal(resolves('https://getapotheke.com/privacy/#a\\b', from).ok, true);
});

test('a stylesheet may use a data URL', () => {
    assert.equal(resolves('data:image/svg+xml,x', pageUrl('assets/x.css')).ok, true);
    assert.equal(resolves('data:image/svg+xml,x', 'https://getapotheke.com/assets/x.CSS').ok, true);
    assert.equal(resolves('data:image/svg+xml,x', pageUrl('index.html')).ok, false);
    assert.equal(resolves('data:image/svg+xml,x', 'https://getapotheke.com/privacy/?file=.css').ok, false);
});

test('relative links resolve from the page that contains them', () => {
    const from = 'https://getapotheke.com/privacy/';
    assert.equal(resolves('privacy/', from).ok, false);
    assert.equal(resolves('./x', from).ok, false);
    assert.equal(resolves('../x', from).ok, false);
    assert.equal(resolves('../index.html', from).ok, true);
    assert.equal(resolves('../privacy/', from).ok, true);
});

test('a look-alike host stays off-site', () => {
    for (const host of ['getapotheke.com.example', 'getapotheke.co', 'xgetapotheke.com']) {
        assert.deepEqual(offSiteHosts('<img src="https://' + host + '/a.png">', false), [host]);
    }
    assert.deepEqual(offSiteHosts('<img src="https://getapotheke.com/a.png">', false), []);
    assert.deepEqual(offSiteHosts('<img src="https://www.getapotheke.com/a.png">', false), []);
});

test('a nested asset path is not an immutable cache', () => {
    assert.equal(expectedCache('/assets/a/b-c.js'), 'no-cache');
    assert.equal(expectedCache('/assets/index-Ab12Cd34.js'), 'public, max-age=31536000, immutable');
});
