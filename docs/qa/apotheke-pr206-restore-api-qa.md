# QA: apotheke PR #206, "feat(api): chunked restore endpoints"

**Verdict: PASS.** Reviewed head `0f37f736269e8a809564ef42e91f83e93dbde908`, 2026-11-01 (EET), full gate on #206's own delta over #205 (`caacb8c`):
- same repo, 7 commits, 12 files (+712/−9), CI 17/17, both Tests jobs green (CodeQL runs only on main bases);
- base `feat/chunked-restore` (#205); see N-1590 for merges.

## Checklist

- [x] **Permission.** All six routes need `backup`, the same as the restore page. The route table test lists them; changing any route to `view` is killed.
  - Signed out gets 401 and a viewer gets 403. A guest with `backup` gets 403 with nothing stored.
- [x] **Owner scope.** Another account's id gets the same 404 as an unknown id on GET, DELETE, chunk, finish and confirm. The status, body and headers are byte-identical (date and cookies aside).
  - Malformed, upper-case, traversal and encoded ids get 404.
- [x] **CSRF and consent.** Every write (create, chunk, finish, confirm, delete) goes through the API CSRF check: own Origin, Sec-Fetch-Site, the session token, and a JSON or multipart body.
  - A missing or wrong token, a foreign Origin or a cross-site request gets 403 `csrf.invalid`; `text/plain` is refused. Without consent, writes are refused.
- [x] **Caps and body size.** The #205 caps hold on every route: out of order 409, over the chunk size or past the end 413, short 422, wrong field 422. A 20-digit index gets 409.
  - The web server caps the body at 22 MiB before PHP (nginx and Caddy). `RESTORE_CHUNK_KB` (at most 16 MiB) fits under it.
- [x] **Apply path.** finish runs only `Restorer::open` (the page's upload check) and returns the dry run; confirm runs only `Restorer::restore`, which checks the zip again. This matches the page: the same 409 keep and the same removal on failure.
  - `{"confirm": true}` is required. The SHA-256, size and refused-backup cases remove the upload.
- [x] **N-443.** Settings are applied only with the `settings` permission. Tested: a keeper gets the catalog with a warning; an admin gets the settings too. Forcing the flag on or off is killed.
- [x] **Errors.** No filesystem path in any answer. A 500 is the API's fixed `server_error`; Restorer's own refusals are 422, as on the page.
- [x] **Docs.** `api.md`, OpenAPI, user guide, deployment and CHANGELOG `[Unreleased]` are accurate. `routes --check` is clean and the OpenAPI route test is updated. One exception: the "one upload at a time" wording (L-1581).
- [x] **Suites.** SQLite 451 OK; own PG17 cluster with password auth, 451 OK. Lint, deptrac and `routes --check` are clean. gitleaks 8.30.1 on `caacb8c..pr206`: no leaks.
- [x] **Mutations: 17 on the controller, routes and owner wiring, 11 killed.**
  - Killed: route permissions, Restorer bypass on finish, confirm flag dropped, N-443 on and off, cancel no-op, `next_chunk`, guest owner, one owner for all, `Location`.
  - Survived, harmless or covered by another check: the 404-first lookups on chunk and confirm (the store answers the same 404, no leak), `is_uploaded_file`, the 500 rethrow (the fixed text, no path), and the index-length guard (it still gets 409).
  - Also survived: a looser confirm check (N-1586).
- [x] **Carry-over L-1580.** #206's API tests cover none of the three guards. All three still survive the #205 plus #206 suites.
- [x] **Carry-over L-1581.** It is now reachable. Eight parallel creates by one account left up to six uploads.

## Findings

- **L-1580 (open, from #205):** The sweep's real-folder filter, the data file's link check and the id's start anchor are still untested. Fix: add store tests in #205.
- **L-1581 (open, now reachable via the API):** Parallel `POST /api/v1/restores` calls by one account can each keep an upload, up to `RESTORE_MAX_MB` each, until the idle sweep.
  - There is no rate limit on these routes and no overall cap or free-disk check. It needs the backup permission.
  - Fix in the store: a per-owner (or root) lock around create, plus an open-upload cap. Otherwise, reword "one at a time" in `api.md`, the OpenAPI and deployment docs.
- **N-1586:** Only a missing confirm flag is tested. A looser check (any truthy value) passes the suite. Add `1` and `"true"` cases, since confirm replaces the catalog.
- **N-1587:** The defaults assume the image's PHP limits (20 MB uploads). On a plain PHP host (2 MB default), every 8 MiB chunk gets 413 "larger than the server accepts".
  - Fix: clamp `chunk_size` to `upload_max_filesize` and `post_max_size`, or say so in the configuration docs.
  - The body is read before the permission check, as on every API write (unlike the page's pre-check).
- **N-1588:** finish (SHA-256 plus the full check) and confirm run under the API's 75 s proxy timeout. The page's upload step has 300 s.
  - A large backup can get 504 while PHP completes; GET then shows the outcome.
  - Fix: document it, or give `finish` and `confirm` the restore timeouts.
- **N-1589 (edge):** confirm is synchronous (200). Per ADR-002, a restore on D1 is chunked compensating work behind the maintenance flag, so on the edge confirm likely becomes a job (202 plus `/api/v1/jobs`).
  - Note it in P4 with N-1584 (the store behind `Core\Storage`, the lock in the Durable Object).
- **N-1590 (process):** #206 doesn't contain #205's QA doc commit `256dece`; it merges cleanly. #205 still conflicts with `main` (CHANGELOG), and so does #206. Both need merges and then merge-only reads; #206's own delta stays as reviewed.

## Security

- Every route needs `backup`, CSRF and consent; guests and other accounts get nothing, and other accounts can't tell an upload exists.
- Bodies are capped by the web server, and chunks and uploads by the store.
- Only Restorer checks and applies a backup, with N-443 kept.
- Errors carry no paths or internals, and there are no secrets in code, tests or docs.
- The abuse limit is per-account only, and not atomic (L-1581). Nothing is H or M.

## Verdict

**PASS.** No H/M findings and no decision needed. L-1580 and L-1581 go to Coder2 (store fixes in #205, docs in #206). N-1586 to N-1590 are backlog or process. Next free ID: 1591.
