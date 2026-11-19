# PR #331 evidence

Gated head `855b13e00bc866d5eec45452989309adc15392cf`. Live `git ls-remote origin refs/pull/331/head` returned that SHA immediately before the verdict. `refs/heads/main` was `24da8801eb061f61cb4ab5ac2be301ca19832ab8`. `git merge-base --is-ancestor 24da8801 HEAD` was true. `git rev-list --count HEAD..24da8801` was 0. Working tree clean after the mutations were restored.

Own diff versus main: `CHANGELOG.md`, `docs/HANDOFF.md`, `docs/autofill.md`, `docs/operator-console.md`, `modules/Settings/SettingsService.php`, `tests/Modules/Core/HostedLimitsTest.php`, `tests/Modules/Settings/SettingsServiceTest.php`. 7 files, +60/−7. Commits: `a22a1dba`, `e54b310c`, merge `855b13e0`.

## Default

`SettingsService::values()` sets `autofill_url` to `http://autofill.internal` only when `HostedLimits::isHosted()` and the stored value is `''`. `isHosted()` is `ApothekeConfig`'s `hosted` flag, set with `FILTER_VALIDATE_BOOL` on `APOTHEKE_HOSTED`. `hostedProblem()` treats a blank save as that same URL, so it matches the fixed current value. `HostedLimits::FIXED_SETTINGS` is `autofill_url` whenever hosted.

A probe on both drivers: the true spellings above, with or without surrounding spaces, read the internal URL and refuse a different save. The false spellings are not hosted and accept a different save. Installation name alone reads an empty URL.

`SettingsService::save()` is what the Control Panel and `SettingsCli::set` call. `import()` ignores a fixed key that does not match. `store()` is not the request path for this field; `resetSection()` writes the schema default, which reads back as the hosted URL.

## Limits and the binding

This diff does not change `edge/src`. `installationEnv()` still gives `APOTHEKE_HOSTED=1` only to a non-primary name, and no `APP_PASSWORD`. `wireStorage()` calls `interceptOutboundHttp` for `autofill.internal` only when `limits` is set. `fetch()` passes `takeLimits()`, which is null unless `LIMITS_ENABLED` is exactly `"1"` and the name is not `primary`. `handleAutofill()` then requires that flag, a non-primary name, an autofill limit prop, and the `AUTOFILL` binding, and forwards only `/v1/info` and `/v1/suggest`. The container starts with `enableInternet: false`.

## Mutations

Each edit was restored before the next. `git diff` was empty at the end.

- Hosted check removed from `values()`: `testHostedAutofillUrlDefault` fails for sqlite and pgsql.
- Empty check removed, so a hosted read always uses the default: the same test fails for both drivers.
- Blank save no longer mapped to the default: `testHostedSettingCeilingsAndFixedSettings` fails. That test creates a SQLite database only.
- `FIXED_SETTINGS` emptied: the same ceilings test fails.

## Docs and suites

CHANGELOG preamble equals main. The file from `## [3.52.0]` equals main (201670 bytes). Main's Unreleased section is empty. README and VERSION match. `VERSION` is `3.52.0`.

`SettingsServiceTest` and `HostedLimitsTest` together: OK, 88 tests, 1157 assertions, both drivers. Scratch PostgreSQL 16, trust on localhost, port 54350, data dir `/tmp/apotheke-pg-331.*`. Stopped with `pg_ctl -D` that dir `-w stop`, then the dir was removed.

CI on this head, recorded without waiting: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, Headless mobile, both Compose jobs, and both upgrade jobs pass. PHP sqlite, PHP pgsql, three visual shards, and both Docker builds are pending.
