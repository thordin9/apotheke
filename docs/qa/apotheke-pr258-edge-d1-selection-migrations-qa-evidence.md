# PR 258 D1 selection and migrations — evidence

Read-only. Head `eebc5a362091aaeaf19b020a43f47309e99759cb` matches `origin/feat/p4-5-2b-chunk2-i-v2`. Parents: `c0e3512b7c26979b7fb0efd0a23524f0f3fd7ccb` and `e10249527bc302d06b235a98730187a8a1582d90`. `git rev-list --count HEAD..origin/main` is 0. `origin/main` is `e1024952`.

`git diff c0e3512b eebc5a36 --name-only` is `docs/deployment-and-operations.md` and `docs/qa/apotheke-pr267-preview-plan-docs-qa.md`. `git diff 9bccc634 e1024952 --name-only` is those same two files.

`git diff 9bccc634 c0e3512b` and `git diff e1024952 eebc5a36` are the same 26 paths. `git patch-id --stable` matches on all 26. `git merge-tree --write-tree` exits 0 with tree `552b90b32f18c4e0008141300d1b79e22b06cd6f`. `git diff` of that tree against the tip is empty.

`#258` occurs once, under `## [Unreleased]`. `## [3.30.0]` through `## [3.29.1]` equals main.

CI run `42951261191`, job `130295848793`, `D1 (local workerd)`, conclusion success, head `eebc5a36`. Rollup at that time: 14 SUCCESS, 8 pending, 1 skipped.

H-1849 stays closed. N-1850 stays open. Next free id: 1851.
