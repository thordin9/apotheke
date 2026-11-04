# QA: apotheke #226, bump symfony/yaml from 8.1.10 to 8.1.11

**Gate:** light (diff read and CI). **Head:** `e3a101cf7cb251e7ff6e502c5a131a9efb66201b`, one commit (`e3a101c`). 0 behind main (`9931286`), mergeable and clean. **Reviewer:** Apotheke QA 2.
**Verdict: PASS at `e3a101c`.** No findings.

## Checklist

- [x] **CI.** 20 check runs are green, including the DI rule (composer audit), Secret scan, Lint, both test suites, the upgrade and rollback runs, Docker smoke and Visual E2E. CodeQL is green.
- [x] **Scope.** Only `composer.lock` changes, in the `symfony/yaml` entry: version, source and dist reference, support URL and release time. `composer.json` and the lock `content-hash` are unchanged, and no other package moves.
- [x] **Provenance.** The new reference `e8266f55160f674f290a5f567cb55e11e21f921b` and the time `2026-11-04T08:39:20+00:00` match Packagist's `v8.1.11` entry, with the dist URL pointing to the upstream `symfony/yaml` repository.
- [x] **Package role.** `symfony/yaml` is a dev dependency, pulled in through the OpenAPI tooling, so it doesn't ship in the production image.
- [x] **Commit.** `build(deps): bump symfony/yaml from 8.1.10 to 8.1.11` is a valid Conventional Commit. `release.php` builds the CHANGELOG entry from the squash subject.

## Conditions

Squash-merge with the PR title as the subject, before main is merged into the other open PRs. No secrets, tokens or credentials appear in the diff or this doc.
