# PR 271 container inactivity — QA

**Verdict: PASS** at `0aa3f783b71c89782688267c121e324bcf88ba6b`. Plain merge of `335319e0` and main `e1024952`. Behind main: 0. No new finding.

Branch `fix/container-sleep`. No suites were re-run.

## Checklist

| Check | Result |
|---|---|
| Parents | `335319e04b4e0ccee3b23ebcfe20530844ca66f1` and `e10249527bc302d06b235a98730187a8a1582d90`. `git merge-tree` exits 0. The tip tree matches that automatic merge. |
| Main range | `9bccc634..e1024952` is the #267 docs commit only. |
| Own diff | 6 files against main. All 6 stable patch-ids match the previous head's diff against `9bccc634`. |
| Kept | `setInactivityTimeout(this.sleepAfter())` is still called. There is no `monitor()` or `waitUntil` call. The #271 bullet appears once under Unreleased. `## [3.30.0]` matches main. |
| CI | Run `42944447642` on this head completed success. Every job succeeded, including `D1 (local workerd)`. |

## Findings

No new finding. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. N-1850 stays on #258. Next free id is 1851.

## Verdict

PASS. The hosted gate stays unsatisfied.
