// In-app routes for /app/. Plain modules; the Svelte app replaces this in step S1.
// Navigation uses the History API only. No localStorage, sessionStorage, IndexedDB or Cache storage.
import { bindSignIn, enableLogin, hasConsent } from './consent.js';

// The build writes APOTHEKE_SPA_BASE in here (default /app/). The page also carries
// data-spa-base for the shell and the fallback server. The router does not read
// that attribute: copying it into an href is DOM text reused as HTML.
function mountPath(value) {
    const fallback = '/app';
    if (typeof value !== 'string') return fallback;
    if (!value.startsWith('/') || value.startsWith('//')) return fallback;
    if (value.includes('\\') || value.includes(':') || value.includes('..')) return fallback;
    const trimmed = value.replace(/\/$/, '');
    return trimmed || fallback;
}

const base = mountPath(__APOTHEKE_SPA_BASE__);

function routeOf(pathname) {
    if (pathname === base || pathname === base + '/') return 'home';
    if (pathname === base + '/sign-in') return 'sign-in';
    if (pathname === base || pathname.startsWith(base + '/')) return 'missing';
    return 'outside';
}

function setRobots(noindex) {
    const existing = document.querySelector('meta[name="robots"]');
    if (!noindex) {
        if (existing) existing.remove();
        return;
    }
    const meta = existing || document.head.appendChild(document.createElement('meta'));
    meta.setAttribute('name', 'robots');
    meta.setAttribute('content', 'noindex');
}

function setTitle(text) {
    document.title = text;
}

function link(href, className, text) {
    const a = document.createElement('a');
    a.href = href;
    if (className) a.className = className;
    a.textContent = text;
    return a;
}

function renderHome(root) {
    setRobots(false);
    setTitle('Apotheke');
    root.replaceChildren();
    const h1 = document.createElement('h1');
    h1.textContent = 'Where did you put the drill?';
    const lead = document.createElement('p');
    lead.className = 'answer';
    lead.textContent = 'Apotheke catalogues your things: a photo, a place, found in seconds.';
    const actions = document.createElement('div');
    actions.className = 'actions';
    actions.append(link(base + '/sign-in', 'btn btn-primary', 'Sign in'));
    root.append(h1, lead, actions);
}

function renderSignIn(root) {
    setRobots(false);
    setTitle('Sign in | Apotheke');
    root.replaceChildren();
    const h1 = document.createElement('h1');
    h1.textContent = 'Sign in';
    const note = document.createElement('p');
    note.id = 'sign-in-note';
    note.className = 'answer';
    note.textContent = 'Accept cookies to sign in.';
    const home = document.createElement('p');
    home.id = 'sign-in-home';
    home.className = 'sign-in-home';
    home.hidden = true;
    home.append(link('/', 'btn', 'Home'));
    const form = document.createElement('form');
    form.id = 'sign-in';
    form.className = 'sign-in';
    form.method = 'post';
    form.action = '/api/v1/session';
    bindSignIn(form);
    const userLabel = document.createElement('label');
    userLabel.htmlFor = 'sign-in-user';
    userLabel.textContent = 'Name';
    const user = document.createElement('input');
    user.id = 'sign-in-user';
    user.name = 'username';
    user.autocomplete = 'username';
    user.disabled = true;
    const passLabel = document.createElement('label');
    passLabel.htmlFor = 'sign-in-pass';
    passLabel.textContent = 'Password';
    const pass = document.createElement('input');
    pass.id = 'sign-in-pass';
    pass.name = 'password';
    pass.type = 'password';
    pass.autocomplete = 'current-password';
    pass.disabled = true;
    const button = document.createElement('button');
    button.type = 'submit';
    button.className = 'btn';
    button.id = 'sign-in-submit';
    button.disabled = true;
    button.textContent = 'Sign in';
    form.append(userLabel, user, passLabel, pass, button);
    root.append(h1, note, home, form);
    if (hasConsent()) enableLogin(form, '');
}

function renderMissing(root) {
    setRobots(true);
    setTitle('Not found | Apotheke');
    root.replaceChildren();
    const h1 = document.createElement('h1');
    h1.textContent = 'Nothing on this shelf';
    const lead = document.createElement('p');
    lead.className = 'answer';
    lead.textContent = 'That page is not in the app.';
    const actions = document.createElement('div');
    actions.className = 'actions';
    actions.append(link(base + '/', 'btn btn-primary', 'Back to the app'), link('/', 'btn', 'Home'));
    root.append(h1, lead, actions);
}

function render() {
    const root = document.getElementById('app');
    if (!root) return;
    const name = routeOf(location.pathname);
    if (name === 'home') renderHome(root);
    else if (name === 'sign-in') renderSignIn(root);
    else renderMissing(root);
}

function onClick(event) {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!anchor || anchor.target === '_blank' || anchor.hasAttribute('download')) return;
    const url = new URL(anchor.href, location.origin);
    if (url.origin !== location.origin) return;
    if (routeOf(url.pathname) === 'outside') return;
    // Same-page jumps, including "Skip to content", stay with the browser.
    if (url.pathname === location.pathname && url.search === location.search) return;
    event.preventDefault();
    const next = url.pathname + url.search + url.hash;
    if (next !== location.pathname + location.search + location.hash) history.pushState(null, '', next);
    render();
}

document.addEventListener('click', onClick);
window.addEventListener('popstate', render);
render();
