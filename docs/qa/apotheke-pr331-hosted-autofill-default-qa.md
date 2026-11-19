# PR #331 hosted autofill default QA

Head `855b13e00bc866d5eec45452989309adc15392cf` (live `refs/pull/331/head` at the verdict). Main `24da8801eb061f61cb4ab5ac2be301ca19832ab8` (v3.52.0) is an ancestor, 0 behind. Own diff: 7 files, +60/−7.

## Checklist

1. An unset `autofill_url` becomes `http://autofill.internal` only when hosted. `APOTHEKE_HOSTED` of `1`, `true`, `on`, `yes` is hosted in any case, including surrounding spaces. `0`, `false`, `off`, `no`, empty, `maybe` and `2` are not. `APOTHEKE_INSTALLATION` alone leaves the URL empty. The parse is `FILTER_VALIDATE_BOOL`, the same flag as #326 and #318.
2. A save of a different URL is refused with "Set by your hosting plan." on SQLite and PostgreSQL. Saving a blank is the same value as the default and is kept. An import of a different URL is ignored. The Control Panel and the settings CLI both go through `save()`.
3. An explicit stored URL is kept. Self-hosted reads and saves are unchanged. `primary` does not get `APOTHEKE_HOSTED`, and this diff does not change that env.
4. The Worker intercepts `autofill.internal` only when `LIMITS_ENABLED` is `"1"` and the installation is not `primary`, then forwards `/v1/info` and `/v1/suggest` through the `AUTOFILL` binding. With limits off the intercept is absent and the container has no internet, so a call to that host fails closed. PHP never reads `LIMITS_ENABLED`.
5. Mutations, files restored: drop the hosted check, or always overwrite the URL, and `testHostedAutofillUrlDefault` fails on SQLite and PostgreSQL. Drop the blank-equals-default rewrite, or remove `autofill_url` from the fixed keys, and `testHostedSettingCeilingsAndFixedSettings` fails.
6. CHANGELOG preamble and every section from `[3.52.0]` match main. README and VERSION match (`3.52.0`). The Unreleased bullet matches the code.

## Findings

None. Next id 1902.

## Security

The default is a read of an empty setting on a hosted install. A request cannot replace it with another address: `save()` and `import()` keep the fixed value. The address is the host the Worker binds only for a non-primary installation with limits on, and the binding adds no token. Self-hosted and `primary` do not get the default.

## Verdict

PASS at `855b13e0`. No high or medium finding.

CI on this head, as it stood: Lint, deptrac, DI, D1, CodeQL, secret scan, conventional commits, Headless mobile, both Compose jobs, and both upgrade jobs pass. PHP sqlite and pgsql, the three visual shards, and both Docker builds are pending.
