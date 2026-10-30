# QA: PR #171, release credential mask, tokenless apply, Dependabot

- PR: https://github.com/thordin9/apotheke/pull/171 (Coder), follow-up to #165 (L-1180, N-1183)
- Head: `20c96bcd1bc20a57bbd38020968a404d686b0bd4`, base `main` (6776b6d), same repository, 0 behind, mergeable clean
- Commits: 3 (8901b67 plus two plain merges of `main`, 82b773c and 20c96bc, with an empty remerge-diff)
- CI: 17/17 check runs success on the exact head (CI run 41057178771 and CodeQL)
- Scope: release credentials (full gate on the delta)

## Checklist

- [x] Files: `.github/workflows/release.yml`, `.github/dependabot.yml` (new), `docs/releasing.md`, `tests/Unit/Ci4BranchCiTest.php`
- [x] Mask: `basic` is computed and registered with `::add-mask::` in the push step, before `GIT_CONFIG_VALUE_0` is built and before `git push`
- [x] The mask command does not leak. The runner's `add-mask` handler has `OmitEcho = true`. Even with command echo on (step debug), it prints only `::add-mask::***`. The step preview shows the script source with `$basic` unexpanded
- [x] Tokenless apply: `release.php apply`, the commit and the tag run in their own step with no token. Then come the token step (same `if:`, after guard and plan), a push-only step with `APP_TOKEN`, and `gh release create` with `GH_TOKEN`
- [x] State: all steps share one job workspace, so the local commit and tag reach the push step. `git push --atomic` sends both or neither. If the push fails, the job stops and the GitHub Release step (default `success()`) does not run; `--verify-tag` also refuses a missing tag. If token minting fails, only local state exists and nothing is pushed
- [x] `ci.yml`, `release.yml` and `snapshots.yml` have no `pull_request_target`, no auto-merge and no Dependabot-specific workflow. CI is `contents: read` and uses no secrets; Dependabot PRs run it as `pull_request` with a read-only token and no Actions secrets
- [x] Dependabot config: `github-actions`, weekly, one group, prefix `ci`, so subjects take the form `ci: bump …`, which `check-commits.sh` accepts. `release.php` gives `ci` no bump, so a Dependabot merge cuts no release on its own and appears under Maintenance in the next one. That is no noise
- [x] Pin format: Dependabot-style bumps (new SHA plus `# vX.Y.Z` or `# X.Y.Z` comment) pass the pin regex for checkout and setup-php. A bump of create-github-app-token fails the test, which pins that SHA exactly (N-1261)
- [x] `Ci4BranchCiTest` and `ReleaseTest`: 37 tests OK (1 PostgreSQL case incomplete, 1 test skipped locally)
- [x] Mutations killed (15):
  - `set -x` and `set -o xtrace` in the push step
  - `shell: bash -x {0}` and `bash -ex {0}`
  - `GIT_TRACE_CURL=1` with `GIT_TRACE_REDACT=0`, both by `export` and in step env
  - the mask moved after the export, removed, or applied to `$APP_TOKEN` instead
  - `release.php` added to the push step
  - a third token use
  - a non-atomic push
- [ ] Mutations survived: any other command added to the push step (for example `composer install`), and `$basic` written to a file (N-1260)
- [x] actionlint 1.7.7 clean on all workflows. gitleaks 8.30.1 (checksum verified) on `main..20c96bc`: no leaks. `.gitleaks.toml` and `CHANGELOG.md` are identical to `main`
- [x] Docs: `releasing.md` steps 5-7, the mask, the tokenless apply and Dependabot all match the workflow. No hostnames, keys or IDs
- [x] N-1124 (from #165), checked through the GitHub API:
  - Release run 41056805181 (`workflow_run`, success, on 6957e29, #169) made `chore(release): v3.1.1 [skip ci]` (6776b6d)
  - Exactly one tag `v3.1.1` (annotated, pointing to 6776b6d) and one release `v3.1.1` (author `apotheke-releaser[bot]`)
  - 6776b6d started only CodeQL ("Push on main"), with no CI or Release run
  - The token's post step (revoke) succeeded

## Findings

- **L-1180 (fixed)** The base64 credential is masked before use, and command tracing and git trace variables are banned and tested.
- **N-1183 (fixed)** `release.php apply` runs in a tokenless step, and the push step only pushes.
- **N-1124 (closed)** The first App-token release (v3.1.1) produced one tag and one release, and started no CI or release run.
- **N-1122 (closed)** The App push to `main` succeeded in that run, so the ruleset setup works as documented.
- **N-1260** The test pins the push step's key lines, not its whole body, so an extra command in that step (which would see the token) still passes. Asserting the push step's `run:` block verbatim would close it.
- **N-1261** The test pins the create-github-app-token SHA exactly. A Dependabot bump of it fails CI and holds back the whole grouped PR until the test is updated by hand. That is reasonable for the credential action, but `releasing.md` could say so, or that action could get its own group.
- **N-1262** Dependabot also proposes updates for the tag-pinned actions in `ci.yml` and `snapshots.yml`, including major versions. Those stay tag-pinned, which is outside this PR's scope.

## Security

The write token now exists only for the push and release steps, and only when a release is due. The step that runs repository code (`release.php apply`) never sees it. Its derived HTTP header is masked before first use, and the mask command itself is never echoed. No tracing that could print the header is allowed, and the test pins this. Dependabot adds no new trigger with secrets: its pull requests run the read-only CI and cannot reach the release environment, which admits only `main`. No secrets or identifiers were printed or committed during this review.

## Verdict

**APPROVE** at `20c96bcd1bc20a57bbd38020968a404d686b0bd4`. No H, M or L findings open; the notes can follow up.
