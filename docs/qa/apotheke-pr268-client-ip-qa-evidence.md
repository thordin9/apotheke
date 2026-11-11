# PR 268 client IP rate-limit keys — evidence

Read-only. Head `ec0a367236f1dee71c77e33284a35c1ded9039fd` matches `origin/fix/edge-client-ip-rate-limit`. Subject: `Merge remote-tracking branch 'origin/main' into fix/edge-client-ip-rate-limit`. Parents: `af96601f108e8f7c8b7e6b41f20b88995f2d9080` and `e10249527bc302d06b235a98730187a8a1582d90`. `git rev-list --count HEAD..origin/main` is 0.

## Main

`e1024952` has one parent, `9bccc634`. Its diff is `docs/deployment-and-operations.md` and `docs/qa/apotheke-pr267-preview-plan-docs-qa.md`. `CHANGELOG.md` is identical between those two commits.

## Patch-ids

`git diff 9bccc634 af96601f` and `git diff e1024952 ec0a3672` are the same 14 paths. `git patch-id --stable` matches on all 14. `git merge-tree --write-tree` exits 0 with tree `0162754a062d8b222b1acbf6ebe5aae1e4a1f63c`. `git diff` of that tree against the tip is empty.

`#268` occurs once, under `## [Unreleased]`. `## [3.30.0]` through `## [3.29.1]` equals main. `edge/src/index.ts` still sets `APOTHEKE_EDGE_CLIENT_IP: "1"`.

## Earlier pgsql job

Run `42935714693`, job `130245177271`, `Tests (PHP 8.4, pgsql)`, is the only non-success job on `af96601f`. The log's only test failure is `Tests\Core\UnitOfWorkLockTest::testAMultiKeyUnitTakesEachKeyLock` at `tests/Modules/Core/UnitOfWorkLockTest.php:170`: `Failed asserting that 169.79139 is less than 100.` The module suite reports Tests 1661, Failures 1. `Ci4ModuleSuiteTest::testCi4ModuleSuitePasses` then fails because that exit code is not 0 (Tests 592, Failures 1). Run `42944441722` on `ec0a3672` has `Tests (PHP 8.4, pgsql)` and `D1 (local workerd)` success, and the run conclusion is success.

## Finding index

No new id. Next free id: 1851.
