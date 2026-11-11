# PR 258 D1 selection and migrations — QA

**Verdict: PASS** at `eebc5a362091aaeaf19b020a43f47309e99759cb`. No-ff merge of `c0e3512b` and main `e1024952`. Behind main: 0. H-1849 stays closed. N-1850 stays open. No new finding.

Branch `feat/p4-5-2b-chunk2-i-v2`. No suites were re-run.

## Checklist

| Check | Result |
|---|---|
| Parents | `c0e3512b7c26979b7fb0efd0a23524f0f3fd7ccb` and `e10249527bc302d06b235a98730187a8a1582d90`. Subject: `Merge origin/main into merge/258-main2`. |
| Diff | `c0e3512b..eebc5a36` is only `docs/deployment-and-operations.md` and `docs/qa/apotheke-pr267-preview-plan-docs-qa.md`, the same two files as `e1024952`. |
| Own diff | 26 files against main. All 26 stable patch-ids match `c0e3512b` against `9bccc634`. `git merge-tree` exits 0 and the tip tree matches it. |
| Changelog | The #258 bullet appears once under Unreleased. `## [3.30.0]` matches main. |
| CI | `D1 (local workerd)` job `130295848793` succeeded on this head. Rollup: 14 success, 8 pending, 1 skipped. |

## Findings

H-1849 stays closed. N-1850 stays open: the stand-in still refuses `_cf_` inside a string literal. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1851.

## Verdict

PASS. The hosted gate stays unsatisfied.
