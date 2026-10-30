# QA: PR #165, release workflow pushes with a GitHub App token

- PR: https://github.com/thordin9/apotheke/pull/165 (Coder), base `main`, same repository
- Reviewed head: `61cc59db878535d4fb7723e5509889ceb5fa3e97`, 4 commits (first pass at `1f8d4ca`)
- CI: run 41045190404, 14/14 success on the exact head (pull_request)
- Scope: release-pipeline credentials (security-relevant full gate, then delta re-verify)

## Checklist

- [x] Same repo, base `main`, mergeable clean. `9fb53a0` is a plain merge of `main` b96f934: no conflicts, and its diff equals `main`'s own. `main` has since gained 13 commits (#166) that touch none of the PR's files (N-1181)
- [x] Files: `.github/workflows/release.yml`, `tests/Unit/Ci4BranchCiTest.php`, `docs/releasing.md`, `docs/ci.md`. No CHANGELOG edit (the changelog is generated from commits)
- [x] Pins, confirmed through the GitHub API, all in official non-fork repositories:
  - `actions/create-github-app-token@64ab729a…`: the commit the `v3.3.0` (lightweight) and `v3` tags point to
  - `actions/checkout@11d5960a…`: the commit `v4.4.0` and `v4` point to (verified commit)
  - `shivammathur/setup-php@eb7c497e…`: the annotated `2.40.0` and `v2` tags both dereference to it ("Bump version to 2.40.0", verified)
- [x] Every `uses:` is SHA-pinned with a version comment. There is no Dependabot config, so the pins are bumped by hand (N-1182)
- [x] Permissions: `contents: read` at the top level; the job has `contents: read` and `actions: read`. `gh run download` keeps the read-only `github.token`
- [x] Environment `release`: deployment branches limited to `main`. It holds the App's client ID (variable) and private key (secret)
- [x] Checkout runs with `persist-credentials: false` and no `token:`, so no credential is in `.git/config` while third-party code runs
- [x] The token is minted after the guard, plan and coverage steps, only `if: steps.plan.outputs.release == 'true'`. It is scoped to `contents: write` on this repository and revoked in the post step
- [x] The token goes, as step-level env, only to the push step and `gh release create`
- [x] The push authenticates through `GIT_CONFIG_COUNT/KEY_0/VALUE_0`, exported inside the push step's shell only. `printf` is a builtin and the value reaches `base64` on stdin, so it is never in argv or `.git/config`
- [x] Order: the commit and tag go in one `git push --atomic`. `gh release create --verify-tag` runs only after the push step succeeds, uses the API only (no git push), and cannot create the release without the tag
- [x] Trigger: `workflow_run` of CI on `main` with `event == push`, or a manual run (the environment admits only `main`). No `pull_request_target`, and no PR-controlled `${{ }}` in `run:`
- [x] Loop: App-token pushes start workflows. `[skip ci]` on the release commit stops CI, CI does not run on tags, and nothing listens to `release`. As a backstop, `release.php plan` ignores release commits. `[skip ci]` is now asserted, and `docs/ci.md` and `releasing.md` give the right reason
- [x] Masking: the raw token is masked by the action. The base64 header value is not separately masked and is not printed today: there is no `set -x`, `GIT_TRACE*` or echo, git errors do not print headers, and step-debug logs list only `env:` expressions (the raw token, masked) (L-1180)
- [x] Tests: `Ci4BranchCiTest` and `ReleaseTest` give 36 tests OK (1 PostgreSQL case incomplete and 1 test skipped locally)
- [x] Mutations killed: persist-credentials true, a `token:` input on checkout, setup-php at `@v2` or `@2.40.0`, the token step's `if` dropped, the token step moved before plan, a third token use (job-level `GH_TOKEN`, extra job env, extra step env), `[skip ci]` removed
- [ ] Mutations survived: `set -x` in the push step (before `apply` or before the push), `shell: bash -x {0}`, and `GIT_TRACE_CURL=1 GIT_TRACE_REDACT=0` (L-1180)
- [x] actionlint 1.7.7 clean. gitleaks 8.30.1 (checksum verified): no leaks on `1f8d4ca..61cc59d` or on `main..61cc59d`. `.gitleaks.toml` and CHANGELOG are unchanged versus `main`
- [x] Docs match the workflow. No hostnames, keys, App IDs or client IDs in the repository

## Findings

- **M-1120 (fixed)** Third-party code could read the write token. Every action is now SHA-pinned, the checkout keeps no credentials, the token is minted only for a release right before the push, and only the push and `gh release create` steps get it. The test pins all of this.
- **L-1121 (fixed)** `[skip ci]` is asserted in `Ci4BranchCiTest`, and `docs/ci.md` and `releasing.md` explain that the marker is what stops a loop.
- **L-1180** The base64 `AUTHORIZATION` value is not registered as a mask. Command tracing or a trace with redaction turned off would put it in the log, and the test does not catch those, because its echo/`set -x` check needs `TOKEN` on the same line. Not printed today. Fix (cheap): add `echo "::add-mask::<the base64 value>"` before the export, and assert that `release.yml` has no `set -x`, `bash -x` or `GIT_TRACE`.
- **N-1181** The branch is 13 commits behind `main` (#166). No overlap and it merges cleanly; CI ran on the head, not on today's merge result.
- **N-1182** The pins need manual bumps (no Dependabot). `actions/checkout` v4.4.0 runs on Node 20; `setup-php` 2.40.0 already uses Node 24.
- **N-1183** `APP_TOKEN` is in the env of the whole push step, so trusted `main` code (`release.php apply`) also sees it. It could be set only right before the push.
- **N-1122** Without admin rights, the `main` ruleset shows only the deletion and non-fast-forward rules and no bypass actors. The "App on the bypass list" step could not be confirmed.
- **N-1124** Known limit: only the next release after merge exercises the App push. Check that the release commit starts no CI run and that only one tag and one release appear.

## Security

GITHUB_TOKEN is read-only. The write credential lives in an environment that admits only `main`, is minted only when a release is due, is limited to contents on this repository, and is revoked at job end. No credential stays in the checkout. All actions are pinned to verified official commits. The one remaining hardening point is masking the derived header value and keeping tracing out of the push step (L-1180). No secrets or identifiers were printed or committed during this review.

## Verdict

**APPROVE** at `61cc59db878535d4fb7723e5509889ceb5fa3e97`. No H or M findings; L-1180 and the notes can follow up.
