# PR 268 client IP rate-limit keys — QA

**Verdict: PASS** at `ec0a367236f1dee71c77e33284a35c1ded9039fd`. Plain merge of `af96601f` and main `e1024952`. Behind main: 0. No new finding.

Branch `fix/edge-client-ip-rate-limit`. No suites were re-run.

## Checklist

| Check | Result |
|---|---|
| Parents | `af96601f108e8f7c8b7e6b41f20b88995f2d9080` and `e10249527bc302d06b235a98730187a8a1582d90`. `git merge-tree` exits 0. The tip tree matches that automatic merge. |
| Main range | `9bccc634..e1024952` is one commit, #267. It changes `docs/deployment-and-operations.md` and adds a QA note. |
| Own diff | 14 files against main. All 14 stable patch-ids match the previous head's diff against `9bccc634`. |
| Kept | `APOTHEKE_EDGE_CLIENT_IP` is still `1` in the container env. The #268 bullet appears once under Unreleased. `## [3.30.0]` matches main. |
| Earlier red | On `af96601f`, CI run `42935714693` failed only `Tests (PHP 8.4, pgsql)`. The module suite's only failure was `UnitOfWorkLockTest::testAMultiKeyUnitTakesEachKeyLock`: 169.79139 ms against a bound of 100. The outer `Ci4ModuleSuiteTest` failure is that suite's exit code. |
| CI | Run `42944441722` on this head completed success. Every job succeeded, including `D1 (local workerd)` and `Tests (PHP 8.4, pgsql)`. |

## Findings

No new finding. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. N-1850 stays on #258. Next free id is 1851.

## Verdict

PASS. The hosted gate stays unsatisfied.
