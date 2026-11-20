# QA: apotheke #341, puppeteer-core 25.16.0 → 25.17.0 (/tests/e2e)

- PR: https://github.com/thordin9/apotheke/pull/341 (Dependabot)
- Reviewed head: `038acbe8abee604e0af6838a072d6959c573d140`, 0 behind main `e0287d50` (v3.58.0, after #348). Content reviewed at `c66213c9587e8c61d40d5d492647b082506ee01a`; since then only plain `--no-ff` catch-up merges (`8df22517`: `c66213c9` + `8d28bf05`; `038acbe8`: `1a5fc73a` + `e0287d50`) and the first copy of this doc (`1a5fc73a`).
- History: 1 commit. Subject: `ci: bump puppeteer-core in /tests/e2e in the tests-e2e group` (60). The GitHub PR title `ci: bump puppeteer-core from 25.16.0 to 25.17.0 in /tests/e2e in the tests-e2e group` is 84 characters, 91 including " (#341)" (see L-2431).
- Gate: light (diff read + lockfile sanity). No mutants, no suites, no image rebuilds.
- CI on `c66213c9`: CI 44744149499 succeeded. `gh pr checks`: 18 pass, 2 skipped (CodeQL, update-snapshots). Headless mobile checks (PHP 8.4) and Visual E2E (Playwright, PHP 8.4, shards 1–3) are among the passes. Frontend and Deploy frontend did not run (path filters; expected).
- CI on `038acbe8`: running at the merge-only read; full CI must be green on the final head before the squash.
- Reviewer: Apotheke QA 2 (merge-only reads 2026-11-20 20:16 and 20:53 UTC+2)

## Checklist

- [x] Scope vs main `c62dafd8` (and vs merge-base `19ed560a`): 2 files: `tests/e2e/package.json` +1/−1, `tests/e2e/package-lock.json` +24/−23. No app, worker, workflow or other package changes.
- [x] Catch-up preview: `git merge-tree --write-tree` of `c66213c9` and main `c62dafd8` is clean (tree `36fdaf82…`). The merge tree differs from main only in this PR's two files, with the same numstat as the PR vs merge-base. Main's five commits since `19ed560a` (#338, v3.55.0, #339, v3.56.0, #345) do not touch `tests/e2e/package.json` or `tests/e2e/package-lock.json`.
- [x] Catch-ups (merge-only reads): `8df22517` has parents exactly `[c66213c9, 8d28bf05]` and `038acbe8` exactly `[1a5fc73a, e0287d50]`; each `git merge-tree --write-tree` equals its head tree (no hand resolution). `1a5fc73a..038acbe8` equals main's delta `8d28bf05..e0287d50`. The `main...head` diff outside this doc is byte-identical to the reviewed `19ed560a..c66213c9` diff (`tests/e2e/package.json` +1/−1, lock +24/−23). `CHANGELOG.md` and `VERSION` equal main.
- [x] `package.json`: only `puppeteer-core` ^25.16.0 → ^25.17.0 (caret range, same style as main). No other `package.json` keys changed. The lock resolves `puppeteer-core` to 25.17.0.
- [x] Lockfile: no added or removed packages. Version bumps: `puppeteer-core` 25.16.0 → 25.17.0 and its transitives `@puppeteer/browsers` 3.2.6 → 3.2.7, `ansi-regex` 6.5.0 → 6.6.0, `devtools-protocol` 0.0.1704951 → 0.0.1717327, `webdriver-bidi-protocol` 1.0.1 → 1.0.2, `ws` 8.22.0 → 8.23.0. Every `resolved` URL is `registry.npmjs.org`; every package has a sha512 integrity. Integrities of all six bumped packages match the npm registry. No install scripts in this lock, and none were added or removed.
- [x] Upstream 25.17.0 (https://github.com/puppeteer/puppeteer/releases/tag/puppeteer-core-v25.17.0, 2026-11-10) is a minor: API additions, Chrome/Firefox rolls, BiDi/CDP/network fixes, and `@puppeteer/browsers` 3.2.7. No breaking-change section and no security advisory in the notes. This repo launches system `google-chrome` via `puppeteer-core` in the Headless mobile checks job, which passed on this head. Visual E2E uses Playwright (a different package tree) and also passed.
- [x] CHANGELOG and VERSION are identical to merge-base `19ed560a` (untouched by the PR). Per #243 (`docs/qa/apotheke-pr243-wrangler-4157-qa.md`) and #312: release.php would put a `ci` squash under Maintenance only if a release ran; `scripts/release.php` cuts no release for `build` or `ci` types, so a hand-written bullet is not needed.

## Findings

| ID | Grade | Finding |
|---|---|---|
| L-2431 | Low | The GitHub PR title is 84 characters, 91 including " (#341)", over the 72-character Conventional Commits limit. The branch commit itself is within the limit (60). Retitle (or squash with) `ci: bump puppeteer-core from 25.16.0 to 25.17.0 in /tests/e2e` (61, 68 including " (#341)"), not the PR title as-is. |

0 High, 0 Medium, 1 Low, 0 Nit.

## Verdict

**PASS** (light gate of the bump plus merge-only read) at `038acbe8`, 0 behind main `e0287d50`. Highs and Mediums block; none are open. This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #341"), followed by the hash check and green CI on that head. Squash as `ci: bump puppeteer-core from 25.16.0 to 25.17.0 in /tests/e2e (#341)` (68), not the PR title (L-2431). A `ci` change does not cut a release.
