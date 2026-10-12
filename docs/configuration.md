# Configuration

Apotheke reads its settings once per request (`cfg()` in `src/config.php`) from three layers; later layers win:

1. built-in defaults (`config_defaults()`),
2. a PHP config file: `config.php` in the project root, or the file named by `APOTHEKE_CONFIG`,
3. environment variables.

**Empty environment variables are ignored.** That is deliberate: a compose file can say
`APP_PASSWORD: ${APP_PASSWORD:-}` without ever wiping a value set in `config.php`.

## `config.php`

Copy [`config.example.php`](../config.example.php) to `config.php` (it is git-ignored) and change what you need. The
file must `return` an array; it is merged recursively over the defaults, so you can leave out anything you don't change:

```php
<?php
return [
    'timezone'     => 'Europe/Athens',
    'app_password' => 'change-me',
    'db'           => ['driver' => 'pgsql', 'host' => 'db.lan', 'password' => 'secret'],
    'currency'     => 'CHF',
];
```

## Every setting

| `config.php` key | Environment variable | Default | Meaning |
|---|---|---|---|
| `data_dir` | `DATA_DIR` | `<project>/data` (`/data` in the Docker image) | Photos (`images/`), thumbnails (`thumbs/`), sessions (`sessions/`), temporary files (`tmp/`) and, by default, the SQLite file. Created if missing. |
| `timezone` | `TZ` | `UTC` (`Europe/Athens` in `docker-compose.yml`) | Time zone for displayed times, the statistics buckets and "today" (lending due dates). |
| `app_password` | `APP_PASSWORD` | *(empty)* | If set, a single shared password is required. Empty means no login. |
| `db.driver` | `DB_DRIVER` | `sqlite` | `sqlite` or `pgsql`; anything else is an error. |
| `db.sqlite_path` | `SQLITE_PATH` | `<data_dir>/catalog.sqlite` | SQLite database file. |
| `db.dsn` | `DB_DSN` | *(empty)* | Full PDO DSN for PostgreSQL; overrides host, port and name. |
| `db.host` | `DB_HOST` | `127.0.0.1` | PostgreSQL host. |
| `db.port` | `DB_PORT` | `5432` | PostgreSQL port. |
| `db.name` | `DB_NAME` | `apotheke` | PostgreSQL database. |
| `db.user` | `DB_USER` | `apotheke` | PostgreSQL user. |
| `db.password` | `DB_PASSWORD` | *(empty)* | PostgreSQL password. |
| `db.schema` | `DB_SCHEMA` | *(empty)* | Optional PostgreSQL schema (`search_path`); created if missing. |
| `uploads.max_mb` | `MAX_UPLOAD_MB` | `20` | Server-side upload limit per photo in MB. Also raise PHP's `upload_max_filesize` / `post_max_size` (and the web server's body limit) if you increase it. |
| `uploads.full_max` | `IMAGE_FULL_MAX` | `1600` | Longest side in px of stored photos. |
| `uploads.thumb_max` | `IMAGE_THUMB_MAX` | `480` | Longest side in px of thumbnails. |
| `page_size` | `PAGE_SIZE` | `60` | Items per page in the list (*Show more* loads the next page). |
| `currency` | `CURRENCY` | `EUR` | Shown next to item values (ISO code or symbol, max. 8 characters). Only a label. |
| `expiry_soon_days` | `EXPIRY_SOON_DAYS` | `30` | Window for *Expiring soon*: an item whose expiry/warranty date is between today and today + N days. Clamped to 1–3650. |
| `public_url` | `PUBLIC_URL` | *(from the request)* | Base URL encoded in QR labels, e.g. `http://192.168.1.10:8080`. Without it the scheme and `Host` of the current request are used. |
| `seed_defaults` | `SEED_DEFAULTS` | `true` | On a brand-new database, create starter locations (Box 1 ★, Living room, Kitchen, Garage, Basement, Attic) and categories (General ★, Tools, Electronics, Kitchen, Documents, Hobby). |

Values are normalised after loading: `data_dir` and `public_url` lose a trailing `/`, the numeric upload settings and
`page_size` are at least `1`, `seed_defaults` accepts the usual boolean spellings (`true/false`, `1/0`, `yes/no`,
`on/off`).

## Other environment variables

| Variable | Used by | Meaning |
|---|---|---|
| `APOTHEKE_CONFIG` | app, CLI | Path of the config file instead of `<project>/config.php`. |
| `PORT` | Docker image | Port nginx listens on inside the container (default `80`). Must be a number. |
| `APOTHEKE_CLOCK` | app (tests) | Freezes the app's clock (`YYYY-MM-DD HH:MM:SS`, UTC). Used by the Playwright suite for deterministic screenshots; don't set it in production. |
| `TEST_PG_HOST`, `TEST_PG_PORT`, `TEST_PG_DB`, `TEST_PG_USER`, `TEST_PG_PASSWORD` | tests | PostgreSQL used by PHPUnit, `tests/run.sh` and the Playwright `pgsql-smoke` project. Defaults `127.0.0.1`, `5432`, `apotheke`, `apotheke`, `apotheke`. |
| `DB_DRIVER` | tests | `pgsql` runs PHPUnit / `tests/run.sh` against PostgreSQL. |
| `KEEP_TEST_DATA` | `tests/integration.php` | Keep the temporary data directories after the run. |
| `E2E_DOCKER_IMAGE` | Playwright | Adds the `docker-smoke` project, which runs the `@smoke` tests against that image instead of `php -S`. |
| `CI` | Playwright | CI mode: retries, the GitHub reporter, `forbidOnly`. |

## PHP and web server limits

Photos are shrunk on the phone before upload (max. 1600 px, JPEG ~80 %), so the defaults are plenty. Backups uploaded
through the web restore can be much larger: the Docker image allows up to 512 MB on `/backup/restore` only (`client_max_body_size` and a `PHP_VALUE` override
in `docker/nginx.conf`; every other route keeps the 22 MB body limit); otherwise use `bin/restore.php`.

## Examples

SQLite with a password (`.env` for Docker Compose):

```
APP_PASSWORD=change-me
TZ=Europe/Berlin
CURRENCY=€
```

An existing PostgreSQL server in a dedicated schema:

```
DB_DRIVER=pgsql
DB_HOST=192.168.1.5
DB_NAME=homelab
DB_USER=apotheke
DB_PASSWORD=secret
DB_SCHEMA=apotheke
```

QR labels that should point at a fixed address:

```
PUBLIC_URL=http://apotheke.lan:8080
```
