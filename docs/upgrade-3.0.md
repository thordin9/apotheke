# Upgrading to 3.0 (CodeIgniter 4)

Apotheke 3.0 is the CodeIgniter 4 app (PHP 8.4). It uses the same database schema, the same data directory and
the same settings as 2.x, so an upgrade is a switch of the app, not a data migration. This page is the upgrade and
rollback runbook (PLAN [§3.6](plans/feature12-ci4/PLAN.md#36-backup-and-rollback-runbook-docker-tested-in-ci)); CI
runs the same steps on every pull request and every push to `main` (see [What CI proves](#what-ci-proves)).

The 2.6 images ship the CI4 app next to the 2.x app and switch to it with `APOTHEKE_APP=ci4`. From 3.0 it is the
only app: the variable may stay empty or `ci4`, and `APOTHEKE_APP=legacy` exits with code 64.

## What changes

* **The app.** Pages, the JSON endpoints and the command-line tools answer as 2.x does: the parity gate compares
  every answer, every table and the data directory after each step on SQLite and PostgreSQL
  ([Testing → Parity gate](testing.md#parity-gate-plan-step-16)). The known differences are the four approved quirks in
  `tests/parity/allowlist.yml` and the deviations listed in the [changelog](../CHANGELOG.md).
* **PHP 8.4.** The image runs PHP 8.4 (`ARG PHP_VERSION` in the `Dockerfile`). With `APOTHEKE_APP=ci4` on an older PHP
  the container exits with code 64.
* **Docker only.** The supported install is the Docker image. Running without Docker works for development (see
  [Getting started → Local development](getting-started.md#local-development)), but is not a supported deployment.
* **One sign-in.** Sessions are not carried over. The first start of the CI4 app removes the session files once and
  writes the marker `sessions/.ci4` in the data directory, so everyone signs in again once. Later starts keep
  sessions.
* **Settings.** CI4 reads the same environment variables and `config.php` as 2.x
  ([Configuration](configuration.md)). CodeIgniter's own dotted overrides (`app.*`, `database.*`, also from
  `.env`) are not read. `CI_ENVIRONMENT` is `production` in the image; leave it so.
* **Unchanged:** the database (no CI4-only migration: both apps run the same `migrations/`), the files under
  `images/`, `thumbs/`, `files/` and `ORIGINALS_DIR` (camera originals, `map/` included), backup zips (a zip from
  either app restores on the other), the port (9091), the health checks (`/healthz`, `/health`) and the start-up
  sign-in check.

## The Control Panel on the CI4 app

The Control Panel is served by the CI4 app too: `/settings` and every section (save and reset), the logo, the
settings file (export and import), Users & sign-in, and the AI autofill page with its token and Test buttons, with the
same permissions as 2.x (`settings` for the panel; `users` for Users & sign-in). Both apps read one database, so a setting changed with either app or with
`bin/settings.php` ([Control Panel → Command line](control-panel.md#command-line)) applies to both.

## 1. Back up

```bash
docker compose exec -u www-data apotheke php /var/www/app/bin/backup.php /data/pre-3.0.zip
```

The zip holds the catalog, the photos and the camera originals. Also keep a copy of the whole `./data` directory
(stop the container first), or on PostgreSQL a `pg_dump -Fc` of the database. If `ORIGINALS_DIR` is outside `/data`,
back it up separately.

Keep `APP_KEY` / `APP_KEY_FILE` unchanged across the switch and both rollbacks, and store a copy apart from the
backup: neither the zip nor `./data` holds it, and without it a stored secret such as the autofill token counts as
unset after a restore. More on backups: [Backup and restore](deployment-and-operations.md#backup-and-restore).

## 2. Switch to the CI4 app

Before 3.0.0, add `APOTHEKE_APP` to the `environment:` block of the `apotheke` service in `docker-compose.yml`
(the shipped file does not pass it through):

```yaml
    environment:
      APOTHEKE_APP: ci4
```

Then:

```bash
docker compose up -d
docker compose logs apotheke | tail -n 5          # "nginx on :9091 (/var/www/app/public)"
curl -fsS http://127.0.0.1:9091/healthz           # 200
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:9091/originals/x   # 404: camera originals are never served
docker compose exec -u www-data apotheke php /var/www/app/bin/migrate.php --status   # nothing pending
```

From 3.0.0 on, pull or build the 3.0 image instead; there is no variable to set.

Everyone signs in again. `bin/migrate.php`, `bin/seed.php`, `bin/backup.php`, `bin/restore.php` and the
`bin/settings.php` forms run the CI4 commands (`php spark apotheke:migrate|seed|backup|restore|settings|accounts`)
with the same output and exit codes as 2.x, apart from the [known differences](#known-differences-from-2x). On a 2.6
image they follow the container's `APOTHEKE_APP`. `bin/settings.php key generate` needs no app; it prints a key and
touches no database.

## 3. Roll back (A): the 2.x app on the same data

Remove `APOTHEKE_APP` again (or set it to `legacy`), or start the 2.x image you ran before the switch (or a newer
2.x), on the same `./data` and database:

```bash
docker compose up -d
docker compose exec -u www-data apotheke php /var/www/app/bin/migrate.php --status   # nothing unknown, nothing pending
```

No data step is needed. From 3.2 the CI4 app has migrations of its own (`0029` adds the `jobs` table for background
jobs); they only add tables, change no table or column 2.x reads, and 2.x ignores them: `--status` lists the 2.x
migrations only, and the `jobs` table stays behind in the database, harmless, until you switch to CI4 again. A
backup made by 3.2 or later does not carry it, so 2.x restores it (step 4 below). Both apps keep sessions in the same files under
`sessions/`, so a sign-in usually survives the switch back.

## 4. Roll back (B): restore the backup

If the data itself must go back to the state before the switch, start the 2.x app and restore the zip from step 1:

```bash
docker compose exec -u www-data apotheke php /var/www/app/bin/restore.php /data/pre-3.0.zip --dry-run
docker compose exec -u www-data apotheke php /var/www/app/bin/restore.php /data/pre-3.0.zip --yes
```

or put back the copy of `./data` (or `pg_restore` the dump) with the container stopped. A restore started as root
switches to the owner of the data directory first ([Backup and restore](deployment-and-operations.md#backup-and-restore)).

## Known differences from 2.x

Besides the settings CLI deviations in the [changelog](../CHANGELOG.md) and the quirks in `tests/parity/allowlist.yml`:

* **SQLite open error.** When the SQLite file cannot be opened (for example its folder does not exist), the pages, the
  health checks and the command-line tools print or log `Could not connect to the database (sqlite – PATH).` and the
  tools exit 1, the same line as for PostgreSQL. 2.x ended in an uncaught `PDOException` with the engine's message
  (`SQLSTATE[HY000] [14] unable to open database file`) and exit code 255.
* **`bin/seed.php` with a short `APP_PASSWORD`.** It prints the `APP_PASSWORD is too short` message and exits 1;
  2.x ended in a fatal error with exit code 255. A valid or empty `APP_PASSWORD` behaves as on 2.x.

Unchanged from 2.x, noted because it can surprise:

* `bin/seed.php --demo` on an existing install adds the eight demo items, with their photos, without asking; items
  with the same name are skipped.
* `bin/settings.php auth status` on a new data folder creates the empty `images/`, `thumbs/`, `originals/` (with its
  `.htaccess`) and `sessions/` folders. It creates no database file. The Docker entrypoint creates these folders
  anyway.

## What CI proves

* **`upgrade-rollback`** (SQLite and PostgreSQL, `scripts/upgrade-rollback.sh`): the 2.x app on PHP 8.3 seeds demo
  data with JPEG, PNG and HEIC originals, an attachment, tags, a saved filter and a stocktake; the CI4 app on PHP 8.4
  runs on the same data directory and database; then the 2.x app again; then each app restores the other's backup
  zip, over the install and into a fresh one. After every step every table and the sha256 of every file under
  `images/`, `thumbs/`, `originals/` (`map/` included) and `files/` must equal the seeded state, originals must be
  0750/0640 and `files/` 0750. The CI4-only additions (migration `0029` and its `jobs` table, with two rows seeded
  after the upgrade) must stay through the rollback and both in-place restores, and the CI4 backup must not name
  them (`tests/upgrade/ci4-only.php`). Mutation checks prove the comparison catches a lost table, a changed or missing
  attachment and a wrong mode.
* **Parity gate** (until the cutover, SQLite and PostgreSQL): one scenario over every ported area on both apps,
  compared step by step; CI4 had to stay within +20 % of 2.x at p95 on PostgreSQL and within + 3 ms at the median on
  SQLite, per area. Its last run on the pre-cutover head passed on both databases with 0 differences ([HANDOFF](HANDOFF.md)).

Details: [Testing → Upgrade and rollback](testing.md#upgrade-and-rollback-plan-step-16-part-2).

Next: [Deployment and operations](deployment-and-operations.md) · [Configuration](configuration.md) ·
[Architecture](architecture.md)
