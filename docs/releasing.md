# Releasing

Apotheke uses [Semantic Versioning](https://semver.org/). Releases are automatic: every merge to `main` that changes the
app becomes a version, with no manual steps.

* [`VERSION`](../VERSION) holds the current version (`MAJOR.MINOR.PATCH`, no `v`). The app reads it through
  `app_version()`: it is shown in the footer, under Control Panel → *System*, in `GET /health` and in the
  `X-Apotheke-Version` header of `GET /healthz`. The Docker image copies it next to the code.
* [`CHANGELOG.md`](../CHANGELOG.md) follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); each entry links
  the pull request it came from.
* Every release has an annotated tag `vX.Y.Z` and a GitHub Release with the same notes.

## How the version is chosen

[`scripts/release.php`](../scripts/release.php) reads the commits since the last `v*` tag (`git log <tag>..HEAD
--no-merges`) and applies the [Conventional Commits](ci.md#commit-rules) rules:

| Commits since the last tag | Bump | Example |
|---|---|---|
| any `!` after the type/scope, or a `BREAKING CHANGE:` footer | **major** | 1.4.2 → 2.0.0 |
| at least one `feat` | **minor** | 1.4.2 → 1.5.0 |
| at least one `fix` or `perf` | **patch** | 1.4.2 → 1.4.3 |
| only `docs`, `test`, `ci`, `build`, `chore`, `style`, `refactor` | **no release** | – |

Release commits (`chore(release): …`) and merge commits are ignored. Every pull request into `main` is
squash-merged, so `main` gets one commit, and that squash commit's subject decides the bump. The repository's squash
setting is `COMMIT_OR_PR_TITLE`: a pull request with a single commit would get that commit's subject, not its title.
So the maintainer passes the title explicitly when merging (`gh pr merge --squash --match-head-commit <sha> --subject
"<PR title> (#N)"`); the trailing `(#N)` gives the pull request number. The subject must be a Conventional Commit,
and its type decides the bump: `feat`, `fix`, `perf` or a breaking change cut a release; `ci`, `build`, `docs`,
`chore` or `test` don't. A `Merge pull request #N` merge commit, if one ever lands, also gives the number.

The changelog groups entries as *Breaking changes*, *Added* (`feat`), *Fixed* (`fix`), *Performance* (`perf`) and
*Maintenance* (everything else). The new section goes below `## [Unreleased]`, and the compare links at the bottom are
updated. Notes written by hand under `## [Unreleased]` move into that section, and `## [Unreleased]` is left empty.
Text written by hand under `## [Unreleased]` before its first `###` heading (for example an **Upgrade note:**
paragraph with a code block) becomes the first block of that section, so it opens the GitHub Release body. Keep
it free of `- ` bullets, or give each bullet its pull-request link.
A handwritten bullet replaces a generated bullet in that same section when both start with the same scope
(`- **autofill:** …`), so the notes do not list one change twice. A generated bullet with a different scope, or
the same scope under a different heading, stays. A handwritten bullet under `## [Unreleased]` keeps its pull
request link (`([#N](…/pull/N))`). `scripts/lint.sh` fails when one does not, because the fold keeps that
bullet as written and drops the generated line that would have carried the link.
The GitHub Release body is that whole section, handwritten notes included, plus the compare link.

## The release workflow

[`.github/workflows/release.yml`](../.github/workflows/release.yml) runs after a successful CI run for a push to `main`.
A green CI run on any other branch or on a pull request does not start it: `workflow_run.branches` is `[main]` only, and a
manual run checks out `main`.

1. check out `main` with full history; stop if `main` is no longer the commit CI tested (that newer commit's CI run
   releases it);
2. `php scripts/release.php plan --github-output` (exit early when there is nothing to release);
3. download the `coverage-ci4-sqlite` artifact of that CI run and regenerate the README coverage table from it;
4. `php scripts/release.php apply --notes=…` updates `VERSION` and `CHANGELOG.md` and writes the release notes;
5. commit `chore(release): vX.Y.Z [skip ci]` and create the tag (no token in this step);
6. push both with `git push --atomic`, using the release App's token;
7. `gh release create vX.Y.Z --notes-file …`.

`check-commits.sh` skips release commits. The release commit starts no new CI run because its message carries
`[skip ci]`; that marker is what prevents a release loop, since the App-token push would otherwise trigger CI.

### Push credentials: the release GitHub App

`main` is protected by a ruleset, so the workflow does not push with `GITHUB_TOKEN` (which stays read-only:
`contents: read`, plus `actions: read` for the coverage artifact). Step 6 and step 7 use an installation token of a
GitHub App, created by [`actions/create-github-app-token`](https://github.com/actions/create-github-app-token)
(pinned by commit SHA, like every action in the workflow) with `permission-contents: write`, only when there is a release
and right before the push. The checkout keeps no credentials (`persist-credentials: false`). Only two steps get the
token, as step-level environment: the push step hands it to `git` as an `http.extraheader` through `GIT_CONFIG_*`
variables (never on the command line or in `.git/config`; its base64 form is registered with `::add-mask::` before
use), and `gh release create` gets it as `GH_TOKEN`. `release.php apply` runs in the earlier step, without the token.
The workflow has no `set -x`, `bash -x` or `GIT_TRACE`, which could print the header; a test pins all of this.
[Dependabot](../.github/dependabot.yml) proposes updates to the pinned actions in one weekly `ci:` pull request.

One-time setup by a repository admin:

1. a GitHub App with the repository permission *Contents: Read and write* and nothing else, installed on this
   repository only;
2. an environment `release` whose deployment branches are limited to `main`, holding the variable
   `RELEASE_APP_CLIENT_ID` (the App's client ID) and the secret `RELEASE_APP_PRIVATE_KEY` (a private key of the App);
   the release job runs in that environment;
3. the App on the bypass list of the `main` ruleset, so its push of the release commit and tag is allowed.

To rotate the key, generate a new private key on the App, replace `RELEASE_APP_PRIVATE_KEY` and delete the old key.

### 3.0.0: the first release from the switched `main`

3.0.0 is cut the same way. After the branch-switch pull request, `main` is fast-forwarded to the CodeIgniter 4 branch
(the former `main` stays as the branch `old`). That push runs CI; when it is green, the release workflow finds the
breaking commits since `v2.6.26` (`feat!: serve the CI4 app from the repository root`, `docs!: allow CodeIgniter 4`),
so `plan` answers `bump=major`, `next=3.0.0`. `apply` folds the handwritten `## [Unreleased]` notes (upgrade notes,
intentional deviations and every bullet) into `## [3.0.0] - <date>`. Do not add a `## [3.0.0]` heading by hand and do
not bump `VERSION` in a pull request: `apply` inserts the section itself.

## By hand

```bash
npm run release:plan          # = php scripts/release.php plan: current version, last tag, bump, next version
```

To re-run a failed release, use *Actions → Release → Run workflow* (`workflow_dispatch`). The coverage step is skipped
then, since there is no CI run to download from. If a release must be made locally:

```bash
php scripts/release.php apply --notes=/tmp/notes.md
git commit -am "chore(release): v$(cat VERSION) [skip ci]"
git tag -a "v$(cat VERSION)" -m "v$(cat VERSION)" && git push --atomic origin HEAD:main "v$(cat VERSION)"
gh release create "v$(cat VERSION)" --notes-file /tmp/notes.md
```

`apply` refuses to run when `VERSION` does not match the last tag (exit 4) or when there is nothing to release (exit 3).

## History

Version 1.0.0 (pull requests #1–#13) was written by hand; everything after it is released by the workflow. Tags and
GitHub Releases start at `v2.6.0`; the changelog summarises earlier releases once per minor version.
