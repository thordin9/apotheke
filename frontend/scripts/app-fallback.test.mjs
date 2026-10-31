import assert from 'node:assert/strict';
import { test } from 'node:test';
import { rewriteAppShell } from './app-fallback.mjs';

function run(url) {
    const req = { url };
    const res = {
        statusCode: 0,
        headers: {},
        ended: false,
        setHeader(name, value) {
            this.headers[name.toLowerCase()] = value;
        },
        end() {
            this.ended = true;
        },
    };
    let next = false;
    rewriteAppShell(req, res, () => {
        next = true;
    });
    return { req, res, next };
}

test('/app redirects to /app/', () => {
    const { res, next } = run('/app');
    assert.equal(next, false);
    assert.equal(res.ended, true);
    assert.equal(res.statusCode, 301);
    assert.equal(res.headers.location, '/app/');
    const withQuery = run('/app?from=nav');
    assert.equal(withQuery.res.headers.location, '/app/?from=nav');
});

test('/app/ is the shell', () => {
    const { req, res, next } = run('/app/');
    assert.equal(next, true);
    assert.equal(res.ended, false);
    assert.equal(req.url, '/app/index.html');
});

test('/app/sign-in is the shell', () => {
    const { req, next } = run('/app/sign-in');
    assert.equal(next, true);
    assert.equal(req.url, '/app/index.html');
});

test('an unknown /app/x is the shell', () => {
    const { req, next } = run('/app/x');
    assert.equal(next, true);
    assert.equal(req.url, '/app/index.html');
});

test('a real /assets/x.js file is left alone', () => {
    const { req, res, next } = run('/assets/x.js');
    assert.equal(next, true);
    assert.equal(res.ended, false);
    assert.equal(req.url, '/assets/x.js');
});

test('/app/x%2Ejs is the shell, matching the Worker raw path', () => {
    const { req, next } = run('/app/x%2Ejs');
    assert.equal(next, true);
    assert.equal(req.url, '/app/index.html');
});

test('/app/%E0 is the shell, matching the Worker raw path', () => {
    const { req, next } = run('/app/%E0');
    assert.equal(next, true);
    assert.equal(req.url, '/app/index.html');
});

test('a missing script under /app stays a file, not the shell', () => {
    const { req, next } = run('/app/x.js');
    assert.equal(next, true);
    assert.equal(req.url, '/app/x.js');
});

test('a page outside the app is left to 404', () => {
    const { req, res, next } = run('/does-not-exist');
    assert.equal(next, true);
    assert.equal(res.ended, false);
    assert.equal(req.url, '/does-not-exist');
});
