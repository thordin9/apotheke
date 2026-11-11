# PR 268 client IP rate-limit keys — QA

**Verdict: PASS** at `47595b865f408953cb562174c44756a0e2f541f7`. Behind main `67b9471b`: 0. H-1849 stays closed. N-1850 stays open. One new non-blocking note, N-1851.

Branch `fix/edge-client-ip-rate-limit`, based on `main`. The previous pass head `de59ba46` is an ancestor. Since then: the no-ff merge of main `67b9471b` (#279).

## Checklist

| Check | Result |
|---|---|
| Merge | Parents `de59ba46` and `67b9471b`. Only the changelog conflicted; both Unreleased entries are kept. The handoff merged automatically. |
| Own diff | Outside the changelog, the diff against main is identical to the previous pass's diff against `fc7e3aa2`. Main's #276 and #279 changes are intact. |
| Index and test | Unchanged from the previous pass: the container env keeps `APOTHEKE_EDGE_CLIENT_IP: "1"` with main's `STORAGE_BACKEND: "r2", DB_DRIVER: "d1"`, the Worker resets `X-Apotheke-Client-IP` from `cf-connecting-ip`, and the installation test asserts `d1` and the flag. |
| Coverage | The address tests are unchanged and still cover the flag on, the flag off, `TRUST_PROXY` alone, and a value that is not an address. |
| Changelog | The #268 bullet appears once under Unreleased, next to #279's. `## [3.36.0]` and `## [3.35.0]` match main. See N-1851. |
| CI | Run `43119966090`: `D1 (local workerd)` job `130857636135` succeeded. SQLite job `130857636255` and PostgreSQL job `130857636100` were still running. No failure. |

## Findings

- **N-1851** (non-blocking): the changelog adds one extra blank line before `## [3.30.0]`. It renders the same and was already present at the previous pass head. It can be dropped in a later changelog edit.

H-1849 stays closed. N-1850 stays open. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1852.

## Security

Hosted rate-limit keys still come from `cf-connecting-ip` only when `APOTHEKE_EDGE_CLIENT_IP` is `1`. `TRUST_PROXY` alone still does not turn that on. Caddy still drops a client-supplied copy when the flag is not `1`.

## Tests

None run here. Merge-only read; the client-IP tests are unchanged from the previous pass.

## Verdict

PASS. The hosted gate stays unsatisfied.
