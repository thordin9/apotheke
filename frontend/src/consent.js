// Cookie consent for the landing pages and the SPA placeholder.
// The Svelte app (step S1) should import these functions rather than copy them.
// Nothing here reads or writes localStorage, sessionStorage, IndexedDB or Cache storage.
// The consent cookie is the only write, and only acceptConsent() performs it.

export const CONSENT_NAME = 'apotheke_consent';
export const CONSENT_VALUE = 'v1';
export const CONSENT_MAX_AGE = 15552000;
export const CONSENT_PARENT = 'getapotheke.com';
export const SESSION_PATH = '/api/v1/session';

/** Parent domain for getapotheke.com and its subdomains; '' (host-only) everywhere else. */
export function consentDomain(hostname) {
    const host = String(hostname || '').toLowerCase().replace(/:\d+$/, '').replace(/\.$/, '');
    if (host === CONSENT_PARENT || host.endsWith('.' + CONSENT_PARENT)) return CONSENT_PARENT;
    return '';
}

/**
 * Cookie string for document.cookie.
 * On getapotheke.com this is exactly
 * apotheke_consent=v1; Domain=getapotheke.com; Path=/; Max-Age=15552000; Secure; SameSite=Lax
 * Secure is included for that domain, and on any other host only in a secure context
 * (HTTPS, or loopback). A plain HTTP LAN install stays host-only without Secure,
 * or the browser would drop the cookie.
 */
export function consentCookie(hostname, { protocol = 'https:', secureContext = false } = {}) {
    const domain = consentDomain(hostname);
    const secure = domain !== '' || protocol === 'https:' || secureContext === true;
    const parts = [CONSENT_NAME + '=' + CONSENT_VALUE];
    if (domain) parts.push('Domain=' + domain);
    parts.push('Path=/', 'Max-Age=' + CONSENT_MAX_AGE);
    if (secure) parts.push('Secure');
    parts.push('SameSite=Lax');
    return parts.join('; ');
}

/** Only the exact value v1 counts. */
export function hasConsent(cookieHeader) {
    const header = cookieHeader === undefined ? readCookieHeader() : String(cookieHeader);
    for (const part of header.split(';')) {
        const text = part.trim();
        const eq = text.indexOf('=');
        if (eq < 0) continue;
        if (text.slice(0, eq) === CONSENT_NAME && text.slice(eq + 1) === CONSENT_VALUE) return true;
    }
    return false;
}

export function acceptConsent() {
    document.cookie = consentCookie(location.hostname, {
        protocol: location.protocol,
        secureContext: typeof isSecureContext === 'boolean' ? isSecureContext : false,
    });
}

/** Decline stores nothing. Hiding the notice is the caller's job. */
export function declineConsent() {}

export async function fetchSession(apiBase = '') {
    const base = String(apiBase || '').replace(/\/$/, '');
    try {
        const res = await fetch(base + SESSION_PATH, {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) return { csrf_token: '' };
        const data = await res.json();
        const token = data && typeof data.csrf_token === 'string' ? data.csrf_token : '';
        return { csrf_token: token };
    } catch {
        return { csrf_token: '' };
    }
}

let csrfToken = '';

export function currentCsrfToken() {
    return csrfToken;
}

export async function enableLogin(form, apiBase = '') {
    const session = await fetchSession(apiBase);
    const note = document.getElementById('sign-in-note');
    if (!session.csrf_token) {
        if (note) note.textContent = 'Sign-in stays off until the server offers a sign-in token.';
        return '';
    }
    csrfToken = session.csrf_token;
    for (const el of form.querySelectorAll('input, button')) el.disabled = false;
    if (note) note.textContent = 'You can sign in.';
    return csrfToken;
}

function readCookieHeader() {
    return typeof document === 'undefined' ? '' : document.cookie;
}

function hide(banner) {
    if (banner) banner.hidden = true;
}

async function signIn(form, apiBase) {
    const error = document.getElementById('sign-in-error');
    if (error) error.textContent = '';
    if (!csrfToken) return;
    const body = {
        username: form.username.value,
        password: form.password.value,
    };
    let res;
    try {
        res = await fetch(String(apiBase || '').replace(/\/$/, '') + SESSION_PATH, {
            method: 'POST',
            credentials: 'include',
            headers: {
                Accept: 'application/problem+json, application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            body: JSON.stringify(body),
        });
    } catch {
        if (error) error.textContent = 'Sign-in could not reach the server.';
        return;
    }
    if (res.ok) {
        if (error) error.textContent = '';
        const note = document.getElementById('sign-in-note');
        if (note) note.textContent = 'Signed in.';
        return;
    }
    let code = '';
    try {
        const problem = await res.json();
        code = problem && typeof problem.code === 'string' ? problem.code : '';
    } catch {
        code = '';
    }
    if (error) {
        error.textContent = code === 'consent_required'
            ? 'Accept cookies, then try again.'
            : 'Sign-in did not succeed.';
    }
}

function wireLogin(form) {
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        signIn(form, '');
    });
}

function start() {
    const banner = document.getElementById('consent');
    const login = document.getElementById('sign-in');
    if (login) wireLogin(login);
    if (hasConsent()) {
        hide(banner);
        if (login) enableLogin(login, '');
        return;
    }
    if (!banner) return;
    const decline = document.getElementById('consent-decline');
    const accept = document.getElementById('consent-accept');
    if (decline) {
        decline.addEventListener('click', () => {
            declineConsent();
            hide(banner);
        });
    }
    if (accept) {
        accept.addEventListener('click', () => {
            acceptConsent();
            hide(banner);
            if (login) enableLogin(login, '');
        });
    }
}

if (typeof document !== 'undefined') start();
