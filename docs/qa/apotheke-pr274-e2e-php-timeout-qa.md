# QA: apotheke #274, cap the E2E PHP 8.4 install step and each Launchpad lookup

- PR: https://github.com/thordin9/apotheke/pull/274 (DevOps)
- Reviewed head: `dad40a255bd50bcd37c91bb88aee4c94541f6118`
- Gate: light (CI-only diff read, CI timings; no suites)
- Reviewer: Apotheke QA 2

## Checklist

- [x] One commit, three files: `.github/workflows/ci.yml` (+2), `scripts/e2e-container-setup.sh` (+4/−1), `docs/ci.md` (+1/−1). No application code. 0 behind main.
- [x] `retry timeout --kill-after=10 120 add-apt-repository -y -n ppa:ondrej/php`: a stalled lookup now exits 124 (or 137 after the kill) and counts as a failed attempt, so `retry()` (4 attempts, 20/40/60 s pauses) gets its further tries. Worst case is about 4 × 130 s + 120 s ≈ 11 min, inside the new 15-minute step cap. `timeout` is coreutils and present in the Playwright noble image.
- [x] `timeout-minutes: 15` sits on the `Install PHP ${{ matrix.php }}` step only, below the 30-minute job timeout, with the reason beside it.
- [x] `docs/ci.md` describes both caps accurately in the Visual E2E row.
- [x] CI run 43025457653 at this head: every check green except the three Visual E2E shards, which failed in `Install PHP 8.4` after about 10 min 10 s each (13:02–13:13 UTC). That matches four capped attempts plus the pauses during the Launchpad outage, so the cap works as intended. The red is upstream, not this change.
- [x] The commit subject is a valid Conventional Commit (`ci(e2e): …`). No CHANGELOG line (CI-only; expected).

## Findings

None. 0 High, 0 Medium, 0 Low, 0 Nit.

## Verdict

**PASS** at `dad40a25`. Merge only after the three Visual E2E shards are re-run green once Launchpad recovers. Squash-merge with the PR title as the subject.
