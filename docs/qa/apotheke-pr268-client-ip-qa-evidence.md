# PR 268 client IP rate-limit keys — evidence

Read-only. Head `47595b865f408953cb562174c44756a0e2f541f7` matches `refs/pull/268/head`. Parents: `de59ba4685e47e234d817124c89e07d60ac07051` and `67b9471bb27384283c28aa32e419b8523757745c` (main, the #279 merge). Behind main: 0. Rechecked live head: unchanged.

`git diff fc7e3aa2 de59ba46` and `git diff 67b9471b 47595b86`, both excluding `CHANGELOG.md` and with `index` lines dropped, are identical. The diff against main touches 15 files (+222/-7), including the two existing QA docs.

Changelog: the Unreleased `### Fixed` section holds the #268 bullet and then main's #279 bullet. `#268` occurs once. The only other changelog hunk is one added blank line before `## [3.30.0]`, already present in `git diff fc7e3aa2 de59ba46` (N-1851).

Main moved from `fc7e3aa2` to `67b9471b` with `CHANGELOG.md`, `docs/HANDOFF.md`, two QA docs, `DatabaseSessionHandler.php` and four test files. None overlap #268's own files except the changelog and the handoff, and the handoff diff against main is unchanged.

CI run `43119966090`. D1 job `130857636135` pass. SQLite job `130857636255` and PostgreSQL job `130857636100` pending. No failed check.

H-1849 closed. N-1850 open. N-1851 new, non-blocking. Next free id: 1852.
