# QA: apotheke #96, CI4 Taxonomy module (locations, categories, tags), N-18 and N-19

- **PR:** [#96](https://github.com/thordin9/apotheke/pull/96) (into `feature/ci4`). This is a full gate: the PR adds POST routes that need a permission and changes `PermissionFilterTest`. It was re-checked after the review fixes.
- **Head:** `c188cd9`. **Base:** `feature/ci4` `7ebb484`. 9 commits; the first round was at `15cc7f8`.
- **Verdict: PASS at `c188cd98081706da30dc95e9445e668c95198932`.** 0 High, 0 Medium, 0 Low open; 1 new Nit.

## Checklist

| Check | Result |
|---|---|
| CI | 22/22 at both heads, as reported by Coder2. |
| Routes and permissions | View for GET, Edit for every POST, as in legacy `route_perm()`. Legacy and CI4 were compared side by side over HTTP on SQLite and PostgreSQL 17: 367 requests, sign-in on (guest off and on) and off, signed out, viewer, editor and admin. Refusals match, and the tables end up identical. Slash variants keep the route's permission. |
| `PermissionFilterTest` | Not weakened. `sample()` now expands every alternative, and all 24 GET/HEAD paths and every POST alternative are checked against `route_perm()` (N-27). |
| Parity test | `assertSame` on status, type, Location, Cache-Control, JSON, body and 4 tables. It now also covers the editor, the guest with view, signed out, and 6 failing-trigger cases (500 with tables unchanged, the same answer in both apps). It isn't vacuous: several mutants are caught only by it. |
| Failed writes (M-25) | Every write in `TaxonomyRepository`, `ActivityLogger` and `TagRepository` goes through `Core\Database\Writes`. No direct `query()`, `insert()`, `update()` or `delete()` is left in the module. Over HTTP, a trigger made each statement fail on SQLite and PostgreSQL 17, 12 cases: add, add under a parent, rename, default on and off, move, and delete with move (history insert, item update, sub-location update, delete). Category delete (delete, history over fetch) was also covered. Every case gave a 500 in both apps, with no change to any table, and the next write gave 303. |
| Transactions | CI4 joins nested transactions without a SAVEPOINT on both drivers. An inner failure decrements the depth and rethrows, and the outermost call rolls back. The flag is reset only at the outermost level, and only after the status check or the rollback, so it can't hide a failure in the current transaction. A transaction that fails ends at depth 0 (asserted). See N-32. |
| Messages and logs | The 500 page and JSON are generic. The app log line names only the step (`Database write failed: add`). CodeIgniter's own error log keeps the driver message and a trace, with no SQL text, the same as other DB errors. |
| Escaping, SQL, tags | All names are escaped in pages, and JSON is `application/json` with nosniff. SQL uses bound values and fixed identifiers. No migration or schema change. |
| N-18, N-19 | Closed. Control characters in `back` give 303 to `/`. The 419, same-origin 403 and 413 answers carry no-store, as JSON and as HTML. |
| Tests, PHP 8.4 | CI4 module suite: SQLite 250 OK, PostgreSQL 17 310 OK. Full suite (`APOTHEKE_APP=ci4`): SQLite 612 OK (39 skipped, 1 incomplete), PostgreSQL 17 612 OK (5 skipped). Legacy (PHP 8.3, SQLite): 612 OK (78 skipped, 1 incomplete) on two clean reruns. The first run had one failure while 3 other jobs were running, and it didn't come back. |
| Mutations | First round: 30 of 30 caught. Re-check: 9 of 13 caught. Removing `check()`, the status check, the read check, the rollback, both flag resets, the history, rename or add checks, or the tag transaction is caught. Survivors: the commit-failure branch is untested, which is hard to trigger. The remaining three are equivalent: each flag reset alone (the other one covers it), and the item-update check inside a transaction (the status check covers it). |
| Worker phase | `Writes` is static and stateless. The flag is reset per transaction, so a long-lived worker can write again. Nothing blocks the FrankenPHP worker / Cloudflare Container phase. `Writes` lives in Core and can be reused by other modules (PM: project-wide). |
| Repo and docs | `.gitleaks.toml` unchanged; gitleaks 8.30.1 finds nothing. `check-commits` ok (9). deptrac and the DI rule: 0 errors. `lint.sh` OK. `check-links`: 0 broken. From `## [2.` to EOF the CHANGELOG is identical to `main` `bbd53c4`. 54 `docs/qa` files match. HANDOFF is accurate. |

## PR deviations

All accepted. (1) Slash variants are served as the route, with the same permission (PM: accepted). (2) A POST with no token gets 419 before 404. (3) The `no-store` wording on redirects and 422 pages differs. (4) `/theme` with control characters (N-18). A difference not listed is stricter than legacy: an overflowing id on PostgreSQL gets 404 where legacy gets a 500.

## Findings

| ID | Grade | Status | Finding |
|---|---|---|---|
| M-25 | Medium | Closed | A failed statement now rolls back and answers 500 as on legacy. Reproduced closed above; `WriteFailureTest` and the parity triggers cover it. |
| N-26 | Nit, docs | Closed | The CHANGELOG and the PR body list the doubled leading slash and several trailing slashes. |
| N-27 | Nit, tests | Closed | The parity test covers the editor, the guest and signed out; `sample()` checks every alternative. |
| N-32 | Nit, docs | Open | Before other modules use `Writes::transaction()`, its docblock should say that nesting is flat (no SAVEPOINT). A caller that catches an inner `UserError` and goes on commits the inner statements, as legacy `db_tx()` does. A caught inner `WriteFailed` is still refused at the outer commit. Suggest a test for a failed commit as well. |
| N-18, N-19 | Nit | Closed | See the checklist. |
| #84 N-2, N-3, N-5 | | | Not affected (main). |

## Re-check (15cc7f8..c188cd9)

4 commits: `Writes` and `WriteFailed`, checked writes in Taxonomy and Activity, `WriteFailureTest`, parity visitors and triggers, `sample()` expansion, CHANGELOG and HANDOFF. The changes were re-verified by HTTP trigger tests on both drivers, by mutation, and by the full suites. No legacy code changed.

## Security

- Every new write needs Edit, CSRF and (with sign-in off) same origin, and slash variants keep that permission.
- Names are escaped; SQL uses bound values only.
- Hardening: M-25 is closed. A failed statement can no longer half-commit or report success. The answer carries no SQL or paths.
- `/theme` refuses control characters in its redirect target (N-18), and refusals aren't cached (N-19).
- Worker phase: nothing new blocks the FrankenPHP worker / Cloudflare Container phase.

**Verdict: PASS at `c188cd98081706da30dc95e9445e668c95198932`** (0H / 0M / 0L open; N-32 can follow).
