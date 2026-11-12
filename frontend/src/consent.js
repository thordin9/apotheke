// Cookie consent for the landing pages and the app shell.
// The Svelte app (step S1) should import these functions rather than copy them.
// Nothing here reads or writes localStorage, sessionStorage, IndexedDB or Cache storage.
// The consent cookie is the only write, and only acceptConsent() performs it.
// Page-view counting (analytics.js) starts only with consent and stores nothing either.
import { stopAnalytics, trackPageview } from './analytics.js';

export const CONSENT_NAME = 'apotheke_consent';
export const CONSENT_VALUE = 'v1';
export const CONSENT_MAX_AGE = 15552000;
export const CONSENT_PARENT = 'getapotheke.com';
export const SESSION_PATH = '/api/v1/session';

/**
 * apiBase from /config.json already ends in /api/v1, and SESSION_PATH is
 * /api/v1/session. Joining them as strings would call /api/v1/api/v1/session.
 */
export function sessionUrl(apiBase) {
    const base = String(apiBase || '').replace(/\/+$/, '');
    if (base.endsWith('/api/v1')) return base + '/session';
    if (base === '') return SESSION_PATH;
    return base + SESSION_PATH;
}

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
    return cookieString(CONSENT_VALUE, CONSENT_MAX_AGE, hostname, protocol, secureContext);
}

/**
 * The cookie string that removes the consent cookie: the same Domain, Path, Secure and
 * SameSite it was set with, an empty value and Max-Age=0.
 */
export function withdrawnConsentCookie(hostname, { protocol = 'https:', secureContext = false } = {}) {
    return cookieString('', 0, hostname, protocol, secureContext);
}

function cookieString(value, maxAge, hostname, protocol, secureContext) {
    const domain = consentDomain(hostname);
    const secure = domain !== '' || protocol === 'https:' || secureContext === true;
    const parts = [CONSENT_NAME + '=' + value];
    if (domain) parts.push('Domain=' + domain);
    parts.push('Path=/', 'Max-Age=' + maxAge);
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

/** Expires the consent cookie. Nothing is counted or stored after this. */
export function withdrawConsent() {
    document.cookie = withdrawnConsentCookie(location.hostname, {
        protocol: location.protocol,
        secureContext: typeof isSecureContext === 'boolean' ? isSecureContext : false,
    });
    stopAnalytics();
    disableLogin();
}

/** After withdrawal: forget the CSRF token and put the sign-in form back as it is before Accept. */
function disableLogin() {
    csrfToken = '';
    const form = document.getElementById('sign-in');
    if (!form) return;
    for (const el of form.querySelectorAll('input, button')) el.disabled = true;
    const note = document.getElementById('sign-in-note');
    if (note) note.textContent = 'Accept cookies to sign in.';
    const error = document.getElementById('sign-in-error');
    if (error) error.textContent = '';
    const home = document.getElementById('sign-in-home');
    if (home) home.hidden = true;
}

/** Consent still stands when the cookie is present and Decline is not replacing it. */
export function consentStands() {
    return !endingConsent && hasConsent();
}

/** A signed-in line is shown only while consent still stands. */
export function signedInLineAllowed(session) {
    return consentStands() && !!(session && session.signed_in === true);
}

const SIGNOUT_WAIT_MS = 3000;

/**
 * Decline stores nothing. When consent was given, one sign-out is sent and the
 * consent cookie is removed after that finishes, or after a short wait.
 * The signed-in state is cleared at once. Hiding the notice is the caller's job.
 */
export function declineConsent() {
    const hadConsent = hasConsent();
    const token = csrfToken;
    sessionEpoch += 1;
    signedIn = false;
    csrfToken = '';
    if (!hadConsent) return;
    endingConsent = true;
    document.dispatchEvent(new Event('apotheke-signed-out'));
    void signOutThenWithdraw(token);
}

async function signOutThenWithdraw(token) {
    let stop = false;
    const timer = setTimeout(() => {
        stop = true;
    }, SIGNOUT_WAIT_MS);
    try {
        await Promise.race([
            signOutRequest(token, () => stop),
            new Promise((resolve) => setTimeout(resolve, SIGNOUT_WAIT_MS)),
        ]);
    } catch {
        // The notice is already gone. A failed sign-out still leaves no local session.
    } finally {
        clearTimeout(timer);
        stop = true;
        endingConsent = false;
        if (hasConsent()) withdrawConsent();
    }
}

async function deleteSession(url, token, stop) {
    if (stop()) return null;
    return fetch(url, {
        method: 'DELETE',
        credentials: 'include',
        headers: {
            Accept: 'application/json',
            'X-CSRF-Token': token,
        },
    });
}

async function signOutRequest(token, stop) {
    const base = await loadApiBase();
    const url = sessionUrl(base);
    let res;
    try {
        res = await deleteSession(url, token, stop);
    } catch {
        return;
    }
    if (!res || stop() || res.status !== 403) return;
    const problem = await readProblem(res);
    if (problem.code !== 'csrf.invalid' || stop()) return;
    try {
        await deleteSession(url, token, stop);
    } catch {
        // One retry only.
    }
}

let apiBasePromise = null;

/** A successful /config.json read is kept. A failure is forgotten so the next call tries again. */
export function loadApiBase() {
    if (!apiBasePromise) {
        apiBasePromise = fetch('/config.json', { headers: { Accept: 'application/json' } })
            .then(async (res) => {
                if (!res.ok) throw new Error('config');
                const data = await res.json();
                const base = data && typeof data.apiBase === 'string' ? data.apiBase : '';
                if (base === '') throw new Error('config');
                return base;
            })
            .catch(() => {
                apiBasePromise = null;
                return '';
            });
    }
    return apiBasePromise;
}

export function rememberCsrf(data) {
    const token = data && typeof data.csrf_token === 'string' ? data.csrf_token : '';
    if (token) csrfToken = token;
    return token;
}

export async function fetchSession(apiBase = '') {
    const epoch = sessionEpoch;
    try {
        const res = await fetch(sessionUrl(apiBase), {
            credentials: 'include',
            headers: { Accept: 'application/json' },
        });
        if (epoch !== sessionEpoch || !res.ok) return { csrf_token: '', signed_in: false, username: '' };
        const data = await res.json();
        if (epoch !== sessionEpoch) return { csrf_token: '', signed_in: false, username: '' };
        const token = rememberCsrf(data);
        const user = data && data.user;
        const username = user && typeof user.username === 'string' ? user.username : '';
        signedIn = !!(data && data.signed_in === true);
        return { csrf_token: token, signed_in: signedIn, username };
    } catch {
        return { csrf_token: '', signed_in: false, username: '' };
    }
}

let csrfToken = '';
let signedIn = false;
let sessionEpoch = 0;
let endingConsent = false;

export function currentCsrfToken() {
    return csrfToken;
}

export async function enableLogin(form, apiBase) {
    const base = apiBase === undefined ? await loadApiBase() : apiBase;
    const session = await fetchSession(base);
    const note = document.getElementById('sign-in-note');
    const token = session && typeof session.csrf_token === 'string' ? session.csrf_token : '';
    const home = document.getElementById('sign-in-home');
    if (token === '') {
        if (note) note.textContent = 'Sign-in is coming soon.';
        if (home) home.hidden = false;
        return '';
    }
    csrfToken = token;
    for (const el of form.querySelectorAll('input, button')) el.disabled = false;
    if (note) note.textContent = 'You can sign in.';
    if (home) home.hidden = true;
    return csrfToken;
}

function readCookieHeader() {
    return typeof document === 'undefined' ? '' : document.cookie;
}

function hide(banner) {
    if (banner) banner.hidden = true;
    const choice = document.getElementById('consent-choice');
    if (choice) choice.hidden = true;
}

function show(banner) {
    if (banner) banner.hidden = false;
}

function dismissNotice(banner) {
    hide(banner);
    const main = document.getElementById('main');
    if (!main) return;
    if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1');
    main.focus();
}

function setFieldError(errorId, inputId, message) {
    const el = document.getElementById(errorId);
    const input = document.getElementById(inputId);
    if (el) el.textContent = message || '';
    if (!input) return;
    if (message) {
        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-describedby', errorId);
    } else {
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
    }
}

function clearFieldErrors() {
    setFieldError('sign-in-user-error', 'sign-in-user', '');
    setFieldError('sign-in-pass-error', 'sign-in-pass', '');
}

function showFieldErrors(errors) {
    setFieldError('sign-in-user-error', 'sign-in-user', errors.username || '');
    setFieldError('sign-in-pass-error', 'sign-in-pass', errors.password || '');
}

async function readProblem(res) {
    try {
        const problem = await res.json();
        if (!problem || typeof problem !== 'object') return { code: '', detail: '', errors: {} };
        const code = typeof problem.code === 'string' ? problem.code : '';
        const detail = typeof problem.detail === 'string' ? problem.detail : '';
        const errors = {};
        const raw = problem.errors;
        if (raw && typeof raw === 'object') {
            if (typeof raw.username === 'string') errors.username = raw.username;
            if (typeof raw.password === 'string') errors.password = raw.password;
        }
        return { code, detail, errors };
    } catch {
        return { code: '', detail: '', errors: {} };
    }
}

function retryAfterSeconds(res) {
    const raw = res.headers.get('Retry-After');
    if (!raw) return null;
    const text = raw.trim();
    if (/^\d+$/.test(text)) return Number(text);
    const when = Date.parse(text);
    if (Number.isNaN(when)) return null;
    return Math.max(0, Math.round((when - Date.now()) / 1000));
}

function rateLimitMessage(res, detail) {
    if (detail) return detail;
    const seconds = retryAfterSeconds(res);
    if (seconds === null) return 'Too many sign-in attempts. Try again later.';
    const minutes = Math.ceil(seconds / 60);
    const wait = minutes < 1 ? 1 : minutes;
    return 'Too many sign-in attempts. Try again in ' + wait + (wait === 1 ? ' minute.' : ' minutes.');
}

async function postSession(url, body, token) {
    return fetch(url, {
        method: 'POST',
        credentials: 'include',
        headers: {
            Accept: 'application/problem+json, application/json',
            'Content-Type': 'application/json',
            'X-CSRF-Token': token,
        },
        body: JSON.stringify(body),
    });
}

function applyFailure(error, res, problem) {
    clearFieldErrors();
    if (!error) return;
    if (problem.code === 'consent_required') {
        error.textContent = 'Accept cookies, then try again.';
        return;
    }
    if (problem.code === 'validation.failed') {
        showFieldErrors(problem.errors);
        error.textContent = problem.detail || 'Enter a user name and password.';
        return;
    }
    if (res.status === 429 || problem.code === 'rate_limited') {
        error.textContent = rateLimitMessage(res, problem.detail);
        return;
    }
    if (problem.code === 'auth.invalid_credentials') {
        error.textContent = problem.detail || 'Wrong user name or password.';
        return;
    }
    error.textContent = problem.detail || 'Sign-in did not succeed.';
}

function submitButton(form) {
    const button = form.querySelector('button[type="submit"]');
    return button instanceof HTMLButtonElement ? button : null;
}

function setBusy(button, busy) {
    if (!button) return;
    button.disabled = busy;
    if (busy) button.setAttribute('aria-busy', 'true');
    else button.removeAttribute('aria-busy');
}

async function signIn(form, onSignedIn) {
    const error = document.getElementById('sign-in-error');
    const button = submitButton(form);
    if (button && button.getAttribute('aria-busy') === 'true') return;
    if (error) error.textContent = '';
    clearFieldErrors();
    if (!csrfToken) return;
    setBusy(button, true);
    const body = {
        username: form.username.value,
        password: form.password.value,
    };
    try {
        const base = await loadApiBase();
        const url = sessionUrl(base);
        let res;
        try {
            res = await postSession(url, body, csrfToken);
        } catch {
            if (error) error.textContent = 'Sign-in could not reach the server.';
            return;
        }
        let problem = res.ok ? { code: '', detail: '', errors: {} } : await readProblem(res);
        if (!res.ok && res.status === 403 && problem.code === 'csrf.invalid') {
            const session = await fetchSession(base);
            const token = session && typeof session.csrf_token === 'string' ? session.csrf_token : '';
            if (token) {
                try {
                    res = await postSession(url, body, token);
                } catch {
                    if (error) error.textContent = 'Sign-in could not reach the server.';
                    return;
                }
                problem = res.ok ? { code: '', detail: '', errors: {} } : await readProblem(res);
            }
        }
        if (res.ok) {
            signedIn = true;
            try {
                rememberCsrf(await res.clone().json());
            } catch {
                rememberCsrf(null);
            }
            if (error) error.textContent = '';
            clearFieldErrors();
            if (typeof onSignedIn === 'function') onSignedIn();
            else location.assign('/app/');
            return;
        }
        applyFailure(error, res, problem);
    } finally {
        setBusy(button, false);
    }
}

export function bindSignIn(form, onSignedIn) {
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        signIn(form, onSignedIn);
    });
}

// The app shell (/app/) counts its own views from its router, one per route render.
function isAppShell() {
    return document.getElementById('app') !== null;
}

// Footer "Cookie settings": reopens the notice with the current choice, so withdrawing
// is one click, like accepting. The button stays hidden without JavaScript.
function bindSettings(banner) {
    const button = document.getElementById('cookie-settings');
    if (!button || !banner) return;
    button.hidden = false;
    button.addEventListener('click', () => {
        const choice = document.getElementById('consent-choice');
        if (choice) {
            choice.textContent = hasConsent()
                ? 'Your choice: accepted. Decline deletes the cookie and stops counting.'
                : 'Your choice: not accepted. Nothing is stored or counted.';
            choice.hidden = false;
        }
        show(banner);
        const first = banner.querySelector('button');
        if (first) first.focus();
    });
}

function start() {
    const banner = document.getElementById('consent');
    const login = document.getElementById('sign-in');
    if (login) bindSignIn(login);
    bindSettings(banner);
    const decline = document.getElementById('consent-decline');
    const accept = document.getElementById('consent-accept');
    if (decline) {
        decline.addEventListener('click', () => {
            declineConsent();
            dismissNotice(banner);
        });
    }
    if (accept) {
        accept.addEventListener('click', () => {
            const already = hasConsent();
            acceptConsent();
            dismissNotice(banner);
            if (already) return;
            const form = document.getElementById('sign-in');
            if (form) enableLogin(form);
            trackPageview();
        });
    }
    if (hasConsent()) {
        hide(banner);
        if (login) enableLogin(login);
        if (!isAppShell()) trackPageview();
        return;
    }
    show(banner);
}

if (typeof document !== 'undefined') start();
