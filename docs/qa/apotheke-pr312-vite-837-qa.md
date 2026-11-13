# QA: apotheke #312, vite 8.3.5 → 8.3.7 (/frontend)

- PR: https://github.com/thordin9/apotheke/pull/312 (Dependabot, with a main merge from DevOps)
- Reviewed head: `24fa69dbae975a61e5cb806b259f992f0f407506`, itself the --no-ff merge of Dependabot `c4643af4` and main `85c6ad8c` (v3.43.0). 0 behind main `85c6ad8c58144cda2a1098a142f847fc5fb4af31`, 2 ahead, mergeable (clean).
- History: 2 commits. Non-merge subject: `build(deps-dev): bump vite in /frontend in the frontend group` (61). Merge exempt (68). The GitHub PR title `build(deps-dev): bump vite from 8.3.5 to 8.3.7 in /frontend in the frontend group` is 81 characters (see N-2156).
- Gate: light (dependency bump + merge: diff read, CI, merge-tree; no mutants, no Docker/PG suites, no image rebuilds).
- CI on `24fa69db`: CI 43445523956, Frontend 43445523965 and Deploy frontend 43445523932 all succeeded. 24 check runs: 23 success incl. Tests (PHP 8.4, sqlite) and (PHP 8.4, pgsql) and Frontend Build, 1 skipped (Deploy getapotheke-app, PR event).
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope: 2 files: `frontend/package.json` +1/−1 and `frontend/package-lock.json` +90/−72. No app, worker, workflow or other package changes.
- [x] `package.json`: only `vite` 8.3.5 → 8.3.7 (exact pin, no range). Other deps unchanged (`@counterscale/tracker` stays 3.4.1).
- [x] Lockfile: vite 8.3.7, rolldown 1.2.18 (plus 15 platform bindings 1.2.16 → 1.2.18) and `@rolldown/pluginutils` 1.0.1 → 1.1.0. No added or removed packages. sha512 integrities of vite, rolldown and `@rolldown/pluginutils` match the npm registry. package.json version == lock version for every exact pin.
- [x] Main merge `24fa69db`: `git merge-tree` of `c4643af4` and `85c6ad8c` is a clean tree identical to the merge commit (`5f0b3b00…`). No file overlap with main's `b58cc830..85c6ad8c` changes; package.json and the lock on the head are byte-identical to the Dependabot commit.
- [x] Upstream 8.3.6 and 8.3.7 are bugfix (and chore/test/perf) releases; 8.3.7 is the Rolldown 1.2.18 bump, matching the lock.
- [x] Local: after `npm ci --ignore-scripts` in a scratch `frontend/` (locked vite 8.3.7), `npm run build` completes with no warnings. JS gzip sizes match the author's figures (index 1.65 kB, consent 3.69 kB); CSS `style-BPbO2Jg2.css` 33.59 kB / gzip 8.09 kB.
- [x] CHANGELOG is identical to main. Per #243 (`docs/qa/apotheke-pr243-wrangler-4157-qa.md`): release.php generates the Maintenance entry from the squash subject (`build` goes under other/Maintenance); no hand-written bullet is needed.

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-2156 | Nit | The GitHub PR title is 81 characters, over the 72-character Conventional Commits limit. The branch commits themselves are within the limit (61 and a merge). Squash with a subject of at most 72 characters, e.g. `build(deps-dev): bump vite from 8.3.5 to 8.3.7 in /frontend` (60), not the PR title as-is. |

0 High, 0 Medium, 0 Low, 1 Nit.

## Verdict

**PASS** at `24fa69db`, 0 behind main `85c6ad8c`. Highs and Mediums block; none are open. Squash-merge with `build(deps-dev): bump vite from 8.3.5 to 8.3.7 in /frontend` once the docs(qa) commit is on the branch and CI is green. If main moves before merge, the PR needs a plain catch-up (conflicts reported as conflicts), a merge-only read and a reissued doc.
