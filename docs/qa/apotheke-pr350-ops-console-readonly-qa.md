# QA: apotheke #350, the read-only operator console screens

- PR: https://github.com/thordin9/apotheke/pull/350 (Front-End), base `main`. Console screens for the #348 reads: Overview, Installations (filters, Load more), Installation detail, Plans, Subscriptions due, Jobs, Audit with CSV export; Users stays "not available yet". Plus the #337 follow-ups L-2322 and N-2323 to N-2327.
- Reviewed head: `bfa881e68e837fa7e3192f2430aa46af6b06fafb`, 0 behind main `1a9c9a3d` (v3.59.0). Commits: `768c1b1` (feat), `d3337b9` (docs), merge `1969cee`, `416f1f3` (fix), merge `bfa881e`.
- Gate: full (new code). Full read of `client.ts`, `types.ts`, `render.ts`, `vite.config.ts` and the bundle test; shapes cross-checked against `edge/ops/src/reads.ts`; unit, bundle and e2e suites; 14 mutants; secret scan.
- CI on `bfa881e6`, all green: CI 44816815234, Operator console 44816815863, PR #350 44816808406 (22 pass).
- Reviewed 2026-11-20, 23:20 to 23:50 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope (main...head): 18 files, +995/−407, all under `ops/console` plus `docs/operator-console.md` and CHANGELOG. Nothing under `edge/`.
- [x] Merges: parents `[d3337b9, d5e36e3a]` and `[416f1f3, 1a9c9a3d]`. Each differs from `git merge-tree` only in `CHANGELOG.md`. End state: CHANGELOG differs from main only by the `[Unreleased]` / Added bullet with the full PR URL; `VERSION` equals main.
- [x] Shapes: the top-level keys of Overview, Installations, Installation detail, Plans, Subscriptions due, Jobs and Audit match `reads.ts` exactly.
- [x] Read-only: `WriteMethod` is `POST | PUT` and nothing in `src/` calls `write()`; `csrfHeaders()` is the only place `X-CSRF: 1` is set and only for writes. Users makes no API call. Primary is badged, with no actions.
- [x] DOM: `el()` sets text only through `textContent` and attributes through `setAttribute`; no `innerHTML` or other HTML sinks. Hosts render as text. Link interception is same-origin only and leaves the CSV link and downloads alone; filter forms are intercepted and reset `cursor`.
- [x] Errors: 401, 403 (csrf, primary, identity), 404, 405, 409, 410, 422 (with `invalid_query` detail), 503 (`not_configured`, `access_unavailable`, container detail), network and unexpected; `detail` is shown as text.
- [x] CSP: the production bundle has no inline script or style, no `style=` and no external URL (bundle test). It runs under `edge/ops`'s production CSP (`script-src`/`style-src 'self'`; filters submit through JS, so `form-action 'none'` holds).
- [x] Tests (Node 22): unit 19/19, bundle 2/2, `tsc` and `vite build` clean, Playwright e2e 19/19 (axe on every screen, keyboard, Load more, CSV filters, the 401/503/410 startup errors).
- [x] Mutants: 8/14 killed (writes always or only POST sending CSRF, 405 mapping, the 422 and 503 details, empty query params, the audit export filters, cursor reset). Survivors are L-2524 and N-2525/N-2526; the external-URL bundle assert is a guard with nothing to catch (equivalent).
- [x] Docs and commits: the preview paragraph matches; subjects are Conventional (48 and 45 characters for the non-merge ones). Squash "feat(ops): read-only operator console screens (#350)" is 52 characters.
- [x] Secret scan: 0 secrets; the only hit is the comment "No secrets, keys, tokens or passwords". Fixtures use `example.test` only; no real host or email.

## Findings

- **L-2524:** no test renders hostile operator data. Switching `el()` from `textContent` to `innerHTML` passes every suite. The code is right today. Fix (follow-up): add a fixture value with markup (for example in `owner_contact`, a host and an audit `detail`) and assert it shows as text with no element created.
- **N-2525:** "the preview CSP reports no violations" passes even with no CSP header (removing `previewCsp` survives). The preview CSP is also looser than `edge/ops`'s (`default-src 'self'` and `form-action 'self'` against `'none'`; no `frame-ancestors`/`object-src`). Optional: assert the header and use the production string.
- **N-2526:** untested but correct: an `ok: false` body on a 2xx, `encodeURIComponent` on detail ids (fixture ids are all safe characters), and the same-origin check on link interception.
- **N-2527:** both merges resolved CHANGELOG by hand without saying so in the merge body, and their subjects are "Merge commit '…' into …". The end state is right and the squash replaces them.
- **N-2528:** a 403 `writes_disabled` (#352) would read "a disallowed identity". It can't happen yet (no writes); map it before the write screens.

0 High, 0 Medium, 1 Low and 4 Nits (next free id: 2529).

## Verdict

**PASS** at `bfa881e6`, 0 behind main `1a9c9a3d`. This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #350"), followed by the hash check and green CI on that head. Squash as "feat(ops): read-only operator console screens (#350)". As a `feat`, this cuts a minor release (v3.60.0). Going live on the console host also needs #352 (the deploy wiring), which has not passed QA yet.
