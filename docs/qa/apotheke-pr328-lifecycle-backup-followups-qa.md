# QA: apotheke #328, lifecycle backup follow-ups (L-2230, L-2231, N-2232 to N-2235, L-2260 to N-2264)

- PR: https://github.com/thordin9/apotheke/pull/328 (Coder), base `main`. It fixes the findings from #324's gate and from this PR's earlier rounds. It was stacked on #324, which is now squashed into main as `bc400108`. No new vars or secrets, no PHP change.
- Reviewed head: `6b5c7c5fbcf6720c2fed45e01be811702aeb22d5`, a merge with parents exactly `ff5e5040` (the N-2264 tests on `160e9b71`) and main `b3c81498` (v3.49.0). 0 behind, 8 ahead. `mergeable_state` clean, not a draft. Against main it changes 9 files, +361/−64.
- Gate: content was gated at `0f916af2` and `160e9b71` (both PASS on content). This round is a light re-read of `ff5e5040` plus a merge-only read with hand-resolved code conflicts. Edge suite, `tsc`, `wrangler types --check`, `check-links` and targeted mutants on the head. No PHP files, so no local PHP suites and no Cloudflare-phase note.
- CI on `6b5c7c5f`, all green: CI 44446777807 (all 18 jobs, including the required Tests (PHP 8.4, sqlite) and Tests (PHP 8.4, pgsql)), Deploy edge 44446777806 (Check and dry run green; Deploy apotheke-api skipped), CodeQL 44446775720 (both Analyze jobs). 0 open code-scanning alerts on the PR ref.
- Reviewer: Apotheke QA 2

## Checklist

- [x] `ff5e5040` touches only tests: `edge/tests/ops-backup.test.mjs`, +44. Two new tests:
  - A hex letter in the date or time part of the id is not a key (three cases).
  - A second backup arriving while the first waits on a public start also waits. For both suspended and deleting, both backups get the key, the container starts once and public gets 503.
- [x] Mutants on the head: M21 (hex letters in the date) and its time-part variant are killed. Setting `opsStart` after deciding to wait but before waiting, so a second backup skips the wait, is killed only by the new test. Setting `opsStart` first, so the first backup never waits, is killed by the N-2262 test and the new test. Round 2's "opsStart before the wait" survivor also dropped the `!opsStart` condition. It always waits, so it behaves the same as the real code.
- [x] Incoming since `590cf9df`: `bc400108` (#324) and `b3c81498` (v3.49.0 release, CHANGELOG and VERSION only). `bc400108`'s tree equals #324's final head `53a2904f` byte for byte. `53a2904f` adds only the QA doc to the catch-up `b202a55e`. So #328's stacked base content is exactly what's in main.
- [x] Merge resolution: `b3c81498` plus `git apply --3way` of `0614fda8..ff5e5040` reproduces the head exactly, except CHANGELOG. Every file the delta doesn't touch is identical to main. The per-file numstat of `main...head`, `main..head` and `0614fda8..ff5e5040` is identical (9 files, +361/−64). In `index.ts`, #325's `isPreflight(request, CORS_ALLOWED_ORIGINS)` is kept. #328's constructor `opsOnly`, the `opsBackup` wait, `opsStart` and `finally`, the permanent codes, the key regex and `publicStart` are all unchanged.
- [x] CHANGELOG: main's file plus #328's `### Fixed` bullet under [Unreleased], with the full PR URL. From `## [3.49.0]` down it is byte-identical to main, and VERSION equals main (3.49.0). The merge body ("chore: merge origin/main into cursor/lifecycle-backup-followups", 63 characters) declares all seven conflicted files and how each was resolved.
- [x] Edge suite on the head: 235/235 on Node 22 and 24. `tsc --noEmit` and `wrangler types --check` are clean. `check-links` reports 396 links in 297 files, 0 broken. Secret scan of `ff5e5040`'s added lines: 0 leaks.

## Prior findings

- **L-2230, L-2260, N-2261, N-2262 closed** (round 2, confirmed in the real Workflows runtime under `wrangler dev` 4.160.0). **L-2231, N-2232 to N-2235 closed** (round 1). **N-2263 closed.**
- **N-2264 closed:** hex letters in the date and time parts, and a second backup during a public start, are both tested, and the mutants above are killed.

## New findings

None. 0 High, 0 Medium, 0 Low and 0 Nit open (next free id: 2265). Accepted risks are unchanged: `lifecycleCode` depends on the message shape, but any other shape records `error` and the stop still runs. Start isolation relies on `readying && !opsStart`, which is tested. And there is one D1 read per restarted, running, non-primary container.

## Verdict

**PASS** at `6b5c7c5f`, 0 behind main `b3c81498` (v3.49.0). The merge-only read passes. Highs and Mediums block; none are open.

Not yet cleared to merge. This doc must land byte-exact as the last commit ("docs(qa): add QA 2 review for #328"), then the hash check and marker, and full CI on the final head must be green, including both required Tests jobs. Squash as "fix(edge): stop failed backups, re-check status after a restart" (63 characters, 70 with " (#328)"). The PR title would be 74 with the suffix.
