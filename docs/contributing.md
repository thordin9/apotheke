# Contributing

Pull requests are welcome. The short version is in [CONTRIBUTING.md](../CONTRIBUTING.md); this page explains the
ground rules and walks through adding a feature end to end. AI agents should also read [AGENTS.md](../AGENTS.md).

## Ground rules

* **Stay small.** Apotheke 3.0 is CodeIgniter 4 on PHP 8.4 with PDO (SQLite and PostgreSQL) and GD, pinned
  Composer dependencies, no JavaScript build step and no CDNs. See [ADR-001](adr/001-codeigniter4.md) and the
  [Feature 12 plan](plans/feature12-ci4/PLAN.md). Do not add any other framework or CDN.
* **Both databases.** Every query must work on SQLite and PostgreSQL; follow the portability rules in
  [Database](database.md#writing-a-migration).
* **Mobile first.** Tap targets ≥ 44 px, no horizontal overflow at 320 px, controls within thumb reach, light and dark
  theme.
* **CSP-safe.** No inline `<script>`, no `style="…"`, no `on…=` handlers: put styles in `app.css` and behaviour in
  `app.js`.
* **Works without JavaScript** where reasonable: real forms and links first, `app.js` enhances them.
* **Safe by default:** escape every value in views, prepared statements, the CSRF filter on every state change, history
  entries (`Activity\ActivityLog`) in the same transaction as the change.
* **No schema change on the port.** The 28 migrations stay byte-identical with 2.x and run through a PDO runner plus a
  `LegacyFunctions` shim. Docker is the only install from 3.0.0. Users sign in again once at upgrade.
* **Lines of development.** `main` is the CodeIgniter 4 app and every pull request targets it. For 90 days after 3.0.0,
  the 2.x line gets security fixes only, as 2.6.x releases from the branch `old` (the former `main`, last release
  v2.6.26); `old` takes no other work.
* **Module rules** (code under `modules/`): deptrac enforces the module dependency graph (every module may depend on
  Core, Core depends on nothing, no cycles). Use constructor injection. `service()` and `Services::` are allowed only
  in controllers, in filter `before()`/`after()`, and in `Config/Services.php` factories. The allowed edges are in
  [AGENTS.md](../AGENTS.md#ci4-module-rules).

## Workflow

```bash
git checkout -b feat/my-change origin/main
# … code, tests, docs …
tests/run.sh && vendor/bin/phpunit && DB_DRIVER=pgsql APOTHEKE_TEST_DB=pgsql vendor/bin/phpunit
npx playwright test --grep-invert visual        # functional E2E
scripts/check-commits.sh origin/main
git push -u origin feat/my-change               # open a PR against main
```

* **CI.** Every pull request and every push to `main` run [`.github/workflows/ci.yml`](../.github/workflows/ci.yml). The tests run on PHP 8.4, on SQLite and PostgreSQL. Lint, headless mobile checks, Playwright and Docker build + smoke run on PHP 8.4 (the shipped image), and the upgrade-and-rollback job checks 2.6.26 → CI4@8.4 → 2.6.26 on one data directory. deptrac and the DI rule check every module under `modules/`. A green CI run for a push to `main` starts the release ([Releasing](releasing.md)). Details: [CI](ci.md#php-84-legs).
* Each commit should pass lint and tests on its own.
* Commit messages follow Conventional Commits, subject ≤ 72 characters (details in [CI](ci.md#commit-rules)).
* If the UI changed, add the `update-snapshots` label to the PR, fetch the artifact with
  `scripts/update-snapshots.sh --from-ci <run id>` and commit the baselines
  ([Testing](testing.md#screenshot-baselines)).
* Fix CI failures with new commits; never force-push a branch others may have fetched.

## Adding a feature, step by step

These steps are for the CodeIgniter 4 app (`modules/`, `app/Config/`), on a branch from `main`.

Using a new item field as the example:

1. **Migration**: `migrations/NNNN_name.php` adding the column ([how](database.md#writing-a-migration)).
2. **Domain logic** in `modules/Catalog/Items/ItemRepository.php`: add the column to `COLS` (and normalise it in
   `get()`), write it in `save()` and add it to the compared fields there (its history label goes into `FIELDS` in
   `modules/Activity/Views/ActivityView.php`); parse it in `ItemInput`, and keep it in bulk actions and duplicating
   (`modules/BulkMerge`) if they should copy it.
3. **Routes and controllers**: the route in `app/Config/Routes.php` (with its permission) and the controller in
   `modules/Catalog/Controllers/`: validate input (throw `UserError` with status 422 for bad input), call the
   repository, redirect after POST. Add the column to the CSV export (`modules/ImportExport/CsvExport.php`) if it is
   item data.
4. **Views** in `modules/Catalog/Views/` (`ItemView`, `ListView`): form field, display on the item page and/or card,
   filter sheet entry if it is filterable, and the history text in `ActivityView`.
5. **Front end**: styles in `public/assets/app.css` (use the CSS variables so dark mode works), behaviour in
   `public/assets/app.js`.
6. **Tests**:
   * unit tests in `tests/Unit/` (extend `DatabaseTestCase` for database code);
   * module tests in `tests/Modules/<Module>/` (CIUnitTestCase; they run on SQLite and PostgreSQL) and HTTP tests in
     `tests/Unit/` through `tests/lib.php`;
   * a Playwright spec in `tests/visual/` that also calls `app.noOverflow()` and `app.tapTargets()`, and a screenshot
     in `visual.spec.ts` for new screens.
7. **Docs**: the relevant pages here (usually the [user guide](user-guide.md)), and a screenshot in
   `docs/screenshots/` if a new screen deserves one.

## Project conventions

* Functions over classes; one file per area (`items.php`, `backup.php`, …).
* Names: `item_*` for item operations, `view_*` for full pages, `*_html` for partials returning strings.
* User-facing errors are `UserError`; anything else is a bug and ends up in the log.
* Dates: store UTC `Y-m-d H:i:s` for timestamps (`now_utc()`), local `Y-m-d` for calendar dates; use `app_time()` /
  `app_now()` instead of `time()` so tests can freeze the clock.
* No new runtime dependencies: the runtime Composer packages are the pinned CodeIgniter 4 set; anything else needs an issue first.
