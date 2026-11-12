# QA: apotheke #284, update stale release, sign-in, and hosted storage status

- PR: https://github.com/thordin9/apotheke/pull/284 (Coder), base `main`. Fixes L-1929 from the 2026-11-12 live audit, plus the PM's d1-refusal wording.
- Reviewed head: `32de14a184fa9117e2c8a911a7c909a6ed94249e`, 0 behind main `00945588` (v3.36.6). Commits: `a75e8a69` docs: update stale release, sign-in, and hosted storage status (62 chars), `26370130` docs(changelog): link the L-1929 docs PR in Unreleased (54 chars), `32de14a1` docs(handoff): state what d1 still refuses before real data (59 chars).
- Gate: light (docs only: diff read, facts checked against the CI4 code at the head, live `/healthz` and GET `/login`, latest GitHub release, CHANGELOG byte-compare, CI). No suites, mutations or Docker.
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope is 3 files: `docs/HANDOFF.md` (+4/−4), `docs/deployment-and-operations.md` (+9/−6), `CHANGELOG.md` (+4/−0). No unexpected files.
- [x] HANDOFF: latest release v3.36.6 (matches the latest GitHub release and `VERSION`; the v3.36.6 tag points at main `00945588`). Next ids QA 1854 (claimed only) and QA 2 1932 (correct at this review). No leftover v3.28.0 or 1846.
- [x] d1 refusals match the code and tests: `Writes::refuseOnD1(..., 'Restoring a backup')` on the restore page, API, Restorer, fence and mutation; `Duplicate` returns 501 when photos would be copied (without photos it copies); `ItemController::save` returns 422 for an inline new location or category (`Kind::KINDS` is only those two) and writes nothing. No other user-facing d1 refusal. `Imports::run(..., true)` still refuses the old transactional path; `commit()` / `preview()` go through `commitHosted()` and `hostedLine()` (`D1HostedImportTest`). Bulk editing runs as one unit of work (`D1HostedBulkTest`, `D1HostedRefusalsTest`). The stale 2-ii-B clause is corrected.
- [x] Live: `https://api.services.getapotheke.com/healthz` is 200, body `ok`, `x-apotheke-version: 3.36.6`. GET `https://api.services.getapotheke.com/login` is 200, title "Sign in · Apotheke" (nothing was submitted). The two deploy-doc storage-mode paragraphs are byte-identical to the HANDOFF Next-steps sentence. The added #284 changelog URL resolves (200).
- [x] CHANGELOG: exactly one `### Changed` under [Unreleased], with the full #284 URL. The released sections are byte-identical to main (177065 bytes). No secrets in the diff. Conventional Commits and Secret scan are green on the head.

## Status of L-1929

- **L-1929** closed: HANDOFF no longer says v3.28.0 or finding id 1846; the post-flip check signs in at `https://api.services.getapotheke.com/login` and notes that the site's sign-in is being fixed.

## Findings

- **N-1932:** the new "hosted API is live" paragraph in `docs/deployment-and-operations.md` is immediately followed by future-tense gate text: "The first `apotheke-api` deploy waits for the storage design… Until then the deploy job does not run…", the job's fail sentence "apotheke-api is stateless until P4 storage (D1/R2) lands", "Set it … only once storage has landed", and "One-time manual step before the first edge deploy… there is nothing to do yet." The bullets still describe the gate correctly. Hardening: put that section in the past tense, or say the variable is already true.

0 High, 0 Medium, 0 Low, 1 Nit.

## Verdict

**PASS** at `32de14a1`. CI run 43207565129 was still in progress at review (21 check runs on the head: 19 success, 2 in progress — Tests PHP 8.4 sqlite and pgsql). It must be green before merge. Squash-merge with the PR title as the subject. N-1932 can go in a follow-up.
