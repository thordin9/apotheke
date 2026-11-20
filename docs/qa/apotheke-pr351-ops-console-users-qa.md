# QA: apotheke #351, the console's installation users read

- PR: https://github.com/thordin9/apotheke/pull/351 (Coder), base `main`. `GET /api/ops/v1/installations/:id/users` in `apotheke-ops`, served through a new `listUsers(installation)` on `apotheke-api`'s existing `Ops` entrypoint, which reads the installation's internal `GET /users` bridge read. Account metadata only.
- Reviewed head: `dd1ad29758693c791e42ef7d3b10c0a8a1ef8de8`, 0 behind main `815a5abd` (v3.60.0). Content gated at `0b020bc7` (0 behind `1a9c9a3d`); catch-up merges `b4aeef9` (onto #350, `86a5e904`) and `dd1ad29` (onto the v3.60.0 release) read merge-only. Commits: `5a43151` (feat), `f534a2e` (docs), merges `47c2f7e` and `ac69a50`, `0b020bc` (feat), merges `b4aeef9` and `dd1ad29`.
- Gate: full (new code on the operator path). Full read of `edge/ops/src/api.ts`, `edge/src/ops-accounts.ts`, `edge/src/index.ts` (`Ops.listUsers`), cross-checked against `edge/src/bridge.ts` and `edge/src/installation.ts`; edge suite; type checks; 18 mutants; secret scan.
- CI on `0b020bc7`, all green: CI 44819597792, Deploy edge 44819597756, PR #351 44819589590. On `dd1ad297`: CI 44825789891, Deploy edge 44825789655, CodeQL 44825773693 (22 pass).
- Reviewed 2026-11-20 23:41 to 2026-11-21 00:30 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope (main...head): 8 files, +312/−12: `edge/ops/src/api.ts`, `edge/src/index.ts`, `edge/src/ops-accounts.ts`, two test files, `edge/ops/wrangler.jsonc` (comment only), `docs/operator-console.md` and CHANGELOG. No new binding, route, secret or migration.
- [x] Merges: parents `[f534a2e, d5e36e3a]`, `[47c2f7e, 1a9c9a3d]`, `[0b020bc, 86a5e904]` and `[b4aeef9, 815a5abd]`. Each differs from `git merge-tree` only in `CHANGELOG.md`, as every body declares. End state at `dd1ad297`: main...head is the same 8 files and +312/−12 as at `0b020bc7`; CHANGELOG differs from main only by the `[Unreleased]` / Added bullet with the full PR URL; `VERSION` equals main (3.60.0).
- [x] Guards: `primary` is refused in `apotheke-ops` (by id before the lookup and again by row) and in `listUsers` (`resolveOpsInstallation`). Unknown is 404, deleted 410, not provisioning or active 409 `not_startable`, no host 409 `no_host`. Access runs before any lookup or call (test). The container is not started and nothing is signed.
- [x] Metadata only: the bridge selects six fixed columns. Both `opsUser` (edge) and `consoleUser` (ops) rebuild each row from an allowlist; injected hash, token, session and email fields do not come out (spread mutants on both sides killed). The bridge's `read_failed` body (`sqlite`, `reason`) is reduced to its code.
- [x] Errors: a bad or missing field is 503 `unavailable` / `bad_answer`; a thrown binding call is 503 `unavailable`; `unbound`, `read_failed` and `http_<n>` go into `detail` (capped at 64); missing DB or OPS is 503 `not_configured`; other methods are 405 with `Allow: GET, HEAD`. No audit row, `no-store`.
- [x] Dates: `isoUtc` accepts the stored UTC form with or without `T`, fraction or `Z`, round-trips it (an impossible date gives null) and gives `…Z` or null.
- [x] Tests (Node 24, as CI runs them): 294/294; `tsc --noEmit` clean in `edge` and `edge/ops`.
- [x] Mutants: 16/18 killed (both ISO conversions, the round-trip check, `bad_answer` on both sides, `active`, empty `roles`, both spreads, guard skipped, POST allowed, OPS missing, a swallowed binding error, the `resolveOpsInstallation` refusal, `storageSelected`, `unreachable`). Survivors are N-2529 and N-2530.
- [x] Cloudflare phase: storage stays behind the `Installation` object, no per-request global state, no new config.
- [x] Docs and commits: the API section, the `listUsers` entrypoint paragraph and the "not served yet" list match the code. Subjects are Conventional (62, 43 and 55 characters plus " (#351)"). Squash "feat(edge): add the installation users read to the console API (#351)" is 69 characters.
- [x] Secret scan: 0 secrets. The only address is `example.test` in a test fixture.

## Findings

- **N-2529:** `opsUser`'s own type checks on `username` and `role` (edge side) have no test: dropping the `username` check passes, because `consoleUser` checks again in `apotheke-ops`. Defence in depth only. Optional: a `bad_answer` case in `ops-accounts.test.mjs`.
- **N-2530:** `opsUsersResult`'s code filter (`^[a-z_]{1,32}$`) is untested: passing any upstream `code` through survives. The bridge sends only fixed codes and `apotheke-ops` caps `detail` at 64 characters. Optional: assert that a long or odd code becomes `http_<status>`.
- **N-2531:** as declared, the list is unbounded and `next_cursor` is always null, and a suspended installation is 409 `not_startable` (the console can't list its users). Both are documented. Revisit with pagination or the suspended view if needed.
- **N-2532:** `docs/operator-console.md` (from #350) still says the users screen's `GET /installations/:id/users` stays planned; stale once this lands. Coder fixes it in the follow-up.

0 High, 0 Medium, 0 Low and 4 Nits (next free id: 2533).

## Verdict

**PASS** at `dd1ad297`, 0 behind main `815a5abd` (v3.60.0). This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #351"), followed by the hash check and green CI on that head. Squash as "feat(edge): add the installation users read to the console API (#351)". As a `feat`, this cuts a minor release (v3.61.0).
