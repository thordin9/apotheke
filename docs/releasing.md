# Releasing

Apotheke uses [Semantic Versioning](https://semver.org/). This is the branch `old`, the 2.x line: since 3.0.0 it gets
security fixes only, as 2.6.x patch releases, for 90 days after 3.0.0. 3.x is released automatically from `main`; a
2.6.x release from `old` is started by hand (see [Cutting a 2.6.x release from `old`](#cutting-a-26x-release-from-old))
and never becomes the *Latest* GitHub Release.

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

Release commits (`chore(release): …`) and merge commits are ignored. The pull request number comes from the
`Merge pull request #N` merge commit that brought a commit to `main` (this repository merges with merge commits), or from
a trailing `(#N)` in a squash commit.

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

## Cutting a 2.6.x release from `old`

1. Merge the security fix into `old` through a pull request whose commits are `fix:` (a `feat:` or a breaking change
   cannot ship from `old`). Wait for CI's push run on `old` to pass.
2. *Actions → Release → Run workflow*, branch **`old`** (or `gh workflow run release.yml --ref old`).

[`.github/workflows/release.yml`](../.github/workflows/release.yml) on `old` only runs by hand (`workflow_dispatch`),
and only for the branch `old` (`if: github.ref == 'refs/heads/old'`):

1. check out `old` with full history; stop with an error unless a successful CI push run on `old` exists for that exact
   commit;
2. `php scripts/release.php plan --github-output` (exit early when there is nothing to release); stop with an error unless
   the next version is `2.6.x`;
3. `php scripts/release.php apply --notes=…` updates `VERSION` and `CHANGELOG.md` and writes the release notes; commit
   `chore(release): vX.Y.Z [skip ci]` and create the tag;
4. push both to `old` with `git push --atomic origin HEAD:old "vX.Y.Z"`;
5. `gh release create vX.Y.Z --notes-file … --latest=false`, then check that the *Latest* release is still a `v3.*`
   one and fail loudly if not (fix it with `gh release edit <newest v3 tag> --latest`).

The push uses `GITHUB_TOKEN` with `contents: write` (plus `actions: read` for the CI check): the release GitHub App that
`main` uses lives in an environment that only admits `main`. A ruleset on `old` must therefore let a `GITHUB_TOKEN`
fast-forward push through (deletion and force-push protection is fine; required status checks are not, since the
release commit runs no CI). The actions are pinned by commit SHA, the checkout keeps no credentials, and the token
reaches git only in the push step, masked. `v2.6.*` tags come only from `old` and `v3.*` only from `main`; never merge
`old` into `main` (forward-port fixes with a pull request to `main` instead). The README coverage table is not refreshed
on `old`.

`check-commits.sh` skips release commits. The release commit starts no new CI run: its message carries `[skip ci]`
(and a `GITHUB_TOKEN` push starts no workflows anyway).

## By hand

```bash
npm run release:plan          # = php scripts/release.php plan: current version, last tag, bump, next version
```

To re-run a failed release, run the workflow again on `old`. If a release must be made locally (on `old`; pass
`--latest=false` and keep a 2.6.x version):

```bash
php scripts/release.php apply --notes=/tmp/notes.md
git commit -am "chore(release): v$(cat VERSION) [skip ci]"
git tag -a "v$(cat VERSION)" -m "v$(cat VERSION)" && git push --atomic origin HEAD:old "v$(cat VERSION)"
gh release create "v$(cat VERSION)" --notes-file /tmp/notes.md --latest=false
```

`apply` refuses to run when `VERSION` does not match the last tag (exit 4) or when there is nothing to release (exit 3).

## History

Version 1.0.0 (pull requests #1–#13) was written by hand; everything after it is released by the workflow. Tags and
GitHub Releases start at `v2.6.0`; the changelog summarises earlier releases once per minor version.
