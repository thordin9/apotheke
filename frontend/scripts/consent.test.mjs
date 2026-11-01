import assert from 'node:assert/strict';
import { test } from 'node:test';
import { consentCookie, consentDomain, withdrawnConsentCookie } from '../src/consent.js';

const HOSTED = 'apotheke_consent=v1; Domain=getapotheke.com; Path=/; Max-Age=15552000; Secure; SameSite=Lax';

test('getapotheke.com gets the exact hosted cookie', () => {
    assert.equal(consentDomain('getapotheke.com'), 'getapotheke.com');
    assert.equal(consentDomain('getapotheke.com.'), 'getapotheke.com');
    assert.equal(consentCookie('getapotheke.com', { protocol: 'https:', secureContext: true }), HOSTED);
    assert.equal(consentCookie('getapotheke.com', { protocol: 'http:', secureContext: false }), HOSTED);
});

test('a subdomain shares that domain', () => {
    assert.equal(consentDomain('www.getapotheke.com'), 'getapotheke.com');
    assert.equal(consentDomain('api.services.getapotheke.com'), 'getapotheke.com');
    assert.equal(consentDomain('autofill.services.getapotheke.com'), 'getapotheke.com');
    assert.equal(consentCookie('api.services.getapotheke.com', { protocol: 'https:', secureContext: true }), HOSTED);
});

test('lookalike hosts stay host-only', () => {
    for (const host of ['evilgetapotheke.com', 'getapotheke.com.evil.test', 'notgetapotheke.com']) {
        assert.equal(consentDomain(host), '');
        const cookie = consentCookie(host, { protocol: 'https:', secureContext: true });
        assert.equal(cookie.includes('Domain='), false);
        assert.equal(cookie, 'apotheke_consent=v1; Path=/; Max-Age=15552000; Secure; SameSite=Lax');
    }
});

test('a LAN address over http omits Domain and Secure', () => {
    assert.equal(consentDomain('192.168.1.10'), '');
    assert.equal(
        consentCookie('192.168.1.10', { protocol: 'http:', secureContext: false }),
        'apotheke_consent=v1; Path=/; Max-Age=15552000; SameSite=Lax',
    );
});

test('loopback http is host-only and Secure', () => {
    assert.equal(consentDomain('127.0.0.1'), '');
    assert.equal(consentDomain('localhost'), '');
    assert.equal(
        consentCookie('127.0.0.1', { protocol: 'http:', secureContext: true }),
        'apotheke_consent=v1; Path=/; Max-Age=15552000; Secure; SameSite=Lax',
    );
});

test('withdrawing expires the cookie with the attributes it was set with', () => {
    assert.equal(
        withdrawnConsentCookie('getapotheke.com', { protocol: 'https:', secureContext: true }),
        'apotheke_consent=; Domain=getapotheke.com; Path=/; Max-Age=0; Secure; SameSite=Lax',
    );
    assert.equal(
        withdrawnConsentCookie('api.services.getapotheke.com', { protocol: 'https:', secureContext: true }),
        'apotheke_consent=; Domain=getapotheke.com; Path=/; Max-Age=0; Secure; SameSite=Lax',
    );
    assert.equal(
        withdrawnConsentCookie('192.168.1.10', { protocol: 'http:', secureContext: false }),
        'apotheke_consent=; Path=/; Max-Age=0; SameSite=Lax',
    );
    for (const host of ['getapotheke.com', 'evilgetapotheke.com', '127.0.0.1', '192.168.1.10']) {
        for (const opts of [{ protocol: 'https:', secureContext: true }, { protocol: 'http:', secureContext: false }]) {
            const set = consentCookie(host, opts).split('; ').filter((p) => !p.startsWith('apotheke_consent=') && !p.startsWith('Max-Age='));
            const gone = withdrawnConsentCookie(host, opts).split('; ').filter((p) => !p.startsWith('apotheke_consent=') && !p.startsWith('Max-Age='));
            assert.deepEqual(gone, set, host + ' ' + opts.protocol);
        }
    }
});
