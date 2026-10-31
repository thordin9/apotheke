// Cookieless page-view counting (Counterscale), only after consent.
// The tracker is bundled (no third-party script) and loaded with a dynamic import,
// so a visitor who has not accepted never downloads it and never sends a request.
// It stores nothing: no cookies, localStorage, sessionStorage, IndexedDB or Cache storage.
// Visits are counted from the browser's HTTP cache (If-Modified-Since on /cache and /collect).
import { hasConsent } from './consent.js';

export const ANALYTICS_SITE_ID = 'getapotheke.com';
export const ANALYTICS_REPORTER_URL = 'https://analytics.services.webapp.gr/collect';

let tracker = null;

function load() {
    if (!tracker) {
        tracker = import('@counterscale/tracker').then((counterscale) => {
            counterscale.init({
                siteId: ANALYTICS_SITE_ID,
                reporterUrl: ANALYTICS_REPORTER_URL,
                autoTrackPageviews: false,
            });
            return counterscale;
        });
        // A failed load (offline, blocked) must not break the page; try again on the next view.
        tracker.catch(() => {
            tracker = null;
        });
    }
    return tracker;
}

/**
 * Counts one page view of the current URL, and only with consent.
 * The URL is passed explicitly: the tracker otherwise prefers <link rel="canonical">,
 * which would report every /app/* route as /app/.
 */
export function trackPageview() {
    if (!hasConsent()) return Promise.resolve(false);
    // Read now, not after the import resolves, so a quick navigation is not counted twice.
    const url = location.origin + location.pathname + location.search;
    return load().then(
        (counterscale) => {
            if (!hasConsent()) return false;
            counterscale.trackPageview({ url });
            return true;
        },
        () => false,
    );
}
