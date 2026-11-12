import assert from 'node:assert/strict';
import { test } from 'node:test';
import { consentCookie, consentDomain, declineConsent, fetchSession, hasConsent, loadApiBase, retryPendingSignOut, sessionUrl, signOutPending, signedInLineAllowed, withdrawnConsentCookie } from '../src/consent.js';

const HOSTED = 'apotheke_consent=v1; Domain=getapotheke.com; Path=/; Max-Age=15552000; Secure; SameSite=Lax';

test('session URL joins an apiBase that already ends in /api/v1', () => {
    assert.equal(
        sessionUrl('https://api.services.getapotheke.com/api/v1'),
        'https://api.services.getapotheke.com/api/v1/session',
    );
    assert.equal(
        sessionUrl('https://api.services.getapotheke.com/api/v1/'),
        'https://api.services.getapotheke.com/api/v1/session',
    );
    assert.equal(sessionUrl(''), '/api/v1/session');
});

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

test('a successful /config.json is fetched once, and a failure is tried again', async () => {
    let calls = 0;
    let succeed = false;
    const previous = globalThis.fetch;
    globalThis.fetch = async () => {
        calls += 1;
        if (!succeed) return new Response('no', { status: 404 });
        return new Response(JSON.stringify({ apiBase: 'https://api.services.getapotheke.com/api/v1' }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        });
    };
    try {
        assert.equal(await loadApiBase(), '');
        assert.equal(calls, 1);
        assert.equal(await loadApiBase(), '');
        assert.equal(calls, 2);
        succeed = true;
        assert.equal(await loadApiBase(), 'https://api.services.getapotheke.com/api/v1');
        assert.equal(calls, 3);
        assert.equal(await loadApiBase(), 'https://api.services.getapotheke.com/api/v1');
        assert.equal(calls, 3);
    } finally {
        globalThis.fetch = previous;
    }
});

test('a signed-in line needs consent', () => {
    const previousDoc = globalThis.document;
    let cookie = '';
    globalThis.document = {
        get cookie() { return cookie; },
        set cookie(value) { cookie = String(value); },
    };
    try {
        assert.equal(signedInLineAllowed({ signed_in: true }), false);
        cookie = 'apotheke_consent=v1';
        assert.equal(signedInLineAllowed({ signed_in: true }), true);
        assert.equal(signedInLineAllowed({ signed_in: false }), false);
    } finally {
        globalThis.document = previousDoc;
    }
});

function installCookieDocument(cookieRef) {
    globalThis.isSecureContext = true;
    globalThis.location = { hostname: '127.0.0.1', protocol: 'http:' };
    globalThis.document = {
        get cookie() { return cookieRef.value; },
        set cookie(value) {
            cookieRef.value = String(value).includes('Max-Age=0') ? '' : String(value);
        },
        getElementById() { return null; },
        dispatchEvent() {},
    };
}

test('the sign-out value is not consent', () => {
    assert.equal(hasConsent('apotheke_consent=out'), false);
    assert.equal(signOutPending('apotheke_consent=out'), true);
    assert.equal(signOutPending('apotheke_consent=v1'), false);
});

test('a response clears the sign-out value, and a network failure is tried once later', async () => {
    const previousFetch = globalThis.fetch;
    const previousDoc = globalThis.document;
    const previousLocation = globalThis.location;
    const previousSecure = globalThis.isSecureContext;
    const cookieRef = { value: 'apotheke_consent=v1' };
    const sessionReads = [];
    let fail = true;
    installCookieDocument(cookieRef);
    globalThis.fetch = async (url, init) => {
        const href = String(url);
        if (href.includes('config.json')) {
            return new Response(JSON.stringify({ apiBase: 'https://api.example/api/v1' }), { status: 200 });
        }
        sessionReads.push({
            method: init && init.method ? init.method : 'GET',
            credentials: init && init.credentials,
            keepalive: init && init.keepalive,
            pending: cookieRef.value.includes('apotheke_consent=out'),
        });
        if (fail) throw new Error('offline');
        return new Response('', { status: 200 });
    };
    try {
        declineConsent();
        assert.equal(hasConsent(), false);
        assert.equal(signOutPending(), true);
        await new Promise((resolve) => setTimeout(resolve, 30));
        assert.equal(sessionReads.length, 1);
        assert.equal(sessionReads[0].keepalive, true);
        assert.equal(sessionReads[0].credentials, 'include');
        assert.equal(sessionReads[0].pending, true);
        assert.equal(signOutPending(), true);
        fail = false;
        retryPendingSignOut();
        await new Promise((resolve) => setTimeout(resolve, 30));
        assert.equal(sessionReads.length, 2);
        assert.equal(signOutPending(), false);
        assert.equal(cookieRef.value, '');
        retryPendingSignOut();
        await new Promise((resolve) => setTimeout(resolve, 20));
        assert.equal(sessionReads.length, 2);
    } finally {
        globalThis.fetch = previousFetch;
        globalThis.document = previousDoc;
        globalThis.location = previousLocation;
        globalThis.isSecureContext = previousSecure;
    }
});

test('Decline during a slow config read sends the session read when config resolves', async () => {
    const mod = await import('../src/consent.js?case=slow-config');
    const previousFetch = globalThis.fetch;
    const previousDoc = globalThis.document;
    const previousLocation = globalThis.location;
    const previousSecure = globalThis.isSecureContext;
    const cookieRef = { value: 'apotheke_consent=v1' };
    let release = () => {};
    const held = new Promise((resolve) => {
        release = resolve;
    });
    let sessionReads = 0;
    installCookieDocument(cookieRef);
    globalThis.fetch = async (url, init) => {
        const href = String(url);
        if (href.includes('config.json')) {
            await held;
            return new Response(JSON.stringify({ apiBase: 'https://api.example/api/v1' }), { status: 200 });
        }
        sessionReads += 1;
        assert.equal(init && init.keepalive, true);
        assert.equal(cookieRef.value.includes('apotheke_consent=out'), true);
        return new Response('', { status: 200 });
    };
    try {
        const pendingConfig = mod.loadApiBase();
        mod.declineConsent();
        assert.equal(sessionReads, 0);
        assert.equal(mod.signOutPending(), true);
        release();
        await pendingConfig;
        await new Promise((resolve) => setTimeout(resolve, 20));
        assert.equal(sessionReads, 1);
        assert.equal(mod.signOutPending(), false);
    } finally {
        globalThis.fetch = previousFetch;
        globalThis.document = previousDoc;
        globalThis.location = previousLocation;
        globalThis.isSecureContext = previousSecure;
    }
});

test('fetchSession drops a response that arrives after Decline', async () => {
    const previousFetch = globalThis.fetch;
    const previousDoc = globalThis.document;
    const previousLocation = globalThis.location;
    const previousSecure = globalThis.isSecureContext;
    let cookie = 'apotheke_consent=v1';
    let release = () => {};
    const held = new Promise((resolve) => {
        release = resolve;
    });
    let sessionReads = 0;
    globalThis.isSecureContext = true;
    globalThis.location = { hostname: '127.0.0.1', protocol: 'http:' };
    globalThis.document = {
        get cookie() { return cookie; },
        set cookie(value) {
            cookie = String(value).includes('Max-Age=0') ? '' : String(value);
        },
        getElementById() { return null; },
        dispatchEvent() {},
    };
    globalThis.fetch = async (url) => {
        const href = String(url);
        if (href.includes('config.json')) {
            return new Response(JSON.stringify({ apiBase: 'https://api.example/api/v1' }), { status: 200 });
        }
        sessionReads += 1;
        if (sessionReads === 1) {
            await held;
            return new Response(JSON.stringify({ csrf_token: 't', signed_in: true, user: { username: 'ada' } }), { status: 200 });
        }
        return new Response('', { status: 204 });
    };
    try {
        const pending = fetchSession('https://api.example/api/v1');
        declineConsent();
        release();
        const session = await pending;
        assert.equal(session.signed_in, false);
        await new Promise((resolve) => setTimeout(resolve, 20));
        assert.equal(sessionReads, 2);
    } finally {
        globalThis.fetch = previousFetch;
        globalThis.document = previousDoc;
        globalThis.location = previousLocation;
        globalThis.isSecureContext = previousSecure;
    }
});
