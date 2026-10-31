# QA: apotheke #198, one product across the landing page and the app (static frontend)

**Gate:** full (touches consent). **Head:** `5998bb6e29f162b4452ffae351db7aeb92f1a875`, first reviewed at `80d663b`. The delta is main merged as `fea857a` (#197, docs only, files identical to main) and 3 fix commits. It is 0 behind main, mergeable and clean, +741/-216 across 20 files. **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `5998bb6`.** No Highs or Mediums. Both Lows are fixed. N-1508 stays open as a DevOps follow-up and N-1510 is optional; neither blocks.

## Checklist

- [x] **CI.** All 22 check runs are green at `5998bb6`; Deploy getapotheke-app is skipped, as usual on a PR. No PHP or `edge/` file changed.
- [x] **Local run.** `npm ci --ignore-scripts` and `npm run build` succeed. `contrast.mjs` 88/88 and `check-dist.mjs` pass. `node --test` over `consent.test.mjs` and `app-fallback.test.mjs` passes 12/12, and the frontend job now runs both. The frontend e2e passes 18/18 on chromium.
- [x] **Fallback parity.** `frontend/scripts/app-fallback.mjs` uses the Worker's `/\.[A-Za-z0-9]+$/` and its 301 from `/app` to `/app/` with the query kept (`edge/app/src/index.ts:79-86`). `vite.config.js`, `consent-server.mjs` and `check-dist.mjs` share it. `vite preview` returns:
  - 301 for `/app` and `/app?x=1`;
  - 200 for `/app/`, `/app/x`, `/app/sign-in` and `/app/a.b-c`;
  - 404 for `/app/x.js`.
- [x] **First-pass checks still hold.** The shared chrome, CTAs and router are as before. There is no `innerHTML` and no web storage. The consent states are unchanged. `_headers` and the CSP are unchanged, and no new hosts were added. Commits follow Conventional Commits, and no manual CHANGELOG entry is expected.
- [x] **Mutations.** All 4 delta mutations are caught, so with the first pass (7 of 8) every mutation is now caught:
  - **Fallback serves a 404 for `/app/x`:** caught by 3 unit tests, check-dist and 14 e2e tests. This survived at `80d663b`.
  - **`#sign-in-error` dropped:** caught by 5 e2e tests.
  - **Shell served for `/app/x.js`:** caught by the unit test.
  - **200 instead of a 301 for `/app`:** caught by the unit test.

## Findings

- **L-1505: fixed in `6899e05`.** `src/app.js:74-76,112` renders `#sign-in-error` with `role="status"`. `app.spec.ts:117-118` expects it to be present and empty. A new e2e test, "a refused sign-in says to accept cookies", submits after a valid token and gets a 403 `consent_required`, then checks the message.
- **L-1506: fixed in `274a789`.** `frontend/README.md:63-67` now describes the 301, the extension rule and the 404 for a missing file. Both servers use the shared helper.
- **N-1507: fixed.** `rewriteAppShell` is exported, and `app-fallback.test.mjs` (7 tests) covers the redirect, the shell, the extension rule and paths outside the app.
- **N-1508: open, DevOps follow-up, non-blocking.** `noindex` is still set only on the client for unknown `/app/*` paths. Suggested fix: `X-Robots-Tag: noindex` on the Worker's shell fallback.
- **N-1509: fixed in `5998bb6`.** The `.slab .opt` rule is gone from `landing.css`.
- **N-1510: new, optional.** `app-fallback.mjs:14-22` percent-decodes before it tests the extension, but the Worker tests the raw pathname. So `/app/x%2Ejs` gets a 404 locally and the shell in production, and a malformed escape passes through instead of getting the shell. This affects the dev and test servers only. Fix: test the raw path, as the Worker does.

## Conditions

Squash-merge with the PR title as the subject. This doc must be the last commit, byte-exact. No secrets, tokens or credentials appear in the diff, the tests or this doc.
