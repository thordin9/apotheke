# PR 271 container sleep after start — QA

**Verdict: PASS** at `be8345de190ef6cc4dafd94c047d74b623970b49`. Behind main `0840308c` (v3.36.2): 0. H-1849 stays closed. N-1850 stays open. N-1851 stays open, non-blocking. No new finding.

Branch `fix/container-sleep`, based on `main`. The previous pass head `79a26def` is an ancestor. Since then: the merge of main `0840308c`, which brings #268, #276 and #279.

## Checklist

| Check | Result |
|---|---|
| Merge | Parents `79a26def` and `0840308c`. The changelog and the handoff conflicted; both sides are kept. `edge/src/index.ts` merged automatically. |
| Own diff | Outside the changelog, the handoff and the QA docs, the diff against main equals the previous pass's diff against `fc7e3aa2` except for shifted hunk line numbers. |
| Index | No `monitor()` or `waitUntil` call. `setInactivityTimeout(SLEEP_AFTER_MS)` follows `start()`, and the constructor sets it again when the container is already running. #268's `APOTHEKE_EDGE_CLIENT_IP: "1"` and main's `STORAGE_BACKEND: "r2", DB_DRIVER: "d1"` are intact. |
| Handoff | Main's #268 sentence is kept, and #271's sleep sentence is added before `EDGE_STORAGE_READY stays off.` |
| Changelog | The diff against main is `### Fixed` plus the #271 bullet under Unreleased. `#271` occurs once. `## [3.36.2]` and every older section match main. |
| CI | Run `43125639967`: `D1 (local workerd)` job `130876887823` succeeded. SQLite job `130876887493` and PostgreSQL job `130876887925` were still running. No failure. |

## Findings

No new finding. Note for the follow-up PR, not for #271: the timeout is set only after a start from this object and in the constructor when the container is already running. A request that finds the container already running in a live object returns early without setting it, so a container started some other way keeps no timeout until the object restarts.

H-1849 stays closed. N-1850 and N-1851 stay open. Next free id is 1852.

## Security

No security-relevant change. Client-IP handling and the storage-mode env from main are unchanged, and the container still starts without internet access.

## Tests

None run here. Merge-only read; the PR's own tests are unchanged from the previous pass.

## Verdict

PASS. The hosted gate stays unsatisfied.
