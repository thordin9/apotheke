# QA: PR #147, CI4 QA notes batch 4 part A (routes, import, export)

Full gate. Verified head: 983e8cedcd4aafdc0a93bf8d130c8722d508dff7. CI: 25/25 green, 0 commits behind feature/ci4, merges cleanly (also with #149). Wed 28 Oct 2026, 20:21 to 21:00 EET.

## Verdict

**Approve.** Every claimed item is closed: N-380, N-381, N-420, N-421, N-422, N-424. There are no new findings. The PR claims none of the batch-4 restore or CLI IDs; those are in #148 and #149. N-373 stays pinned until the `main` port. N-423 belongs to the Cloudflare phase, and the deferral holds.

## Checklist

- [x] **N-380, auth routes.** `WebRoutes::every()` registers the nine web methods through the collection's own `create()`, with the same options on each, and no command-line route. `/login`, `/register` and `/account` keep their permission (`none` and `self`) on every method. Auth parity is unchanged.
- [x] **N-420, import routes.** OPTIONS, TRACE and CONNECT on `/import`, `/import/{id}` and `/import/{id}/(preview|commit)` answer as on legacy. Each takes the `import` permission, and a viewer gets 403. CONNECT needs the CSRF token, like the other non-safe methods (a recorded difference).
- [x] **N-421, export bounds (PostgreSQL).** The read-only snapshot sets a statement bound and an idle-in-transaction bound for itself only. Both are in force while the export is open and are back to the server values afterwards.
- [x] **N-422.** One tag query per batch, each reading only that batch's items.
- [x] **N-424.** `Ci4ImportRaceTest` runs on legacy and CI4. The import parity holds on SQLite and PostgreSQL.
- [x] **N-381.** The docblock is back on `titles()`.
- [x] **Mutations** (module tests, SQLite and PostgreSQL):
  - each of these fails a test: dropping the batch filter, dropping either bound, routing `/login` through `add()`, leaving TRACE out of the method list, and giving `/import` a weaker permission;
  - session-wide `SET` instead of `SET LOCAL` is equivalent here, because the export always ends with a rollback.
- [x] **Suites.**
  - Module suite with PostgreSQL configured: 685 tests, 0 failures.
  - Root suite filtered to the import, auth, HTTP and parity tests, on CI4 and legacy × SQLite and PostgreSQL: 0 failures caused by this PR.
  - Two tests failed only because the local test role cannot create roles. Neither file is touched by this PR, and both are green on CI.
- [x] **Lint, commits, architecture.** `scripts/lint.sh` passes; 8 Conventional Commits; deptrac reports 0 violations; the `di` check is OK.
- [x] **Docs.** The CHANGELOG and HANDOFF entries match the code. The overlap note for #144's compiled routes is accurate.

## Findings

| ID | Sev | Status | Finding |
|---|---|---|---|
| — | — | — | No new findings. |

Note for the PM: whichever of this PR and #144 merges second must regenerate the compiled routes, as the PR says.

## Security

- No change to permissions: every method on these routes takes the same permission as GET, and non-safe methods still need the CSRF token.
- No command-line route is left on the web pages.
- The export can no longer hold a PostgreSQL snapshot open indefinitely.
- No secrets in the diff.
