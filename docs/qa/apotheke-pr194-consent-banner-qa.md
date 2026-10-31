# QA: apotheke #194, cookie consent banner (static frontend)

**Gate:** full. **Head:** `111e1af66a19e7ee377d10db9bb98ebb40cc78e5`, a fast-forward from `5b78c2b`, which was itself a fast-forward from the first-reviewed `c82e894`. It is 9 commits on main `bd92a10`, 0 behind, mergeable and clean. **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `111e1af`.** No findings are open: every Low is fixed, and the open Nits are fixed or accepted.

## Checklist

- [x] **CI.** All 22 check runs are green at `111e1af`; Deploy getapotheke-app is skipped, as usual on a PR. No PHP changed.
- [x] **Local run.**
  - `contrast.mjs` 88/88 and `check-dist.mjs` pass.
  - `node --test frontend/scripts/consent.test.mjs` passes 5/5, and the frontend job now runs it.
  - The consent Playwright file passes 8/8 on chromium at the unmodified head.
- [x] **Built output.** Each of `/`, `/privacy/` and `/app/` loads one same-origin module, and 404 loads none. There are no inline scripts or `style=` attributes. The notice starts with the `hidden` attribute (`[hidden] { display: none !important }` in base.css) and the script unhides it, so no inline style is needed and the CSP is unaffected. `#main` has `tabindex="-1"` in the HTML.
- [x] **No-JS.** The notice stays hidden and nothing is stored (new e2e test). The sign-in fields stay disabled.
- [x] **DOM safety and storage.** The delta adds no `innerHTML` and no storage writes. Accept is still the only write.
- [x] **Cookie and CSP interpretations: still accepted.** The cookie attributes and `Domain` rule are unchanged and now locked by the unit test. The 404 `script-src 'self'` and HSTS are unchanged.
- [x] **Docs.** Commits follow Conventional Commits. The privacy page's Domain line is now accurate (L-1492). The squash title still generates the Maintenance CHANGELOG bullet with its link.
- [x] **Mutations.** All 14 are caught:
  - **Caught by the unit test:** the hosted cookie without `Domain`, `SameSite=Strict` on the hosted cookie, a different `Max-Age`, and lookalike hosts given `Domain`.
  - **Caught by the e2e test:** a session GET before consent, Decline setting a cookie, theme written to `localStorage` before consent, any value counting as consent, the notice never shown, and no focus move.
  - **Caught by the e2e test at `111e1af`:** sign-in enabled without the token check (4 tests fail), enabled on a non-2xx response (1 fails) and enabled on a failed fetch (1 fails).

## Findings

- **L-1490: fixed in `111e1af`, which changes only `tests/e2e/consent.spec.ts`.** After Accept, the tests wait for the outcome note: "Sign-in stays off until the server offers a sign-in token." in the negative cases and "You can sign in." for a valid token. Only then do they check the form state. The test also asserts that no session request is sent before Accept.
- **L-1491: fixed.** `consent.test.mjs` covers hosted, subdomain, lookalike, LAN and loopback hosts, and CI runs it.
- **L-1492: fixed.** The privacy page says every getapotheke.com subdomain receives the cookie: api.services reads it as consent, and autofill.services stores nothing.
- **N-1493: accepted by design.** Secure is set on loopback HTTP; the unit test now pins this.
- **N-1494: accepted.** A code comment notes that the placeholder stays on its own origin until the SPA passes the API base.
- **N-1495: fixed.** Focus moves to `#main` after Accept or Decline, and the e2e test asserts it.
- **N-1496: fixed.** The notice is hidden until the script shows it, with no inline style.
- **N-1497: fixed.** The `frontend.yml` paths include the root `package.json` and `package-lock.json` (dependency hygiene).

## Conditions

Squash-merge with the PR title as the subject. This doc must be the last commit, byte-exact. No secrets, tokens or credentials appear in the diff, the tests or this doc.
