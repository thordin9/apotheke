# PR 282 storage preview paths — evidence (merge-only)

Head `87cf087ce0d85c34e2cdcb6916f1e8f959c17487`, which matches `refs/pull/282/head` in `git ls-remote`. Its parents are `cf2c4d84b5449c278c245b53fd770ab3a2ae73e8` and `362e747750e316818e586b7c2c8c08cd794e7c01`, which is origin/main (v3.36.4). The previous PASS was the full gate at `e64414979a63e97fc32b6b15d780cb7262c988e6`, parent `0840308c90b31730d553b0e31cee2f94b73f5915`.

## Commands

- `git merge-base --is-ancestor e6441497 87cf087c` returned ok.
- `git rev-list --count 87cf087c..origin/main` returned 0.
- `git log -1 --format=%P cf2c4d84` returned e6441497. `git diff e6441497 cf2c4d84` changes only the one CHANGELOG line, which adds ` ([#282](https://github.com/thordin9/apotheke/pull/282))`.
- `diff <(git diff 0840308c e6441497 -- . ':!CHANGELOG.md' ':!docs/HANDOFF.md' | grep -v '^index ') <(git diff origin/main 87cf087c -- . ':!CHANGELOG.md' ':!docs/HANDOFF.md' | grep -v '^index ')` returned no differences.
- `git diff --shortstat origin/main 87cf087c` returned 15 files, +314/-15.
- `git diff origin/main 87cf087c -- CHANGELOG.md` shows only the `### Fixed` heading plus the one linked bullet under Unreleased. The `pull/282` link appears once.
- `git diff origin/main 87cf087c -- docs/HANDOFF.md` changes two lines. The first appends the storage-mode boot, `auth status` and `?action=head` sentence before `EDGE_STORAGE_READY stays off`. The second adds the preview-fix sentence to next step 1. Main's other text is unchanged.
- `git diff --quiet origin/main 87cf087c -- README.md VERSION` showed no difference.
- Main commits since 0840308c are d16c85e0 (#271), 0297baa3 (v3.36.3), 75ed0161 (#281) and 362e7477 (v3.36.4).

## CI on 87cf087c (check-runs)

Succeeded: CodeQL, Analyze (actions), Analyze (javascript-typescript), D1 (local workerd), Lint (PHP 8.4), DI rule, deptrac, Conventional Commits, Secret scan, Headless mobile checks, and Upgrade and rollback (sqlite and pgsql).

In progress: Tests (PHP 8.4, sqlite), Tests (PHP 8.4, pgsql), Visual E2E shards 1 to 3, the Docker build and smoke tests, the Docker Compose self-hosting jobs, and the Check and dry run (apotheke-api).

## Notes

No suites were run again, because this is a merge-only change. The release test that closes L-1852 runs in the CI sqlite and pgsql jobs. L-1852 is closed and N-1853 stays open. The next free id is 1854.
