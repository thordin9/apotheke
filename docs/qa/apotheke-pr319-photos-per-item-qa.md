# QA: apotheke #319, plan limit key photos_per_item (control migration 0004)

- PR: https://github.com/thordin9/apotheke/pull/319 (Coder), base `main`. Adds the `plan_limits` key `photos_per_item` through control migration `0004_plan_limits_photos_per_item.sql`.
- Reviewed head: `34c612de90137a75287a1ccd3bb3321b6cca3715`, the clean merge of `7c20856ce5f646f2af6f5a6d7898cd39ef53467f` (the previous approved head: docs(qa) on `0becb361`) and main `6afe9cb93af57d1d673a9d95d534ace56f38fe78` (the #316 squash onto `562bc22a`; test-only, no release; single parent `562bc22a14d0fbaa42cd1f6153551dcf7343c4d2`, VERSION still 3.45.0). 0 behind current main `6afe9cb9`, 5 ahead. Parents are `7c20856c` and `6afe9cb9`.
- Prior review: **PASS** at `0becb3617e1c895fca3a9bb50fab9b64e9014146` after the catch-up onto `562bc22a`, with the docs(qa) commit `7c20856ce5f646f2af6f5a6d7898cd39ef53467f` (parent `0becb361`). The earlier content review was at `970300396f5c8dd4541f2207907307fd9f6ecb8d`, 0 behind then-main `28373bcd`. This reissue supersedes the committed doc at `7c20856c`.
- Gate: the full gate at `97030039` (diff, edge suites as CI runs them, local D1 apply, 20 migration mutants, docs, commits, secret scan), plus merge-only reads of both catch-ups: first onto `562bc22a` with a CHANGELOG-only conflict, now this clean merge onto `6afe9cb9`. PHP is not touched. No suites, mutants or Docker in this read.
- CI on `34c612de`: Deploy edge 44365089966 succeeded (Check and dry run green; Deploy apotheke-api skipped). CodeQL (PR #319) 44365076062 succeeded (Analyze actions and javascript-typescript green; the CodeQL check green). CI 44365090063 was still running at this read (13 success, 9 in progress, 1 skipped), including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql), all 3 Visual E2E shards, both Docker build + smoke jobs, and both Docker Compose self-hosting jobs. Secret scan, Conventional Commits, D1 (local workerd), Lint, deptrac, DI rule, Headless mobile checks, and both Upgrade and rollback jobs have succeeded. `mergeable_state` is blocked while CI runs. Prior CI on `97030039` (CI 43477216525, Deploy edge 43477216453, CodeQL 43477206563) was fully green, including both required Tests jobs.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only reads of both catch-ups. First, onto `562bc22a`: parents of `0becb361` were `97030039` and `562bc22a`; `git merge-tree --write-tree` conflicted only on CHANGELOG.md; the PM kept #311's Maintenance bullet and added #319's Added bullet above it. Now, the clean merge `34c612de` of `7c20856c` and `6afe9cb9`: parents are exactly those two. behind_by is 0 against current main `6afe9cb9` (main has not moved). `git merge-tree --write-tree 7c20856c 6afe9cb9` equals the head tree `3d935226…` exactly; no conflicts. Released CHANGELOG sections from `## [3.45.0]` down and the header are byte-identical to main, and the full CHANGELOG is byte-identical to `7c20856c`. VERSION equals main (3.45.0); README equals main. [Unreleased] still holds #319's Added bullet (same text as at `97030039`, linking https://github.com/thordin9/apotheke/pull/319) under `### Added`, then #311's Maintenance bullet under `### Maintenance`. `gh api compare 7c20856c...34c612de` brings in only #316's four files (`docs/HANDOFF.md`, both `docs/qa/apotheke-pr316-uow-lock-held-qa*.md`, and `tests/Modules/Core/UnitOfWorkLockTest.php`). Per-file numstat of `6afe9cb9...34c612de` equals `562bc22a...7c20856c` (5 files: 4/0 CHANGELOG, 4/2 operator-console, 50/0 QA doc, 17/0 `0004`, 46/1 control-migrations test). Every PR file is blob-identical to `7c20856c`, including migration `0004`, the test with its sha256 pin `c2720cf2…`, and `docs/qa/apotheke-pr319-photos-per-item-qa.md`. Every incoming #316 file is blob-identical to main. Nothing dropped.
- [x] Scope vs main `6afe9cb9`: 5 files (the same 4 as at `97030039`, plus the QA doc committed at `7c20856c`): the new `0004` (17 lines), `edge/tests/control-migrations.test.mjs` (+46/−1), `docs/operator-console.md`, CHANGELOG, and `docs/qa/apotheke-pr319-photos-per-item-qa.md`. `edge/src` is unchanged, so `limits.ts`, `route.ts` and `LIMITS_ENABLED` handling are untouched.
- [x] Key semantics (prior gate; blobs unchanged): the key is allowed but not enforced, and no plan gets a value. No row means no limit. The route query only loads rows with a hard value, and only when `LIMITS_ENABLED` is "1". A row would reach `parseLimits` and `X-Apotheke-Limits` but nothing reads it. Primary is unaffected. There is no comparison here, so there's no off-by-one to check.
- [x] `0004` rebuilds `plan_limits` (prior gate; file and pin unchanged):
  - It keeps the same columns, types, `NOT NULL`, value checks, `soft <= hard`, the PK and the FK to `plans`. The key `CHECK` is 0001's list plus `photos_per_item`.
  - Nothing references `plan_limits`: there's no FK, trigger, view or index on it besides its PK autoindex.
  - It is forward-only and adds no rows. Numbering continues from 0001-0003, and 0001-0003 are byte-unchanged.
- [x] Additive-only guard (prior gate; blobs unchanged): an allow-list permits exactly `DROP TABLE plan_limits;` and the rename, once each and only in `0004`. A mutant adding another `DROP TABLE` is killed.
- [x] Tests, run as CI runs them at `97030039` (Node 24, `npm ci --ignore-scripts`): deploy-scripts + edge pass 182/182 (main then had 180/180, so 2 are new). `tsc --noEmit` and `wrangler types --check` are clean, and `check-links` reports 391 links with 0 broken. Not re-run on this merge-only read.
- [x] Local D1 and 20 mutants at `97030039` stand: rows kept, second apply is a no-op, a mid-file failure rolls back, the pin kills all 20 file mutants; with the pin re-hashed, M16 and M20 are the N-2200 gaps. Not re-run here.
- [x] CHANGELOG: one Added bullet under [Unreleased] that links the full PR URL, placed above #311's Maintenance bullet. The released sections (3.45.0 down) and the header are byte-identical to main.
- [x] Commits: the two feature subjects are 67 and 53 characters, both Conventional. The docs(qa) subject is Conventional. Both merges are exempt.
- [x] Secret scan of added lines at `97030039`: 0 secret or keyword hits and 0 UUID or 32-hex ids. The one 64-hex match is the migration's sha256 pin, unchanged on this head.
- [x] Prior #315 findings: #319 is independent of L-2171 to L-2175 and N-2176 to N-2179. It fixes none of them and makes none worse. L-2171 matters for the app-side follow-up (L-2197).

## Findings

- **L-2197 (latent, for the app-side PR that caps `max_photos`):** the value needs a trusted path to PHP.
  - Today the limits reach the container object only through `X-Apotheke-Limits`. With the switch off, a client's own header reaches PHP (L-2171).
  - If the app-side change reads `photos_per_item` from that header, a client could set its own cap.
  - Fix L-2171 in or before that PR, or deliver the value another way.
- **N-2198:** Two comments in the migrations are wrong now.
  - 0001's header still says "Additive only … never drop or rename". 0001 is pinned, so it can't be edited; `operator-console.md` documents the exception.
  - 0004's header says the app's `max_photos` "is capped by it", but that's the separate app change. It should read "will be".
- **N-2199:** The PR title is 67 characters, but with GitHub's default " (#319)" suffix the squash subject becomes 74, over 72. Squash with an explicit subject, such as "feat(edge): plan limit key photos_per_item (migration 0004)".
- **N-2200:** Test gaps, currently masked by the sha256 pin:
  - M16: dropping an existing key such as `import_file_bytes` from the rebuilt `CHECK` survives when no row uses it.
  - M20: adding `ON DELETE CASCADE` survives, because `fks()` compares only from, table and to.
  - Assert every 0001 key is still accepted after 0004, and compare the full `foreign_key_list`.

0 High, 0 Medium, 1 Low (latent) and 3 Nit, all open, none blocking. No new findings (next id 2230).

## Verdict

**PASS** at `34c612de`, 0 behind main `6afe9cb9` (#316 squash onto `562bc22a`; test-only, no release; VERSION 3.45.0), confirmed by the merge-only read of this clean catch-up and the earlier catch-up onto `562bc22a`. Highs and Mediums block; none are open.

Not yet cleared to merge. The replacement docs(qa) commit must land as the last commit, then the hash check and marker, and full CI on the final head must be green, including both required Tests jobs. If main moves before merge, the PR needs a plain catch-up (conflicts reported as conflicts), a merge-only read and a reissued doc.

Squash as "feat(edge): plan limit key photos_per_item (migration 0004)" (N-2199). Fix L-2197 with or before the app-side `max_photos` change. The Nits can follow there too.
