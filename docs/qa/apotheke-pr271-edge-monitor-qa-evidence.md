# PR 271 container inactivity — evidence

Read-only. Head `0aa3f783b71c89782688267c121e324bcf88ba6b` matches `origin/fix/container-sleep`. Subject: `Merge remote-tracking branch 'origin/main' into fix/container-sleep`. Parents: `335319e04b4e0ccee3b23ebcfe20530844ca66f1` and `e10249527bc302d06b235a98730187a8a1582d90`. `git rev-list --count HEAD..origin/main` is 0.

## Main

`e1024952` changes only `docs/deployment-and-operations.md` and `docs/qa/apotheke-pr267-preview-plan-docs-qa.md`.

## Patch-ids

`git diff 9bccc634 335319e0` and `git diff e1024952 0aa3f783` are the same 6 paths. `git patch-id --stable` matches on all 6. `git merge-tree --write-tree` exits 0 with tree `e57657fc1e781d0f0bd25a2ab45cdcc519032dd4`. `git diff` of that tree against the tip is empty.

`#271` occurs once, under `## [Unreleased]`. `## [3.30.0]` through `## [3.29.1]` equals main. `edge/src/index.ts` still calls `setInactivityTimeout(this.sleepAfter())` at both start sites. `git grep` for `c.monitor` and `waitUntil(` finds only the comment that says not to call them.

## CI

Run `42944447642`, head `0aa3f783`, conclusion success. `D1 (local workerd)`, both PHPUnit database jobs, and the other listed jobs are success.

## Finding index

No new id. Next free id: 1851.
