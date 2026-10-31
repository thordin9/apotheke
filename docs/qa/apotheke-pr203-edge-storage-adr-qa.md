# QA: PR 203, P4-1 edge storage ADR (docs only)

- PR: thordin9/apotheke#203, `docs/p4-edge-storage-adr`, by Coder2
- Head: `4286177d2b813caf094699c8085993c21b054682` (3 commits), merge-base `774534e`, mergeable `clean`
- Behind main by 1 (#202, a deploy fix). It touches none of this PR's files.
- Diff: +359/-5 in 4 files, docs only. Round 2 is the delta from `a723823`: `docs/adr/002-edge-storage.md` and `docs/HANDOFF.md`.
- Scope: a design read plus hash and repo checks. Docs only, so no suites.

## Verdict

**PASS.** The round 1 findings are fixed. M-1540 is resolved by the project owner's decisions of 2026-10-29 and 2026-10-31, which replace the 2026-10-22 phase. Three new notes, all N.

## Checklist

| Check | Result |
|---|---|
| Head, CI | Head unchanged. 20/20 success, including `Tests (PHP 8.4, sqlite)` and `Tests (PHP 8.4, pgsql)` |
| `CHANGELOG.md`, `.gitleaks.toml` | Not touched by the PR. Released sections equal main's, which only adds #202 under Unreleased |
| gitleaks 8.30.1, `a723823..4286177` | 2 commits, no leaks |
| No account ids, database UUIDs, hostnames or tokens | OK. No UUID or 32-hex string. The D1 id is a repository variable |
| Status | Cites the owner's 2026-10-29 and 2026-10-31 decisions by date and content only. Neutral, no business details |
| Plan B (Postgres through Hyperdrive) | Coherent: when it applies, the relay, and no SQL change. See N-1561 |
| Hyperdrive from a Container | Unsupported, which matches cloudflare/containers#97 (open). See N-1560 |
| Transaction inventory | Recounted at 34: 30 `Writes::transaction` code sites in 20 files, plus 4 direct (`Imports.php:369`, `CsvExport.php:115`, `Backups.php:347`, `PdoMigrationRunner.php:147`) |
| Bindings vs #204 (`3a87467`) | Match `edge/storage.jsonc`: `DB` `apotheke-api`, `STORAGE` `apotheke-api-storage` `eu`, `INSTALLATION` → class `Installation`, `v2` `new_sqlite_classes`, `drop_vars: ["COOKIE_DOMAIN"]` |
| Merge only behind `EDGE_STORAGE_READY` | Matches #204's `edge-storage-config.mjs` and `deploy-edge.yml`. `cf-worker-guard.mjs` refuses `env` sections and a second config |
| Cookies | `edge/src` forwards `COOKIE_DOMAIN ?? ""`, and `Consent::domainFrom('')` gives host-only. Consistent with #188 and #204 |
| Classic FrankenPHP | `docker/frankenphp/Caddyfile` has a plain `php_server`, with no `worker` in the Caddyfile or the entrypoint. `WorkerMode.php` and `FilterStateTest` exist |
| Self-hosted PostgreSQL | PDO path unchanged. P4-2 parity gate added (L-1542) |

## Closed

- **M-1540, resolved by the owner.** The Status now records the owner's 2026-10-29 decision (edge storage: D1 or Durable Object SQLite, plus R2, with Docker self-hosting kept) and the 2026-10-31 one (per installation: one Container and one Durable Object with SQLite, an EU R2 prefix, and a D1 control plane). The 2026-10-22 phase is kept as plan B, with the spike's reasons.
- **M-1541, fixed.** Every `Installation` id comes from `env.INSTALLATION.jurisdiction("eu")` through one helper in `edge/src`. A test checks that no other code path creates one. Gate item 4 adds an EU check on the preview before any real installation is created.
- **L-1542, fixed.** The inventory matches the code: 34 sites, with a strategy for each of the 4 direct ones. The two PostgreSQL-only snapshots are stated as such. `Imports::run` maps to per-row batches, which also covers its per-row `SAVEPOINT imp_row`; the `trgm_probe` savepoint is PostgreSQL-only. The P4-2 gate is the full suites on SQLite and PostgreSQL 17, plus a test that the unit of work on PDO and the batch executor give the same rows and ids.
- **N-1543, fixed.** The binding names match #204's actual files, see the checklist.
- **N-1544, fixed.** The new section "Request state (FrankenPHP)" says the image runs in classic mode (verified in the Caddyfile). The storage code is written for worker mode: no statics, no installation context in PHP, and the unit-of-work registry empty at the end of every request. Both tests are planned for P4-2 and P4-3.

## New findings

**N-1560:** `cloudflare/workers-sdk#10320` was closed on 2025-09-30, when it became a feature request (discussion #10831). `cloudflare/containers#97` is the open tracker. Cite #97, or the discussion, as the place to follow.

**N-1561:** Plan B relays each statement through an outbound handler. An interactive transaction then needs one PostgreSQL connection held open across several handler calls. Say where that connection lives (for example, the container's Durable Object) and that it is released when the request ends. This only matters if plan B is chosen.

**N-1562:** The Status calls M-1540 a "decision record". M-1540 is a QA finding ID. "Resolving QA finding M-1540" would be more precise, or the reference could be dropped, since the dated owner decisions already stand on their own.

## Not checked

- No suites, because the PR is docs only.
- The spike's numbers (26 of 29 sites fit a batch) and the D1 limits table come from the spike, not from a re-run.
- The planned tests (the helper test, `FilterStateTest`'s storage rules, the worker test, the parity test) are design statements, to be gated in P4-2 to P4-5.
