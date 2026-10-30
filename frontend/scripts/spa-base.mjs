// Where the SPA is mounted on the single origin (getapotheke.com).
// Step S1 replaces frontend/app/index.html and should keep this default.
// Override with APOTHEKE_SPA_BASE (a path such as /app/, never another origin).

export const DEFAULT_SPA_BASE = '/app/';

export function spaBase(env = process.env) {
    const raw = (env.APOTHEKE_SPA_BASE ?? '').trim();
    const value = raw || DEFAULT_SPA_BASE;
    if (!value.startsWith('/') || value.startsWith('//') || value.includes('://') || value.includes('..')) {
        throw new Error('APOTHEKE_SPA_BASE must be a single-origin path (default /app/)');
    }
    return value.endsWith('/') ? value : `${value}/`;
}
