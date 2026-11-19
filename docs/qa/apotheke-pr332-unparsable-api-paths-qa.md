# PR #332 unparsable API paths QA

Head `fdc37e6f0bdb886b786e46c8e2fef87e97d23ebd` (live `refs/pull/332/head` at the verdict), a plain merge of main `a4b411a70e751e8335917cd885c816f162d73eab` (v3.53.0) into `76e51c68`, 0 behind. The gate ran at `76e51c68` (`f195f244` plus a commit that only relinks the changelog and handoff from #323 to #332); the merge-only read is in the evidence file.

## Checklist

1. `RequestPath::of()` keeps a path `parse_url()` rejects (`false`): the target up to `?` or `#`, then one `rawurldecode`. `GET`/`POST`/`DELETE` `/api/a:1`, `/api/v1/items:1` and `/api/v1/a%3A1`, with the listed `Origin`, match `/api/v1/nothing-here`: 404 `application/problem+json`, CSP `default-src 'none'; frame-ancestors 'none'; sandbox`, `cache-control: no-store`, `access-control-allow-origin` for that origin, `access-control-allow-credentials: true`. No `Set-Cookie`, no `X-Frame-Options`, no `Location`. The same with the consent cookie omitted, and with sign-in on, with and without consent. None of those answers is a page or a redirect.
2. `/originals/a:1` is `404` with body `Not found` and `text/plain`. The three front controllers, including the FrankenPHP worker, call `RequestPath::of` before the originals guard.
3. `//stats` is still the home page (same body as `/`). `//api/v1/items` and `/api//v1/items` are still the API 404. `RouteMethodFilter` uses the raw target when it starts with `/`, so a doubled slash does not go through the new fallback. `parse_url('//stats')` is null, and null still becomes `/`.
4. The old `parse_url(...) ?: '/'` remains only in the autofill test stub. `LegacyPathRouter::legacyPath` and the three front controllers use `RequestPath::of`. Restoring the old one-liner fails `Ci4UnparsablePathTest` (the HTML 404 and the API 404) and five `LegacyPathRouterTest` cases.
5. CHANGELOG preamble and every released section match main `a4b411a7`. README and VERSION match main. Unreleased holds only the #332 bullet. The own diff without CHANGELOG is the same as at `76e51c68` except the latest-release line in `docs/HANDOFF.md` (v3.53.0).

## Findings

None. Next id 1910.

## Security

An unparsable `/api/...` target used to become `/`, so the API checks were skipped and the home page was served. The path is now kept, those checks see `/api`, and the answer is the API 404 with the API headers and no cookie. A non-API unparsable path is the HTML 404, not the home page. Doubled-slash targets are unchanged.

## Verdict

PASS at `fdc37e6f`. No high or medium finding. Docker is not available here, so the FrankenPHP image was not started; the worker source calls the same helper, and `php -S` uses `public/router.php`.

CI on this head, as it stood at the read: 12 checks pass and 9 are pending. Not waited on.
