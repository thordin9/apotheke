# Legacy PHP 8.3 and PHP 8.4

Recorded for Feature 12 PR 3. Later parity compares legacy@8.4 with CI4@8.4. This note is the legacy app on the two PHP versions. Legacy@8.3 stays the upgrade and rollback reference.

## Suites

| Suite | PHP 8.3 | PHP 8.4 |
|---|---|---|
| Lint (`php -l`, JS, links, shellcheck, actionlint) | CI job kept | CI job added |
| PHPUnit, SQLite and PostgreSQL | CI cells kept (upgrade/rollback reference) | CI cells since PR 2. This PR adds the parity recorder to those cells |
| Headless mobile checks | CI job kept | CI job added |
| Playwright | CI job kept, 3 shards, Ubuntu PHP 8.3 | CI job added, 3 shards, PHP 8.4 from `ppa:ondrej/php` |
| Docker build + smoke | default image, `PHP_VERSION=8.3` | same Dockerfile with `PHP_VERSION=8.4` (test image) |

## Local PHPUnit

Run on 2026-10-20, SQLite, no PostgreSQL server in the environment:

| PHP | Result |
|---|---|
| 8.4.26 | OK, 288 tests, 1940 assertions, 8 skipped |
| 8.3.6 | OK, 288 tests, 1940 assertions, 8 skipped |

The skips sit at the same points in the run on both versions (optional tools such as zbar, absent here). Assertion counts match. PR 2 already recorded legacy PHPUnit green on PostgreSQL for both versions (281 tests before this PR's recorder and CI tests).

`QrCodeTest::testFunctionJitMatchesInterpreter` is the later `fix(php84)` check. It runs on PHP 8.4 when function JIT can be turned on, and it is skipped on PHP 8.3.

The parity recorder (`vendor/bin/phpunit --filter ParityRecorderTest`) reports zero diffs on 8.3.6 and on 8.4.26. Each run compares two fresh installs on that one PHP: home page, migration rows, settings, file modes, `/healthz`, `/health`, and a General settings save.

## Difference

`fix(php84): keep QR labels working under JIT 1235` leaves the encoder in `src/qr-encode.php` unchanged. `src/qr.php` loads it only after turning PHP 8.4 function JIT off.

PHP 8.4's function JIT miscompiles the finder loops, which count down from a negative index. `opcache.jit=1235` (the default `shivammathur/setup-php` writes, and the setting on the headless mobile job, which runs with `coverage: none`) never returned from `GET /labels`, so the mobile check hit `max_execution_time` of 30 seconds in `src/qr.php`. `opcache.jit=1205` returned a different matrix. Tracing JIT already matched the interpreter. The switch is `opcache.jit=disable` before `qr-encode.php` is compiled, and it sticks for that process. PHP 8.3 is left alone. PHPUnit cells load PCOV, which turns JIT off, so they did not see the hang. The regression test is `QrCodeTest::testFunctionJitMatchesInterpreter`.

Nothing else in the legacy app changed. PHP 8.4 did not change the install, health or settings responses the recorder checks.

PDO on PHP 8.4 no longer treats a backslash as a placeholder escape. SQL in this tree already avoids that form (see [CONTRIBUTING.md](../../../CONTRIBUTING.md)).

## Image

The shipped image stays `php:8.3-fpm-alpine`. `ARG PHP_VERSION=8.3` lets CI pass `PHP_VERSION=8.4` and build a throwaway test image from the same Dockerfile. The CI4 image moves to PHP 8.4 in a later pull request.
