# PR 268 client IP rate-limit keys — evidence

Read-only. Head `693ac32f937ae3cfcb30e55bdb00240839afb4dd` matches `refs/pull/268/head`. Parents: `fb4b08ee2943a2d4d0abd6135abd172550d82a54` and `a9d89163557e4452fc36371dab23c3fc8f1466c2` (main, v3.36.1). Behind main: 0. Rechecked live head: unchanged.

`git diff 67b9471b fb4b08ee` and `git diff a9d89163 693ac32f`, both excluding `CHANGELOG.md` and with `index` lines dropped, are identical. `git diff a9d89163 693ac32f -- README.md VERSION` is empty.

Main moved from `67b9471b` to `a9d89163` in `CHANGELOG.md` (+9/-1), `README.md` and `VERSION` only.

`git diff a9d89163 693ac32f -- CHANGELOG.md` has two hunks: `### Fixed` plus the #268 bullet under `## [Unreleased]`, before `## [3.36.1]`; and one added blank line before `## [3.30.0]` (N-1851). `#268` occurs once. `## [3.36.1]` holds only main's #279 and #276 entries.

CI run `43121041546`. D1 job `130861399434`, SQLite job `130861399486` and PostgreSQL job `130861399605` pending at the read. No failed check.

H-1849 closed. N-1850 open. N-1851 open, non-blocking. Next free id: 1852.
