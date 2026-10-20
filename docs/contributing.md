# Contributing

Pull requests are welcome. The short version is in [CONTRIBUTING.md](../CONTRIBUTING.md); this page explains the
ground rules and walks through adding a feature end to end. AI agents should also read [AGENTS.md](../AGENTS.md).

## Ground rules

* **Stay small.** The legacy app is plain PHP 8.2+, PDO (SQLite and PostgreSQL) and GD, with no JavaScript build step
  and no CDNs. **CodeIgniter 4 replaces the no-framework rule** for the 3.0 port: pinned Composer dependencies, built
  side by side in `ci4/`, on PHP 8.4. See [ADR-001](adr/001-codeigniter4.md) and the
  [Feature 12 plan](plans/feature12-ci4/PLAN.md). Do not add any other framework or CDN.
* **Both databases.** Every query must work on SQLite and PostgreSQL; follow the portability rules in
  [Database](database.md#writing-a-migration).
* **Mobile first.** Tap targets ≥ 44 px, no horizontal overflow at 320 px, controls within thumb reach, light and dark
  theme.
* **CSP-safe.** No inline `<script>`, no `style="…"`, no `on…=` handlers: put styles in `app.css` and behaviour in
  `app.js`.
* **Works without JavaScript** where reasonable: real forms and links first, `app.js` enhances them.
* **Safe by default:** escape output with `h()`, prepared statements, `check_csrf()` on every state change, history
  entries (`activity_log()`) in the same transaction as the change.
* **No schema change on the port.** The 25 migrations stay byte-identical and run through a PDO runner plus a
  `LegacyFunctions` shim. Docker is the only install after the 3.0.0 cutover. Users sign in again once at upgrade.
* **Lines of development.** Legacy `main` stays as it is until the 3.0.0 cutover. After that, 2.5.x gets security
  fixes only, on `release/2.5`, for 90 days. CI4 pull requests target `feature/ci4`.
* **CI4 module rules** (code under `ci4/`): deptrac enforces the module dependency graph (every module may depend on
  Core, Core depends on nothing, no cycles). Use constructor injection. `service()` and `Services::` are allowed only
  in controllers and in `Config/Services.php` factories. The allowed edges are in
  [AGENTS.md](../AGENTS.md#ci4-module-rules).

## Workflow

```bash
git checkout -b feat/my-change origin/main
# … code, tests, docs …
tests/run.sh && vendor/bin/phpunit && DB_DRIVER=pgsql vendor/bin/phpunit
npx playwright test --grep-invert visual        # functional E2E
scripts/check-commits.sh origin/main
git push -u origin feat/my-change               # open a PR against main
```

* Legacy changes open a pull request against `main`, as in the commands above. CI4 work branches from `feature/ci4` and opens the pull request against that branch.
* **CI on `feature/ci4`.** Pushes to that branch, and every pull request into it, run [`.github/workflows/ci.yml`](../.github/workflows/ci.yml). The PHP 8.4 matrix is `legacy` and `ci4`, each on SQLite and PostgreSQL. legacy@8.3 stays the upgrade/rollback reference. The ci4 leg skips PHPUnit until `ci4/public/index.php` exists. deptrac and the DI rule run with empty configs until modules exist. Nothing is released from this branch. Details: [CI](ci.md#php-84-legs).
* Each commit should pass lint and tests on its own.
* Commit messages follow Conventional Commits, subject ≤ 72 characters (details in [CI](ci.md#commit-rules)).
* If the UI changed, add the `update-snapshots` label to the PR, fetch the artifact with
  `scripts/update-snapshots.sh --from-ci <run id>` and commit the baselines
  ([Testing](testing.md#screenshot-baselines)).
* Fix CI failures with new commits; never force-push a branch others may have fetched.

## Adding a feature, step by step

These steps are a legacy change on `main`. A CI4 change goes in `ci4/` and targets `feature/ci4`.

Using a new item field as the example:

1. **Migration**: `migrations/NNNN_name.php` adding the column ([how](database.md#writing-a-migration)).
2. **Domain logic** in `src/items.php`: add the column to `ITEM_COLS` (and normalise it in `item_get()`), write it
   in `item_save()` and add it to the list of compared fields there (its history label goes into `ACTIVITY_FIELDS` in
   `src/views.php`), and include it in `item_input()` if
   bulk actions and duplicating should keep it.
3. **Routes** in `public/index.php`: parse and validate input (throw `UserError` with status 422 for bad input), call
   the domain function, redirect after POST. Add the column to the CSV export if it is item data.
4. **Views** in `src/views.php`: form field, display on the item page and/or card, filter sheet entry if it is
   filterable, and the history text in `activity_html()`.
5. **Front end**: styles in `public/assets/app.css` (use the CSS variables so dark mode works), behaviour in
   `public/assets/app.js`.
6. **Tests**:
   * unit tests in `tests/Unit/` (extend `DatabaseTestCase` for database code);
   * HTTP tests in `tests/integration.php` (they run on SQLite and PostgreSQL, standalone and inside PHPUnit);
   * a Playwright spec in `tests/visual/` that also calls `app.noOverflow()` and `app.tapTargets()`, and a screenshot
     in `visual.spec.ts` for new screens.
7. **Docs**: the README feature list, the relevant pages here (usually the [user guide](user-guide.md)), and the
   README screenshots in `docs/screenshots/` if a new screen deserves one.

## Project conventions

* Functions over classes; one file per area (`items.php`, `backup.php`, …).
* Names: `item_*` for item operations, `view_*` for full pages, `*_html` for partials returning strings.
* User-facing errors are `UserError`; anything else is a bug and ends up in the log.
* Dates: store UTC `Y-m-d H:i:s` for timestamps (`now_utc()`), local `Y-m-d` for calendar dates; use `app_time()` /
  `app_now()` instead of `time()` so tests can freeze the clock.
* No new runtime dependencies on the legacy app. CI4 dependencies are the pinned CodeIgniter 4 set; anything else needs an issue first.
