# QA: PR #185, import commit job and GET /jobs/{id}

- **Head:** `dc916d9438fafa94fb8a44a58bb0194b82cc1f4e`. 22 commits, base main `c057394` (v3.4.0), 0 behind, same repo, mergeable CLEAN. CI 17/17 green on this head.
- **Merge re-check** (`7092283` #184 merge, `dc916d9`): conflicts only in CHANGELOG, RoutesCompiled and HANDOFF. The 0030 note is under `[Unreleased]` above `[3.4.0]`, and the released sections are byte-identical to main. Routes regenerate byte-identical. The scoping code is unchanged, and the owner/admin 404 tests are present and pass. The consent, imports and jobs API tests pass on SQLite and PG17. A test mutation that sends consent makes the new commit 403 test fail.
- **Re-gate delta** since `5c31f74`: fix `feaa932` (ImportsController::commit scope, Ci4ApiJobsTest, OpenAPI, docs/api.md, deployment-and-operations, HANDOFF), plus merges of #184 (`89cbdc5`, `73f406e`, `f397d57`). It also contains all of #184's fix `902841c`.
- **Verdict: PASS.** M-1380 fixed and N-1381 documented. No H/M open.

## Checklist (delta)

- [x] **Commit scope (M-1380).** `commit()` checks `Imports::mine()` before the done check, `JobQueue::active()` and the file read.
  - Other editor: 404.
  - Actor-NULL (guest) upload committed by an editor: 404.
  - Other editor with a JSON body (map plus create): 404.
  - Unknown id: 404.
  - All four answers are byte-identical: status, `application/problem+json`, length 103, same body.
  - The jobs count does not change after the rejected commits.
  - The owner gets 202, and an admin committing the guest upload gets 202 (jobs +2).
  - Verified on SQLite and PG17.
- [x] **GET /jobs/{id}.** Starter and admin get 200. The other editor gets a 404 byte-identical to an unknown id (`Job not found.`, 100 B), and so does a viewer. No job of another user is revealed.
- [x] **Other routes.** There is no list (`GET /api/v1/imports`, `GET /api/v1/jobs`) and no `DELETE`; all return the generic API 404.
- [x] **Show/preview (from #184).** The same matrix and timing results as in #184's review hold on this head, on both drivers.
- [x] **N-1381 documented.** docs/api.md (Background jobs), the OpenAPI `getJob` text and docs/deployment-and-operations.md now state:
  - after SIGTERM with pcntl, the job is released at once;
  - after SIGKILL, an OOM kill or a crash, the job stays `running` for up to the 300 s lease, then resumes from its last checkpoint.
- [x] **Mutations (own run, all killed):**
  - commit check dropped (cross-user test fails);
  - owner clause dropped or forced true;
  - admin bypass dropped or not wired;
  - 403 instead of 404 on commit;
  - formula flags emptied.
- [x] **Merges.** `--remerge-diff` shows conflict resolution only.
  - ImportsController keeps both the `JobQueue` and `CsvCells` imports and both `formulaCells()` and `commit()`.
  - docs/api.md takes the scoped rows and adds the commit and jobs rows.
  - openapi.yaml keeps the commit/Job and the #186 users/branding entries (23 paths, valid JSON).
  - The CHANGELOG `[Unreleased]` upgrade note sits above `[3.3.0]`; the released sections are identical to main.
  - `php spark apotheke:routes` output is byte-identical to the committed `RoutesCompiled.php`; `routes --check` OK.
- [x] **No schema change in the delta.** Migration 0030 is as previously reviewed; `.gitleaks.toml` is unchanged.
- [x] **Suites.**
  - Root: SQLite 367 OK (5 skipped); PG17 367 OK (1 skipped).
  - Modules: 864 OK (SQLite and PG in one run).
  - Lint OK, deptrac 0, gitleaks 8.30.1 clean on `5c31f74..f397d57`.
- [x] **Unchanged since the last review:** kill-resume on both drivers, double-commit idempotency, HTML parity and the 0030 registration. Only the scope check and docs changed.

## Prior findings

- **M-1380:** fixed (`feaa932`), verified on both drivers.
- **N-1381:** documented (lease wait after SIGKILL/OOM).
- **N-1382:** closed. #182 cookie consent is on main (v3.4.0) and merged here. `POST /imports/{id}/commit` is 403 `consent_required` without consent, and `GET /jobs/{id}` is in the consent matrix.

## New findings

- **N-1440 (main):** `Ci4BranchCiTest` drops and recreates the fixed table `public.apotheke_ci4_pgsql_probe` on the default `127.0.0.1:5432` even in SQLite runs, so parallel local runs race. See #184's review.
- **N-1441 (main, pre-existing):** the HTML `/import/{id}/commit` is not owner-scoped (legacy parity). HANDOFF records it as pending a PM decision.
- **N-1442:** the scope is keyed by username, as `jobs.actor` is. A rename or a same-name re-creation of an account moves ownership.

## Security

Commit, show and preview are limited to the uploader or an admin, with an identical 404, and a rejected commit queues nothing. Job reads stay starter-or-admin. Resume is idempotent via `import_rows`. Testing used private scratch and PG17 on port 55493.
