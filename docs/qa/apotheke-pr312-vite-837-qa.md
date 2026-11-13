# QA: apotheke #312, vite 8.3.5 → 8.3.7 (/frontend)

- PR: https://github.com/thordin9/apotheke/pull/312 (Dependabot, with main merges from DevOps and PM)
- Reviewed head: `3228def01f5d655f9bafd78d4c876e772600fd36`, the plain merge of the docs(qa) commit `059191ae` and main `2510138e` (#313 wrangler squash). 0 behind main `2510138eb0bb2255fd7af8894026fa5c1b6059cb`, 4 ahead, mergeable (clean). Parents are `059191ae` and `2510138e`.
- History: 4 commits. Non-merge subject: `build(deps-dev): bump vite in /frontend in the frontend group` (61). Merges exempt. docs(qa) subject `docs(qa): add QA 2 review for #312`. The GitHub PR title `build(deps-dev): bump vite from 8.3.5 to 8.3.7 in /frontend in the frontend group` is 81 characters (see N-2156).
- Gate: merge-only read of the catch-up onto `2510138e` (parents, behind_by, merge-tree, numstat, compare). Prior light gate at `24fa69db` is unchanged; no mutants, no Docker/PG suites, no image rebuilds.
- CI on `3228def0`: Frontend 43455824840, Deploy frontend 43455824783 and CodeQL 43455816397 succeeded. CI 43455824603 was still running at this read (11 in progress, 12 success, 1 skipped: Deploy getapotheke-app, PR event). `mergeable_state` is blocked while CI runs. Prior CI on `24fa69db` (CI 43445523956, Frontend 43445523965, Deploy frontend 43445523932) was fully green.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main `2510138e`: 3 files: `frontend/package.json` +1/−1, `frontend/package-lock.json` +90/−72, and `docs/qa/apotheke-pr312-vite-837-qa.md` +30. No app, worker, workflow or other package changes.
- [x] Merge-only: `git merge-tree --write-tree` of `059191ae` and `2510138e` is a clean tree identical to `3228def0` (`0c8a53c1…`). `gh api compare 059191ae...3228def0` brings only main's #313 files (edge package files, both `worker-configuration.d.ts`, and the #313 QA doc). `frontend/package.json` and `frontend/package-lock.json` on the head are byte-identical to `24fa69db`, and their numstat equals `85c6ad8c...24fa69db`. Nothing dropped.
- [x] `package.json`: only `vite` 8.3.5 → 8.3.7 (exact pin, no range). Other deps unchanged (`@counterscale/tracker` stays 3.4.1).
- [x] Lockfile: vite 8.3.7, rolldown 1.2.18 (plus 15 platform bindings 1.2.16 → 1.2.18) and `@rolldown/pluginutils` 1.0.1 → 1.1.0. No added or removed packages. sha512 integrities of vite, rolldown and `@rolldown/pluginutils` match the npm registry. package.json version == lock version for every exact pin. (Prior light gate; blobs unchanged.)
- [x] Upstream 8.3.6 and 8.3.7 are bugfix (and chore/test/perf) releases; 8.3.7 is the Rolldown 1.2.18 bump, matching the lock.
- [x] Local (prior gate at `24fa69db`): after `npm ci --ignore-scripts` in a scratch `frontend/` (locked vite 8.3.7), `npm run build` completes with no warnings. JS gzip sizes match the author's figures (index 1.65 kB, consent 3.69 kB); CSS `style-BPbO2Jg2.css` 33.59 kB / gzip 8.09 kB.
- [x] CHANGELOG is identical to main. Per #243 (`docs/qa/apotheke-pr243-wrangler-4157-qa.md`): release.php generates the Maintenance entry from the squash subject (`build` goes under other/Maintenance); no hand-written bullet is needed.

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-2156 | Nit | The GitHub PR title is 81 characters, over the 72-character Conventional Commits limit. The branch commits themselves are within the limit (61 and merges). Squash with a subject of at most 72 characters, e.g. `build(deps-dev): bump vite from 8.3.5 to 8.3.7 in /frontend` (60), not the PR title as-is. |

0 High, 0 Medium, 0 Low, 1 Nit.

## Verdict

**PASS** at `3228def0`, 0 behind main `2510138e`, confirmed by the merge-only read. Highs and Mediums block; none are open. Squash-merge with `build(deps-dev): bump vite from 8.3.5 to 8.3.7 in /frontend` once the replacement docs(qa) commit is on the branch and CI is green. If main moves before merge, the PR needs a plain catch-up (conflicts reported as conflicts), a merge-only read and a reissued doc. The earlier doc at `059191ae` (sha256 `d8131b9b…`) is superseded and will be replaced by this reissue in a new docs(qa) commit.
