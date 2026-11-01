# QA: apotheke PR #215, "fix(backup): report owner lookup failures as server errors"

**Verdict: PASS.** Reviewed head `da207bef7a1a60bc20065b9ef685bdeec559be18`, 2026-11-01 (EET), targeted gate (nit follow-up):
- same repo, base `main` `dbcf259` (v3.15.0, with #214), 0 behind, 5 commits (one a main merge), 12 files (+111/−19), no migrations;
- CI 20/20 green on the head.

It closes #213's N-1634 to N-1636.

## Checklist

- [x] **Diff read in full:** `UserRepository::createdAt()`, `RestoreUploads::me()`, the owner closure in `Services`, three test files, CHANGELOG, HANDOFF and four docs.
- [x] **N-1636, unreadable account.** `createdAt()` returns null only when there is no such account. A failed read throws `WriteFailed`, whether the query returns false or the driver throws.
  - `me()` turns that into a 500 `UserError`. The API rethrows any 500 not in its own map, so the answer is the fixed `500 server_error` with the generic text: no internals and no store message. Every operation goes through `me()`, so nothing is created, read or restored; tested for create and get.
  - A guest still gets 403. A signed-in account that is gone resolves to a guest earlier (`CurrentUser`), so it gets 403 too; the closure's null for a missing row is a second guard.
  - The owner key is only `local` (sign-in off), `user:<id>:<sha256 of created_at>`, or none. An error never yields a key, so no request can act as another owner.
- [x] **N-1634, tests.** The new eviction test keeps idle uploads below the cap and removes them at it. It kills the evict-on-every-start mutant that survived on #213. The unreadable-account path is tested in the store (500, nothing stored, guest still 403), the repository (SQLite and PG) and the API (fixed 500, then 201 once readable).
- [x] **N-1635, trade-off.** Configuration, deployment and operations, api.md, OpenAPI and CHANGELOG state it: at the cap, a fully sent upload left unconfirmed for 10 minutes can be removed by another account's start. They also say how to raise `RESTORE_MAX_UPLOADS`. The wording is neutral and consistent; an upload in use or being confirmed is never removed.
- [x] **Merge of main:** only a CHANGELOG conflict, resolved cleanly (`[Unreleased]` note above `[3.15.0]`). Release sections, VERSION, README, `docs/qa` and `.github` are identical to `main`.
- [x] **Targeted tests on SQLite and on my own PG17 cluster with password auth:** all OK.
  - `RestoreUploadsTest` 26 tests;
  - `SignInAccountsTest` 38 (SQLite) and 65 (PG);
  - `Ci4ApiRestoresTest` 11;
  - `Ci4BackupRestoreChecksTest` 31 (3 skipped on SQLite).
- [x] **Mutations: 9 on the changed code, 7 killed.** The survivors are in N-1642.
- [x] **Tooling:** gitleaks 8.30.1 on `main..pr215` found no leaks, and `routes --check` is up to date.

## Findings

- **N-1641:** OpenAPI names the 500 only in `createRestore`'s description.
  - No restores operation lists a `500` response; this matches the rest of the spec, which lists none.
  - The other restore operations answer the same 500 on this fault without saying so. api.md covers them all.
  - Optional: a shared `500` Problem response, or one sentence on the restores tag.
- **N-1642:** Two branches have no test; both were checked by reading the code.
  - The driver-exception branch of `createdAt()`. The tests break the query, which takes the "query returned false" path, so turning the catch back into `null` survives.
  - The closure's null for a row that disappears between sign-in and lookup. It can only happen in a race, since `CurrentUser` already treats a missing account as a guest.
- **N-1584 and N-1589 (open, P4):** unchanged.

## Security

- A database fault while identifying the account fails closed with a generic 500. It is never treated as another account, the local admin or a guest with access.
- No upload is created, read, sent, finished or confirmed on that path, and the API exposes no message, path or exception detail.
- Guest and permission checks, CSRF, owner binding (N-1594) and eviction rules are unchanged. The only behaviour change is the 403 becoming a 500 on a database fault.
- No secrets in the diff. Nothing is H or M.

## Verdict

**PASS.** No H/M findings and no decision needed. N-1634 to N-1636 are fixed; N-1641 and N-1642 go to the backlog. Next free ID: 1643.
