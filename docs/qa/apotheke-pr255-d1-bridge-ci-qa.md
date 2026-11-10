# QA: apotheke #255, run the D1 driver bridge tests with Node 22 and edge modules (CI)

- PR: https://github.com/thordin9/apotheke/pull/255 (DevOps)
- Reviewed head: `93ef4b9f67152556f35fe0289e6e33544c77e0c1` (base `cddcd8f`; main has since moved to the v3.29.0 release commit `caaf2f0`)
- Gate: light (diff read and CI; CI-only change)
- Reviewer: Apotheke QA 2

## Checklist

- [x] Scope: `.github/workflows/ci.yml` (+19) and `CHANGELOG.md` (+4). No app, edge or test source changes.
- [x] Tests job, pgsql leg only: `actions/setup-node@v7` with Node 22, the npm cache keyed on `edge/package-lock.json` and `npm ci --ignore-scripts --no-audit --no-fund` in `edge/`, all before PHPUnit. The pin style matches the other uses in this job. Node 22 satisfies `edge/package.json` `engines` (`>=22`) and the `node:sqlite` need (22.6+).
- [x] The sqlite coverage leg is unchanged: it still has its own Node 20 setup, gated on `matrix.db == 'sqlite'`.
- [x] Prove step: it runs `--filter D1DriverBridgeTest --fail-on-skipped --fail-on-empty-test-suite` against `tests/Modules/phpunit.xml.dist` (which exists), the same pattern as the HEIC prove step. Both flags exist in PHPUnit 11.5.57 (the version locked in `composer.lock`). Until #254 adds `tests/Modules/Core/D1DriverBridgeTest.php`, it is a no-op, as stated.
- [x] No secrets, tokens or new permissions. `--ignore-scripts` is kept, in line with #224 and #225.
- [x] CI on head: 21 checks green, including both Tests legs, DI rule, Secret scan, Conventional Commits and CodeQL.
- [x] CHANGELOG: the hand-written bullet sits under `### Maintenance`, the heading `release.php` uses for a `ci:` squash subject (see N-1581).
- [ ] Mergeable: `dirty`. The v3.29.0 release rewrote `[Unreleased]` in `CHANGELOG.md`. A plain main merge must keep the #255 bullet under `## [Unreleased]` / `### Maintenance` (see the verdict).

## Findings

| ID | Grade | Finding |
|---|---|---|
| N-1580 | Nit | The `[ -f tests/Modules/Core/D1DriverBridgeTest.php ]` guard turns the prove step into a silent pass if #254 lands the test under another path or name. Once #254 is on main, drop the guard so a missing file fails loudly. Optional; can go in the #254 follow-up. |
| N-1581 | Nit | `release.php` joins hand-written notes into the same `### Maintenance` heading, and it does not drop near-duplicates. The hand-written bullet ("run **the** D1 driver bridge tests") differs from the generated squash line, so the next release would list #255 twice. Drop the bullet, or word it exactly like the PR title. |

0 High, 0 Medium, 0 Low, 2 Nit.

## Verdict

**PASS** at `93ef4b9`. Before the doc commit, merge main in as a plain merge (no rebase). The merge may change only `CHANGELOG.md` and `VERSION`, and any #255 bullet that stays must sit under `[Unreleased]` / `### Maintenance`. I check that delta at the hash check.
