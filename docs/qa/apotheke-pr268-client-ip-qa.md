# PR 268 Worker client IP — QA

**Verdict: PASS** at `1b60c7fd1ae3dd606b971e689a06f51eddb8af56`. The client-IP change is the same 13 files, +176/−7, as `a73adeec`. The v3.29.1 merge adds the release files, and the tip puts the #268 bullet back under Unreleased.

Branch `fix/edge-client-ip-rate-limit`. Base `main`. Behind 0. `c182d50a` parents are `a73adeec` and `bb1c7748`. `1418a7b4` merges main `72582532`. `1b60c7fd` is the tip. CI at review: 9 passed, 1 skipped (the app deploy), 14 still running, 0 failed. The FrankenPHP smoke job had not finished. `frankenphp` is not on this machine. The quoted-matcher assertions passed.

## Checklist

| Check | Result |
|---|---|
| Byte match | `git diff bb1c7748 c182d50a` is byte-identical to `git diff 301540e1 a73adeec`: 13 files, +176/−7. |
| #264 kept | That identity means the readiness host and the forwarded-header overwrite from `bb1c7748` are still the base. `edge/src/index.ts` at the tip differs from `bb1c7748` only by the client-IP lines, and those lines match `a73adeec`. |
| Changelog | The #268 bullet appears once, under `## [Unreleased]` / `### Fixed`. `## [3.29.1]` through `## [3.29.0]` matches main. |
| Release delta | `c182d50a` to the tip is `CHANGELOG.md`, `README.md`, and `VERSION`. README and VERSION match main. |
| Behaviour | The flag, the header replacement, and the Caddy matcher are unchanged from the `a73adeec` review. |

## Findings

No new finding. N-1786 stays deferred. N-1794, N-1795, L-1811, and N-1812 stay tracked. Next free id is 1849.

## Security

A client `X-Apotheke-Client-IP` is still deleted and replaced from `cf-connecting-ip`. `TRUST_PROXY` alone does not enable it. The readiness probe still uses `ALLOWED_HOSTS` or `127.0.0.1`, not `container`.

## Tests

`ClientAddressTest`: 6 tests, 46 assertions. Client-IP and forwarding tests: 7 passed. The Caddy matcher string test: 1 test, 4 assertions. The earlier delete and `TRUST_PROXY` mutations still describe this code.

## Verdict

PASS. `EDGE_STORAGE_READY` stays off. The FrankenPHP Docker job was still running.
