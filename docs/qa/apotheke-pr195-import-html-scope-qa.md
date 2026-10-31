# QA: PR #195, HTML import pages scoped to uploader or admin (N-1441)

- **Head:** `8700480735ea850e7aefb966568e53655cc62e65`, 5 commits on main `bd92a10`; main has since gained `cc32e35` (#194), 1 behind, mergeable CLEAN. Same repo. CI 20/20 green incl. required `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)`.
- **Diff:** 11 files. First round (`27f8513`): ImportController page check and `?done=` notice, Imports `admin()` and `recent()` filter, Ci4ImportHtmlScopeTest. Delta `27f8513..8700480` (PM: L-1500 option b): Imports `guest()` and the upload refusal, Services guest closure, ImportView form hidden for guests, API 403 mapping, two tests, CHANGELOG, user guide, api.md, OpenAPI, HANDOFF.
- **Verdict: PASS.** L-1500 and L-1501 fixed. No H/M. L-1520 is a follow-up test gap; N-1502 accepted as backlog.

## Checklist

- [x] **Page scope.** `/import/{id}`, `/preview`, `/commit` call `Imports::mine()` (actor equals username, or admin) right after the lookup, before the done redirect, `rows()` and any write; same helper as the API. No schema change, no migration, routes unchanged.
- [x] **404 identity (SQLite + PG17).** For another editor and a guest, open and done imports answer exactly as an unknown id on GET, HEAD, GET/POST preview and commit, POST, PUT, DELETE: status, headers (except Date/Set-Cookie) and body, per-request nonce/CSRF normalised. 54 pairs; nothing changed.
- [x] **Ordering.** CSRF (419) and consent (403) run before the lookup and before the guest refusal, with identical answers for other and unknown ids.
- [x] **History and `?done=`.** *Recent imports* lists only your own (admin: all, guest: none); another user's page is byte-identical before and after others' uploads. `?done=` for another user's id equals the unknown-id page apart from the echoed id.
- [x] **Guest upload refused (L-1500).** With the Guest role granted Import and guest browsing on: `/import` shows the reason and a sign-in link and no upload form; HTML POST with a real CSV, binary data, no file or a 6 MB body is 403 with the message; API multipart upload is 403 `permission.denied` (problem+json). Data directory files, `imports` (including the max id), `import_rows`, `jobs` and `items` are unchanged before/after on SQLite and PG17. The refusal is the first statement of `Imports::upload()`.
- [x] **Others unaffected.** A signed-in editor keeps the form and gets 303 (HTML) and 201 (API); with sign-in turned off the anonymous admin still sees the form and uploads (the closure tests `anon`, and CLI is exempt).
- [x] **Docs.** CHANGELOG `[Unreleased]`, user guide, api.md and the OpenAPI `createImport` text (403 response present) describe the guest refusal accurately. Released CHANGELOG sections identical to main.
- [x] **Other routes.** Only the three HTML routes, `/import` and the four scoped `/api/v1/imports…` routes expose import data; no list, delete or download route. `imports` is read only by Imports.
- [x] **Mutations.** First round: the PR's six and two own, killed. Delta: guest history listing every import (former survivor) and refusal removed, both from the PR, killed; own: refusal moved after the file store, form shown to guests, API 403 mapped to 422, guest closure not wired, killed. Own: closure keyed on `guest` instead of `anon` (blocks the sign-in-off admin) survives the suite (L-1520).
- [x] **Per-request state.** No new static or cached state; the guest, admin and actor checks are closures evaluated per call.
- [x] **Suites.** Root 444 OK on SQLite (6 skipped) and PG17 (2 skipped); modules 901 OK (SQLite and PG in one run). Lint OK, deptrac 0, `routes --check` and `apotheke:routes --check` OK, gitleaks 8.30.1 clean on the delta.

## Findings

**L-1500 (fixed):** a guest with Import could upload into a dead end. Now refused before anything is stored, with a clear message, form hidden; API 403.

**L-1501 (fixed):** the guest branch of the history filter is now covered (guest sees no imports and gets the unknown-id 404).

**L-1520: the sign-in-off upload path is not pinned against the guest refusal.** If the guest check keyed on `guest` rather than `anon`, an install with sign-in turned off (everyone the anonymous admin) could no longer import, and no test would fail. **Fix:** add a sign-in-off upload case to Ci4ImportHtmlScopeTest. Not blocking: the current code is correct.

**N-1502 (accepted, backlog):** the layout-wide theme form echoes the escaped request URL, so `/import?done=` length follows the id's digits only; no existence or ownership signal.

N-1442 (ownership by user id) is tracked for P4 and not re-raised.

## Security

An import is visible and actionable only for its uploader or an admin, on the pages and the API; others get the unknown-id answer before any status or file access, and the history and done notice are filtered the same way. Guest sessions cannot upload at all, and a refused upload writes no file or row. CSRF and consent gates still run first. No new static state. Testing used a private scratch tree and a throwaway PG17 cluster on a free local port.
