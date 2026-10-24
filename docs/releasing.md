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
--no-merges`) and applies the [Conventional Commits](contributing.md) rules:

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

## The release workflow

[`.github/workflows/release.yml`](../.github/workflows/release.yml) runs after a successful CI run for a push to `main`.
A green CI run on `feature/ci4` does not start it: `workflow_run.branches` is `[main]` only, and a manual run checks out `main`.

1. check out `main` with full history; stop if `main` is no longer the commit CI tested (that newer commit's CI run
   releases it);
2. `php scripts/release.php plan --github-output` (exit early when there is nothing to release);
3. download the `coverage-sqlite` artifact of that CI run and regenerate the README coverage table from it;
4. `php scripts/release.php apply --notes=…` updates `VERSION` and `CHANGELOG.md` and writes the release notes;
5. commit `chore(release): vX.Y.Z [skip ci]`, create the tag and push both with `git push --atomic`;
6. `gh release create vX.Y.Z --notes-file …`.

`check-commits.sh` skips release commits, and the release commit starts no new CI run.

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

Version **1.0.0** is the baseline: tag `v1.0.0` marks `main` after pull requests #1–#13, and its changelog section was
written by hand. Everything after it is released by the workflow.
