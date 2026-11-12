# QA: apotheke #285, drop unrecognised bluetooth from Permissions-Policy

- PR: https://github.com/thordin9/apotheke/pull/285 (DevOps), base `main`. Fixes L-1923 from the 2026-11-12 live audit. L-1924 (zone HSTS) is out of scope and is tracked separately.
- Reviewed head: `7748e1f813a52bd2e63dc80637bd1345e7ace6eb` (then 1 behind main `48d2fbc1`; merge-base v3.36.6 `00945588`). Commits: `be8d90b5` fix(site): drop unrecognised bluetooth from Permissions-Policy (62 chars), `7748e1f8` docs(changelog): add the pull-request link for the L-1923 fix (61 chars).
- Merge-only reads. (1) At `a64e10d7`, a plain merge of main `48d2fbc1` (#284) into `7748e1f8`; doc `949cb372` was committed at `188bcf3c`. (2) At `a415d7f181c3e8797d79d87e35531ae09518eb9d`, 0 behind main `644a5d1d` (v3.36.7). Since `188bcf3c`: `badb1857`, a plain merge of main `00afbb55` (#287; parents `188bcf3c`, `00afbb55`), and `a415d7f1`, a plain merge of main `644a5d1d` (release v3.36.7; parents `badb1857`, `644a5d1d`). The +/- lines of `48d2fbc1...188bcf3c` match `main...a415d7f1` for every file, and `edge/app/src/index.ts`, `frontend/public/_headers` and `frontend/scripts/check-dist.mjs` are byte-identical to `188bcf3c` (and to `7748e1f8`). CHANGELOG [Unreleased] holds only #285's bullet under `### Fixed`; everything from `## [3.36.7]` down is byte-identical to main. This supersedes `dd011653` and `949cb372` (both void).
- Gate: light (diff read, the three policy strings compared, repo grep, current Chrome / Chromium feature list, `check-dist` and landing-Worker tests, two mutants, CHANGELOG byte-compare, CI). Catch-up: merge-only read, no tests or mutants. No full suites, Docker or deploy.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope is 4 files: `edge/app/src/index.ts` (+1/−1), `frontend/public/_headers` (+1/−1), `frontend/scripts/check-dist.mjs` (+1/−1), `CHANGELOG.md` (+4/−0). After dropping the `bluetooth=(), ` token, the three code files are byte-identical to merge-base `00945588`. CSP and HSTS lines are unchanged (Front-End #286 and L-1924 stay untouched). No unexpected files. Conventional Commits and Secret scan are green on the head.
- [x] `bluetooth` is gone from all three sources, and the three Permissions-Policy values are byte-identical (197 bytes). A repo-wide grep at the head finds no other `bluetooth` and no other Permissions-Policy definitions: not in `edge/` (the API container Worker), the API PHP (`ApiSecurityPolicy` / `SecurityPolicy` do not set this header), tests or docs that list features. Docs mention the header but never listed `bluetooth`, so they need no edit.
- [x] Remaining feature names: `camera`, `microphone`, `geolocation`, `accelerometer`, `gyroscope`, `magnetometer`, `payment`, `usb`, `serial`, `hid`, `midi` and `display-capture` are in current Chromium `permissions_policy_features.json5`. `interest-cohort` and `browsing-topics` are not (see N-1933). Current Chrome stable is 157; those two are removed from Chromium main for M159.
- [x] Tests at `7748e1f8`, Node v24.22.1: `node --test frontend/scripts/check-dist.test.mjs` 10/10; `node --test --experimental-strip-types scripts/deploy-scripts.test.mjs` 33/33 (includes Worker security-header presence). Mutant 1: `bluetooth=()` restored only in `frontend/public/_headers` — killed by `check-dist.test.mjs` (actual vs `PERMISSIONS_POLICY`); `deploy-scripts.test.mjs` still 33/33. Mutant 2: restored only in `edge/app/src/index.ts` — both unit tests survived (see N-1934). `scripts/app-worker-check.mjs` (CI, needs `wrangler dev`) would compare the Worker header to `dist/_headers`.
- [x] CHANGELOG at `a415d7f1`: one `### Fixed` (#285, full URL) under [Unreleased]; #284's bullet shipped in [3.36.7]. No empty or duplicate headings. The released sections are byte-identical to main. No secrets in the diff.

## Status of L-1923 and L-1924

- **L-1923** closed in the repo: `bluetooth=()` is removed from the landing Worker `COMMON` headers, `frontend/public/_headers` and the `check-dist` assertion. The live console warning on getapotheke.com is verified only after deploy (not in this review; DevOps asked not to deploy).
- **L-1924** not in this PR: no HSTS values changed. Zone-level alignment stays with DevOps.

## Findings

- **N-1933:** `interest-cohort=()` and `browsing-topics=()` remain in all three (now identical) policy strings. Chromium main has already removed both Permissions-Policy features (mojom: "Removed in M159"). They do not currently log the L-1923-style warning: Chrome stable is 157, and the 2026-11-12 live audit only recorded `Unrecognized feature: 'bluetooth'`. Hardening: drop them in the same three places before M159 ships.

- **N-1934:** `deploy-scripts.test.mjs` only asserts that a Worker response has a `permissions-policy` header, not that `COMMON` matches `_headers`. Putting `bluetooth=()` back in `edge/app/src/index.ts` alone left both unit tests green. CI's `app-worker-check.mjs` (running `wrangler dev` against `dist/_headers`) would catch that drift. Hardening: pin the exact `COMMON` Permissions-Policy value against `_headers` in the unit test.

0 High, 0 Medium, 0 Low, 2 Nits.

## Verdict

**PASS** at `7748e1f8` (CI run 43209079550), confirmed at `a64e10d7` and at `a415d7f1` by merge-only reads. CI run 43222484478 is green on `a415d7f1` (18/18 jobs success); all 23 non-skipped check runs on that head succeeded and Deploy getapotheke-app was skipped, as intended. The head is 0 behind main `644a5d1d` and mergeable (clean). Squash-merge with the PR title as the subject. N-1933 and N-1934 can go in a follow-up. Do not deploy from this review.
