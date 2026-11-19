# PR #332 evidence

Gated head `76e51c6889287e3d9cfac556dd98153c697f0f48`. Live `git ls-remote origin refs/pull/332/head` returned that SHA immediately before the verdict. The claimed `f195f244` was the head at the start of the gate. `76e51c68` is one docs commit on top of it: the changelog and handoff links change from pull/323 to pull/332. No other file differs. `refs/heads/main` was `24da8801eb061f61cb4ab5ac2be301ca19832ab8`. That commit is an ancestor, and `git rev-list --count HEAD..24da8801` was 0.

Own diff versus main is the 10 files of `f195f244` plus those two doc lines. `RequestPath::of` is used by `LegacyPathRouter::legacyPath`, `public/index.php`, `public/router.php` and `public/frankenphp-worker.php`.

## Requests

A `php -S` server through `tests/ci4-router.php`, which loads `public/router.php`. Listed origin set. For each of GET, POST and DELETE on `/api/a:1`, `/api/v1/items:1` and `/api/v1/a%3A1`, status, JSON body and the listed headers matched `GET /api/v1/nothing-here`. Compared headers: content-type, content-security-policy, x-content-type-options, referrer-policy, cross-origin-resource-policy, cache-control, access-control-allow-origin, access-control-allow-credentials, access-control-expose-headers. CSP was `default-src 'none'; frame-ancestors 'none'; sandbox`. Cache-Control was `no-store`. Allow-Origin was the listed origin. Allow-Credentials is `true` for an allowed origin (`Cors::headers`). No set-cookie, x-frame-options or location. Mismatches: 0 with the consent cookie, 0 without it, 0 with sign-in on and no consent cookie, 0 with sign-in on and the consent cookie. The sign-in page itself was HTML 200.

`/originals/a:1`: 404, body `Not found`, `text/plain`. `/` and `//stats`: both 200 and the same body. `//api/v1/items` and `/api//v1/items`: 404 problem+json, code `not_found`.

`parse_url('//stats')` is null, so `RequestPath::of` still returns `/`. `parse_url('//api/v1/items')` is `/v1/items`. The doubled-slash API 404 comes from `RouteMethodFilter` on the raw target, which starts with `/`.

## Tests and the mutation

`Ci4UnparsablePathTest`: OK, 4 tests, 335 assertions. `LegacyPathRouterTest`: OK, 27 tests, 54 assertions, with the scratch PostgreSQL still up.

Replacing the body of `of()` with `rawurldecode((string)(parse_url($requestUri, PHP_URL_PATH) ?: '/'))` failed `testAnUnparsablePathIsThe404` and `testAnUnparsableApiPathIsTheApiJson404` (4 tests, 2 failures) and five router cases, including `/stats:1`, `/:1`, `///stats` and the decode test. The file was restored. `git diff` was empty.

Search for `parse_url(...PHP_URL_PATH) ?: '/'` outside vendor and docs finds only `tests/stubs/autofill-stub.php`.

CHANGELOG preamble equals main. The file from `## [3.52.0]` equals main. README and VERSION match. `VERSION` is `3.52.0`.

Docker is not installed, so the FrankenPHP image was not built. The worker's request function calls `RequestPath::of` before `OriginalsGuard`, the same order as `public/index.php`.

CI on this head, recorded without waiting: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, Headless mobile, FrankenPHP Compose, and both upgrade jobs pass. PHP sqlite, PHP pgsql, three visual shards, both Docker builds, and the nginx Compose job are pending.

Scratch PostgreSQL 16 on port 54350, data dir `/tmp/apotheke-pg-331.*`, was stopped with `pg_ctl -D` that dir `-w stop` and the directory was removed. Probe server logs were removed.

## Merge-only read at fdc37e6f

Live `refs/pull/332/head` is `fdc37e6f0bdb886b786e46c8e2fef87e97d23ebd` and main is `a4b411a70e751e8335917cd885c816f162d73eab` (`ls-remote`). Parents are `76e51c68` and `a4b411a7`. 0 behind main, `76e51c68` is an ancestor, and there are no non-merge commits since `76e51c68` outside main.

Own diff without CHANGELOG (index lines dropped, hunk headers normalised), `24da8801..76e51c68` against `a4b411a7..fdc37e6f`: 285 lines each, identical except the latest-release line in `docs/HANDOFF.md` (v3.52.0 to v3.53.0). No code changed in the merge. Same 10 files.

CHANGELOG: preamble and every released section equal main. Unreleased has only the core #332 bullet. README and VERSION equal main.

CI as it stood (`gh pr checks`): 12 pass, 9 pending.
