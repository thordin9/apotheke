# Contributing

Pull requests are welcome. The short version is in [CONTRIBUTING.md](../CONTRIBUTING.md); this page explains the
ground rules and walks through adding a feature end to end. AI agents should also read [AGENTS.md](../AGENTS.md).

## Ground rules

* **Stay small.** Plain PHP 8.2+, PDO (SQLite and PostgreSQL), GD. No framework, no Composer packages at runtime
  (PHPUnit is dev-only), no JavaScript build step, no CDNs or other external runtime dependencies.
* **Both databases.** Every query must work on SQLite and PostgreSQL; follow the portability rules in
  [Database](database.md#writing-a-migration).
* **Mobile first.** Tap targets ≥ 44 px, no horizontal overflow at 320 px, controls within thumb reach, light and dark
  theme.
* **CSP-safe.** No inline `<script>`, no `style="…"`, no `on…=` handlers: put styles in `app.css` and behaviour in
  `app.js`.
* **Works without JavaScript** where reasonable: real forms and links first, `app.js` enhances them.
* **Safe by default:** escape output with `h()`, prepared statements, `check_csrf()` on every state change, history
  entries (`activity_log()`) in the same transaction as the change.

## Workflow

```bash
git checkout -b feat/my-change origin/main
# … code, tests, docs …
tests/run.sh && vendor/bin/phpunit && DB_DRIVER=pgsql vendor/bin/phpunit
npx playwright test --grep-invert visual        # functional E2E
scripts/check-commits.sh origin/main
git push -u origin feat/my-change               # open a PR against main
```

* Each commit should pass lint and tests on its own.
* Commit messages follow Conventional Commits, subject ≤ 72 characters (details in [CI](ci.md#commit-rules)).
* If the UI changed, add the `update-snapshots` label to the PR, fetch the artifact with
  `scripts/update-snapshots.sh --from-ci <run id>` and commit the baselines
  ([Testing](testing.md#screenshot-baselines)).
* Fix CI failures with new commits; never force-push a branch others may have fetched.

## Adding a feature, step by step

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
* No new runtime dependencies. If something seems to need one, open an issue first.
