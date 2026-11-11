# QA: apotheke #275, install the E2E PHP 8.4 from the setup-php prebuilt, not Launchpad

- PR: https://github.com/thordin9/apotheke/pull/275 (DevOps), stacked on #274 (`0a0de6db`)
- Reviewed head: `154e2cf9ba014c7b69d22d752efda9f8028e4c7c` (new commit only; #274's commits were gated in #274)
- Gate: light (CI-only diff read, CI run and step timings; no suites)
- Reviewer: Apotheke QA 2

## Checklist

- [x] One new commit on `0a0de6db`: `scripts/e2e-container-setup.sh` (+50), `.github/workflows/ci.yml` (+2/−1), `docs/ci.md` (+1/−1). No application code. 0 behind main, `mergeable_state` clean.
- [x] 8.4 only: the prebuilt path extracts just `php8.4`, `cli/php.ini` and the allowlisted extensions, enables exactly those, and refuses the build (falling back to the PPA path) on a missing shared library, a `php -v` warning or an extension that doesn't load. Every function step returns on failure, so `set -e` being off inside the `if` is handled. 8.3 (the snapshot baselines) is unchanged.
- [x] `ci.yml` installs `php8.4-intl` only when intl isn't loaded (`grep -qix`), which is the PPA fallback.
- [x] CI run 43028860588 at this head: 21/21 checks green. The `Install PHP 8.4` step took 9–10 s on each Visual E2E shard while Launchpad was unreachable, so the prebuilt path ran, not the fallback.
- [x] `docs/ci.md` describes the prebuilt path, the allowlist, the fallback and the intl rule accurately. The commit subject is a valid Conventional Commit. No CHANGELOG line (CI-only; expected).

## Findings

| ID | Grade | Finding |
|---|---|---|
| L-1899 | Low | The tarball is a rolling third-party release asset (`shivammathur/php-ubuntu`, tag `builds`) downloaded without an integrity check, where the repo pins Composer by sha256 and Imagick and hadolint by tag or digest. The Tests jobs already trust the same build through setup-php, so the trust boundary is not new. Hardening: check the download against a pinned sha256 (GitHub publishes the asset digest) and treat a mismatch like any other failure, so an upstream rebuild drops to the PPA until the pin is bumped. At least log the tarball's sha256 for traceability. |
| L-1900 | Low | The fallback runs on top of the prebuilt's leftovers: only `/usr/bin/php` is removed, while `/usr/bin/php8.4`, `cli/php.ini`, the extracted `.so` and `mods-available/*.ini` files and the `conf.d` links stay. The PPA install then runs on that state, and the fallback path was not exercised in CI. Extract into a staging dir and move it into place only after the checks pass, or remove everything extracted before falling back. |
| N-1901 | Nit | `curl --retry 5 --max-time 300` allows about 30 min of download attempts, past the 15-minute step cap, so a stalled GitHub download fails the step instead of reaching the fallback. A per-attempt `--max-time` of about 120 s keeps the fallback reachable. |

0 High, 0 Medium, 2 Low, 1 Nit.

## Verdict

**PASS** at `154e2cf9`. The Lows and the Nit don't block and can be a follow-up. Merge after #274 (or with it, if the PM takes this run's green Visual E2E as standing in for #274's). Squash-merge with the PR title as the subject.
