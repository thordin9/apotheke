# QA: apotheke #345, ops accounts method, body-size, path and hosted-off tests (#338 follow-up)

- PR: https://github.com/thordin9/apotheke/pull/345 (Coder), base `main`. The #338 follow-up for L-2351, N-2352 and N-2353. Test and docs only; no CHANGELOG bullet (none is required: `test`/`docs` do not cut a release).
- Reviewed head: `695cb8ba7d08598a37ae004c84a4c32723288706` (2 commits, the last of `83928a6e` then `695cb8ba`). 0 behind and 2 ahead of main `2750b4ad` (v3.55.0). `mergeable_state` is clean.
- Gate: a full test gate on a test-and-docs-only diff. Diff read against main, the touched class and the full sqlite suite, 5 mutants independent of Coder's M1–M6, docs against the code, `check-commits`, `check-links` and gitleaks. No `app/`, `modules/` or `edge/` source changed, so this is not a security re-gate of #338.
- CI on `695cb8ba`, all green: CI 44750468627 and CodeQL 44750461532. 21 checks pass, 0 skipped, 0 failed. Deploy edge did not run, because no edge paths changed.
- Reviewed 2026-11-20, 16:26 to 16:41 (UTC+2). Reviewer: Apotheke QA 2

## Checklist

- [x] Scope vs main: 2 files, +58/−4. `tests/Modules/Auth/OpsAccountsHttpTest.php` +52/−2 and `docs/operator-console.md` +6/−2. CHANGELOG.md and VERSION are byte-identical to main. `release.php plan` on this head: `release=false`, bump empty, current 3.55.0.
- [x] L-2351: `testOnlyAPostOfAtMost4KiBToTheExactPathIsAnswered` covers the three cases in one test. A signed GET to `/internal/accounts/claim` is 405, body `method_not_allowed`, `Allow: POST`, and no claim row. A signed 4097-byte JSON body is 401 `unauthorized` and no claim row (`MAX_BODY` is 4096). Signed `…/claim?x=1` and `…/claim/` are unanswered (status 404 or 421, body has no `"ok"`) and leave no claim row.
- [x] N-2352: `testTheServiceAnswersOnlyWhenHosted` builds the endpoint from `Services::opsAccountsEndpoint(false)` after injecting `ApothekeConfig` with `APOTHEKE_HOSTED` `0` and `1`. Off: `applies()` is false and `handle()` returns null. On: a GET is 405. Mutant QA5 (factory always hosted) is killed by the off case.
- [x] N-2353: `docs/operator-console.md` now names 405 `method_not_allowed` with `Allow: POST`, that a body over 4 KiB is 401, that the path must match exactly (a query string or a trailing `/` is not this endpoint), and that those requests, and any request on `ops.internal` when self-hosted, get the host list's 421. That matches `OpsAccountsEndpoint` and `HostFilter`.
- [x] Tests (PHP 8.4.24; composer `^8.4`, CI is 8.4 only): `OpsAccountsHttpTest` 6/6 (135 assertions), including the two new tests. Full root suite, which includes the module suite: sqlite 646 tests, 33 skipped, 1 incomplete, OK (7m 52s). `OpsAccountsHttpTest` is pinned to `TestDatabase::create('sqlite')`, and the factory test injects `DB_DRIVER=sqlite`, so the new tests do not run on pgsql and pgsql was not re-run. CI's pgsql leg on this head is green.
- [x] Mutants, all killed and restored (`cmp` and `git diff --quiet` on `OpsAccountsEndpoint.php` and `Services.php`): QA1 change 405 to 404 (killed by the GET 405 assertions); QA2 drop the `Allow` header (killed by `Allow: POST`); QA3 raise `MAX_BODY` from 4096 to 8192 (killed: the 4097-byte body became 200 instead of 401); QA4 accept a trailing `/` via `rtrim` (killed: `…/claim/` became 200); QA5 factory ignores env and is always hosted (killed: `APOTHEKE_HOSTED=0` still applied). These are not Coder's M1–M6.
- [x] Commits `83928a6e` (69 characters) and `695cb8ba` (67) are Conventional, and `check-commits` is ok. The PR title plus " (#345)" is 76 characters; see L-2354. `check-links`: 402 links, 0 broken. gitleaks 8.30.1 with the repo config: 0 findings in the 2 PR commits.
- [x] Coder's reported docblock nit is not in the tree. The class docblock already says the path is not found "(404, or 421 from the host list)", unchanged from main, and the new tests do not say "the usual 404". Not recorded.

## Findings

- **L-2351 (closed):** the three hardening cases now have tests, as above. Mutants that change the 405 status, drop `Allow`, raise the cap or accept a trailing slash are all killed.
- **N-2352 (closed):** the factory is tested with hosted off and on, as above.
- **N-2353 (closed):** the docs now name the 405 code and `Allow: POST`, the 4 KiB cap giving 401, the exact path, and the 421 on `ops.internal` when self-hosted.
- **L-2354:** the PR title plus " (#345)" is 76 characters, over the 72 limit. Retitle before squash, for example "test(auth): cover ops accounts method, size, path and hosted off" (71 characters with " (#345)").
- L-2350 (replay nonce in the ±300 s window) stays a residual from #338; it is not in this PR's scope.

0 High, 0 Medium, 1 Low (L-2354) and 0 Nits open from this PR; L-2351, N-2352 and N-2353 are closed (next free id: 2355).

## Verdict

**PASS** at `695cb8ba`, 0 behind main `2750b4ad` (v3.55.0). This doc lands byte-exact as the last commit ("docs(qa): add QA 2 review for #345"), followed by the hash check and green CI on that head. The current title is not a valid squash subject (76 characters with " (#345)"). After a retitle, squash as "test(auth): cover ops accounts method, size, path and hosted off" (71 characters with " (#345)").
