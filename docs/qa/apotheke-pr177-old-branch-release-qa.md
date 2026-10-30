# QA: apotheke PR #177 — 2.6.x releases by hand from `old`, CI on `old` (L-1060 PR 2)

- **PR / head:** #177 `cursor/old-ci-release` → `old`, same repo, ready, CLEAN. Head `72fc528dc3bb46b4bb202cb403c0a6d42779faac`, 2 commits (`822d209` + fix-up `72fc528`), 0 behind `old` (`5f63656`, v2.6.26).
- **CI:** run 41086066081 (`pull_request`) passed 10/10 on the exact head: Lint, Secret scan, Tests (PHP 8.3, sqlite), Tests (PHP 8.3, pgsql), Headless mobile checks, Visual E2E shards 1–3, Docker build + smoke test, Conventional Commits.
- **Scope:** full gate (a release workflow that pushes with a write token). First review PASS at `822d209` (0H/0M/2L/5N); this round re-checks only the `822d209..72fc528` delta (3 files: `release.yml`, `docs/releasing.md`, `WorkflowsTest.php`). 2026-10-30 (EET).
- **Verdict: PASS.** Open: 0H/0M/0L/3N. L-1400, L-1401, N-1402, N-1403 and N-1406 are fixed. N-1404 and N-1405 are deferred as agreed. One new Nit (N-1407).

## Checklist

| Check | Result |
|---|---|
| `ci.yml` triggers | Unchanged since `822d209`: `pull_request:`, `push: branches: [old]` |
| `release.yml` trigger and ref guard | `workflow_dispatch` only; job `if: github.ref == 'refs/heads/old'` |
| Checkout | `ref: old`, `fetch-depth: 0`, `persist-credentials: false`, so no credential stays in `.git/config` |
| Action pins | `actions/checkout@11d5960…` (v4.4.0), `shivammathur/setup-php@eb7c497…` (2.40.0), unchanged; setup-php gets no token input or env |
| Token scope (L-1400) | No workflow- or job-level `env:`. `GH_TOKEN` is set at step level only on the CI-guard step and the GitHub Release step. Plan, version check and apply/commit/tag get no token. The push step gets only `PUSH_TOKEN`, sent as a masked header through `GIT_CONFIG_*` env. `github.token` appears on exactly those 3 lines; no `secrets.*` is used |
| CI-success guard | `head=$(git rev-parse HEAD)` of the checked-out `old`, no `github.sha`/`GITHUB_SHA`; fails on 0 runs or an API error |
| Version guard / expression injection | Unchanged: anchored `^2\.6\.[0-9]+$`; every `${{ }}` sits in `env:` or `if:` |
| Push | One push only: `git push --atomic origin HEAD:old "v$NEXT"` |
| GitHub Release | `--verify-tag --latest=false`, then `releases/latest` must still be `v3.*` |
| Concurrency | `group: release`, `cancel-in-progress: false` (once) |
| Permissions | Workflow `contents: read`; job `contents: write`, `actions: read` |
| Header comment (N-1406) | `release.yml` now says dispatch needs `main`'s `release.yml` at this filename with `workflow_dispatch`, and that the run uses `old`'s copy |
| actionlint 1.7.7 | Both workflows clean |
| zizmor 1.31.1 (offline) | `release.yml`: no findings (1 suppressed). `ci.yml` unchanged, see N-1404 and N-1405 |
| `WorkflowsTest` | 6 tests, 60 assertions, OK (2 new: `testGhTokenOnlyOnTheStepsThatCallGh`, `testReleasePinsGuardInputsConcurrencyAndOnePush`) |
| Full suite on the head (PHP 8.4.24 here, no 8.3 chroot left) | 560 tests, 4,952 assertions, OK, 19 skipped (Coder: 560 OK, 19 skipped) |
| Mutations (14 on `release.yml`, delta only) | 11 caught: `GH_TOKEN` moved to job level, `GH_TOKEN` added to the push step, `GH_TOKEN` moved to the apply step, workflow-level `GH_TOKEN`, a token input on setup-php, the push split into two pushes, an extra tag push, `cancel-in-progress: true`, the guard on `github.sha`, the guard on `GITHUB_SHA`, `persist-credentials: true`. 3 survive (N-1407) |
| Docs (L-1401, N-1403, N-1406) | `docs/releasing.md` matches the workflow; the recovery commands are valid `gh` usage, use placeholders (`v2.6.N`, `<newest v3 tag>`) and contain no credentials |
| Commit subject | `ci(release): scope GH_TOKEN to gh steps and pin old release guards`: valid type and scope, 66 chars (max 72); the CI check passed |
| CHANGELOG | No edit needed: `ci:` commits make no release on their own |
| Secrets | gitleaks in CI clean; grep of the delta for credential patterns clean |

## Findings

**L-1400 (fixed): the write-scoped workflow token reached every step of the release job.** The job-level `env:` is gone. `GH_TOKEN` now sits only on the two steps that call `gh`, and `testGhTokenOnlyOnTheStepsThatCallGh` pins the two step-level blocks and the count. The token no longer reaches setup-php, the plan or the apply/commit/tag step.

**L-1401 (fixed): "run the workflow again" didn't recover a failure after the push.** `docs/releasing.md` now says a re-run only helps before the push, explains why, and gives the manual finish: `gh release create v2.6.N … --verify-tag --latest=false`, then check that `releases/latest` is still `v3.*`, else `gh release edit <newest v3 tag> --latest`. The local full-release recipe also gained `--verify-tag`.

**N-1402 (fixed): four workflow mutants survived.** `testReleasePinsGuardInputsConcurrencyAndOnePush` pins `git rev-parse HEAD` in the guard, `cancel-in-progress: false`, a token-free setup-php and the single atomic push. All four earlier survivors are now caught.

**N-1403 (fixed): `concurrency: release` is shared with `main`.** `docs/releasing.md` step 3 explains that a pending `old` run can be replaced by a queued `main` release, that such a run made no changes, and that you run it again.

**N-1404 (deferred, accepted): pre-existing hardening gaps in `old`'s `ci.yml`** (tag-referenced actions and images, checkouts keep credentials). Pin them in the first 2.6.x fix PR or accept them for the 90-day window.

**N-1405 (deferred, accepted): zizmor help and low-confidence items in `ci.yml` (pre-existing).** Move the template expansions to `env:` the next time those lines change.

**N-1406 (fixed): the dispatch dependency on `main` was only in the PR body.** It is now in `docs/releasing.md` and in `release.yml`'s header comment. `main`'s `release.yml` has `workflow_dispatch` today, so `main` needs no change.

**N-1407 (new): the token-scope test is narrower than its comment.** The "no workflow- or job-level env" assertion only matches `env:` at column 0 or 2, but job keys sit at column 4. A job-level `env:` that passes the workflow token under another name, or a token under another name on a non-`gh` step (via `github.token` or `secrets.GITHUB_TOKEN`), still passes the tests. The workflow itself is correct today. **Fix (hardening, optional, can ride the next 2.6.x PR):** match `env:` at up to 4 spaces of indent, assert `github.token` appears exactly 3 times (the two `GH_TOKEN` lines and `PUSH_TOKEN`), and assert there is no `secrets.` in `release.yml`.
