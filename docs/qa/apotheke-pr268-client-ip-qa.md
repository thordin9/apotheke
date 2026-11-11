# PR 268 client IP rate-limit keys — QA

**Verdict: PASS** at `693ac32f937ae3cfcb30e55bdb00240839afb4dd`. Behind main `a9d89163` (v3.36.1): 0. H-1849 stays closed. N-1850 stays open. N-1851 stays open, non-blocking.

Branch `fix/edge-client-ip-rate-limit`, based on `main`. The previous pass head `47595b86` is an ancestor. Since then: a QA-doc commit `fb4b08ee`, then the merge of main `a9d89163`.

## Checklist

| Check | Result |
|---|---|
| Merge | Parents `fb4b08ee` and `a9d89163`. Main moved only in the changelog, `README.md` and `VERSION`. The #268 bullet, placed inside `## [3.36.1]` by the automatic merge, was moved back under Unreleased in the merge commit. |
| Own diff | Outside the changelog, the diff against main is identical to the previous pass's diff against `67b9471b`. `README.md` and `VERSION` equal main. |
| Index and test | Unchanged: the container env keeps `APOTHEKE_EDGE_CLIENT_IP: "1"` with main's `STORAGE_BACKEND: "r2", DB_DRIVER: "d1"`, the Worker resets `X-Apotheke-Client-IP` from `cf-connecting-ip`, and the installation test asserts `d1` and the flag. |
| Coverage | The address tests are unchanged and still cover the flag on, the flag off, `TRUST_PROXY` alone, and a value that is not an address. |
| Changelog | The diff against main is the #268 bullet under Unreleased `### Fixed` plus the N-1851 blank line. `#268` occurs once. `## [3.36.1]` and every older section match main. |
| CI | Run `43121041546` had just started: `D1 (local workerd)` job `130861399434`, SQLite job `130861399486` and PostgreSQL job `130861399605` were pending. The pass needs D1 green. |

## Findings

- **N-1851** (open, non-blocking): one extra changelog blank line before `## [3.30.0]`. It renders the same. Drop it in a later changelog edit.

H-1849 stays closed. N-1850 stays open. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1852.

## Security

Hosted rate-limit keys still come from `cf-connecting-ip` only when `APOTHEKE_EDGE_CLIENT_IP` is `1`. `TRUST_PROXY` alone still does not turn that on. Caddy still drops a client-supplied copy when the flag is not `1`.

## Tests

None run here. Merge-only read; the client-IP tests are unchanged from the previous pass.

## Verdict

PASS, subject to `D1 (local workerd)` going green. The hosted gate stays unsatisfied.
