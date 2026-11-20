# QA: apotheke #353, a lighter, tighter landing and app shell (design refresh A)

- PR: https://github.com/thordin9/apotheke/pull/353 (Front-End), base `main`. CSS-only restyle of the landing, privacy, 404 and `/app` shell, plus an axe and keyboard spec (`tests/e2e/landing-a11y.spec.ts`) with one new dev dependency.
- Reviewed head: `11b18a1a94a64dc6960946c52437a3d3123fee31`, 0 behind main `4637fa18` (v3.61.0), merge state `CLEAN`. Content PASS at `4a4b6759` (against `815a5abd`); catch-up merge `11b18a1` read merge-only. Commits: `5f0f28f` (feat), `67fec98` and `4a4b675` (docs), merges `598021a`, `c4e33e1` and `11b18a1`.
- Gate: full (user-facing CSS and a new dependency). Full diff read, frontend build, the Frontend e2e suite locally, extra layout probes at 8 widths in both schemes, contrast checks and a secret scan.
- CI on `4a4b6759`: Frontend 44828088476, CI 44828088539, Deploy frontend 44828088819, PR #353 44828077798. On `11b18a1a`: 23 green, 1 skipped (the deploy job).
- Reviewed 2026-11-21 00:50 to 01:30; merge-only read 01:45 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope (`815a5abd...head`): 7 files, +186/−58: `tokens.css`, `base.css`, `landing.css`, root `package.json` and `package-lock.json`, the new spec, CHANGELOG. No HTML, consent JS, CSP, headers or build config changed.
- [x] Merges: parents `[67fec98, 86a5e904]` and `[598021a, 815a5abd]`; each differs from `git merge-tree` only in `CHANGELOG.md`. `VERSION` equals main (3.60.0); the bullet is under `[Unreleased]` / Changed with the full PR URL. `11b18a1` (parents `[4a4b675, 4637fa18]`) also differs from `git merge-tree` only in `CHANGELOG.md`: the markers are dropped and both sides kept. `4637fa18...head` has the same 7 files with identical per-file hunks to `815a5abd...4a4b6759`; `VERSION` equals main (3.61.0).
- [x] Dependency: `@axe-core/playwright` 4.11.2 (pinned, dev, MPL-2.0) and `axe-core` 4.11.4 (`~4.11.3`), both from registry.npmjs.org with integrity hashes. It goes in the root package, which is what the Frontend job installs, and Dependabot already covers the root (`npm`, `/`). `tests/e2e/package.json` (puppeteer) is untouched.
- [x] The spec runs in CI: the Frontend job went from 50 to 64 tests (+14: axe on 5 pages × 2 schemes, tab order × 2, consent clearance, no inline script or style). Locally 64/64 against the built `dist`.
- [x] Contrast: `contrast.mjs` 90/90 pairs in CI, and axe (WCAG 2.0 to 2.2 AA tags) finds nothing in either scheme. The light header is now paper/ink (covered by the existing header pair). The dark header stays `#07161c`, and its focus ring (`--color-focus`, now used there) measures 8.05:1. Dark paper moves one step lighter in both dark blocks, which stay identical.
- [x] Consent: no JS or markup change; the consent and app specs pass unchanged. The header is now sticky (`z-sticky-nav` 300, above the bar's 200; they don't overlap) and `scroll-padding-top` (72px) clears its 57px. Probes at 320 to 1280 px: the bar never covers a link or button. At 320 px it covers only the © line (34 px, 58 px on main); between 40 and 60rem it covers only footer padding.
- [x] Keyboard: skip link, header, consent and hero order pinned by the spec in both schemes; `.slab :focus-visible` keeps a 2 px ring.
- [x] Docs and commits: subjects are Conventional (54, 38 and 49 characters); the squash "feat(frontend): lighter, tighter landing and app shell (#353)" is 61 characters. The superseded lint failure on `5f0f28f` is fixed by later commits.
- [x] Secret scan: 0 secrets; the only long strings are the lockfile's integrity hashes.

## Findings

- **N-2539:** `frontend.yml`'s `paths` list names `consent.spec.ts` and `app.spec.ts` but not `landing-a11y.spec.ts`, so a PR that only edits the new spec skips the Frontend job. Add it (or `tests/e2e/*.spec.ts`).
- **N-2540:** the consent padding is two fixed steps (14rem up to 40rem, then 7.5rem). The bar is 242 px at 320 px against 224 px reserved, and 167 px between 40 and 60rem against 120 px. Today that covers only the © line or padding, but the spec checks 390 × 844 only. Optional: probe 320 and 768 too, or add a 40 to 60rem step.
- **N-2541:** `contrast.mjs` has no pair for the focus ring on the dark header (8.05:1 today). Optional: add `focus` on `--color-header` for both schemes.
- **N-2542:** the two merge subjects ("Merge branch 'main' into …") aren't Conventional; the squash replaces them.

0 High, 0 Medium, 0 Low and 4 Nits (next free id: 2543).

## Verdict

**PASS** at `11b18a1a`, 0 behind main `4637fa18` (v3.61.0). The doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #353"), followed by the hash check and green CI on that head. Squash as "feat(frontend): lighter, tighter landing and app shell (#353)". As a `feat`, this cuts a minor release.
