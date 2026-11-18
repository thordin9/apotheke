# QA: apotheke #319, plan limit key photos_per_item (control migration 0004)

- PR: https://github.com/thordin9/apotheke/pull/319 (Coder), base `main`. Adds the `plan_limits` key `photos_per_item` through control migration `0004_plan_limits_photos_per_item.sql`.
- Reviewed head: `0becb3617e1c895fca3a9bb50fab9b64e9014146`, the plain merge of `970300396f5c8dd4541f2207907307fd9f6ecb8d` and main `562bc22a14d0fbaa42cd1f6153551dcf7343c4d2` (the #311 squash onto v3.45.0; single parent `28373bcd`, VERSION still 3.45.0). 0 behind current main `562bc22a`, 3 ahead. Parents are `97030039` and `562bc22a`.
- Prior review: **PASS on content** at `970300396f5c8dd4541f2207907307fd9f6ecb8d`, 0 behind then-main `28373bcd44e4f3dec6e2059e1b5aade57bcbabf6` (v3.45.0). This reissue supersedes that local doc (it was never committed).
- Gate: the full gate at `97030039` (diff, edge suites as CI runs them, local D1 apply, 20 migration mutants, docs, commits, secret scan), plus this merge-only read of the catch-up onto `562bc22a`. PHP is not touched. No suites, mutants or Docker in this read.
- CI on `0becb361`: Deploy edge 44360711635 and CodeQL (PR #319) 44360702824 succeeded (Check and dry run green; Deploy apotheke-api skipped). CI 44360711177 was still running at this read (15 success, 7 in progress), including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql), all 3 Visual E2E shards, and both Docker build + smoke jobs. Secret scan, Conventional Commits and D1 (local workerd) have succeeded. `mergeable_state` is blocked while CI runs. Prior CI on `97030039` (CI 43477216525, Deploy edge 43477216453, CodeQL 43477206563) was fully green, including both required Tests jobs.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Merge-only read of `0becb361`: parents are `97030039` and `562bc22a`. behind_by is 0 against current main (main has not moved). `git merge-tree --write-tree` of those parents conflicts only on CHANGELOG.md (tree `4fb3dc32…`); the only diff vs the head tree `974ce4b4…` is CHANGELOG. The PM kept #311's Maintenance bullet and added #319's Added bullet above it (same text as at `97030039`, linking https://github.com/thordin9/apotheke/pull/319) under `### Added`, then `### Maintenance`, which matches the file's Keep a Changelog heading order. Released sections from `## [3.45.0]` down are byte-identical to main. VERSION equals main (3.45.0). `gh api compare 97030039...0becb361` brings in only main's #311 files plus that CHANGELOG resolution. Per-file numstat of `562bc22a...0becb361` equals `28373bcd...97030039` (4 files, +71/−3). Every non-CHANGELOG PR file is blob-identical to `97030039`; every incoming main file is blob-identical to main. The `0004` file and its sha256 pin are unchanged. Nothing dropped.
- [x] Scope vs main `562bc22a`: 4 files, +71/−3, same as at `97030039`: the new `0004` (17 lines), `edge/tests/control-migrations.test.mjs` (+46/−1), `docs/operator-console.md` and CHANGELOG. `edge/src` is unchanged, so `limits.ts`, `route.ts` and `LIMITS_ENABLED` handling are untouched.
- [x] Key semantics (prior gate; blobs unchanged): the key is allowed but not enforced, and no plan gets a value. No row means no limit. The route query only loads rows with a hard value, and only when `LIMITS_ENABLED` is "1". A row would reach `parseLimits` and `X-Apotheke-Limits` but nothing reads it. Primary is unaffected. There is no comparison here, so there's no off-by-one to check.
- [x] `0004` rebuilds `plan_limits` (prior gate; file and pin unchanged):
  - It keeps the same columns, types, `NOT NULL`, value checks, `soft <= hard`, the PK and the FK to `plans`. The key `CHECK` is 0001's list plus `photos_per_item`.
  - Nothing references `plan_limits`: there's no FK, trigger, view or index on it besides its PK autoindex.
  - It is forward-only and adds no rows. Numbering continues from 0001-0003, and 0001-0003 are byte-unchanged.
- [x] Additive-only guard (prior gate; blobs unchanged): an allow-list permits exactly `DROP TABLE plan_limits;` and the rename, once each and only in `0004`. A mutant adding another `DROP TABLE` is killed.
- [x] Tests, run as CI runs them at `97030039` (Node 24, `npm ci --ignore-scripts`): deploy-scripts + edge pass 182/182 (main then had 180/180, so 2 are new). `tsc --noEmit` and `wrangler types --check` are clean, and `check-links` reports 391 links with 0 broken. Not re-run on this merge-only read.
- [x] Local D1 and 20 mutants at `97030039` stand: rows kept, second apply is a no-op, a mid-file failure rolls back, the pin kills all 20 file mutants; with the pin re-hashed, M16 and M20 are the N-2200 gaps. Not re-run here.
- [x] CHANGELOG: one Added bullet under [Unreleased] that links the full PR URL, placed above #311's Maintenance bullet. The released sections (3.45.0 down) and the header are byte-identical to main.
- [x] Commits: the two feature subjects are 67 and 53 characters, both Conventional. The merge is exempt.
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

0 High, 0 Medium, 1 Low (latent) and 3 Nit, all open, none blocking. No new findings (next id 2201).

## Verdict

**PASS** at `0becb361`, 0 behind main `562bc22a` (#311 squash; VERSION 3.45.0), confirmed by the merge-only read. Highs and Mediums block; none are open.

Not yet cleared to merge. The replacement docs(qa) commit must land as the last commit, then the hash check and marker, and full CI on the final head must be green, including both required Tests jobs. If main moves before merge, the PR needs a plain catch-up (conflicts reported as conflicts), a merge-only read and a reissued doc.

Squash as "feat(edge): plan limit key photos_per_item (migration 0004)" (N-2199). Fix L-2197 with or before the app-side `max_photos` change. The Nits can follow there too.
