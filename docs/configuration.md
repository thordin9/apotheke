# Configuration

Apotheke reads its settings once per request (`cfg()` in `src/config.php`) from three layers; later layers win:

1. built-in defaults (`config_defaults()`),
2. a PHP config file: `config.php` in the project root, or the file named by `APOTHEKE_CONFIG`,
3. environment variables.

**Empty environment variables are ignored.** That is deliberate: a compose file can say
`APP_PASSWORD: ${APP_PASSWORD:-}` without ever wiping a value set in `config.php`.

## Bootstrap settings vs. the Control Panel

Since the Control Panel (see [control-panel.md](control-panel.md)) there are two kinds of settings:

* **Bootstrap** — needed before the database can be opened: `data_dir`, everything under `db.*`, `seed_defaults` and the
  config-file path. They are only read from `config.php` / the environment and are shown read-only under
  **Settings → System**.
* **Everything else** (marked *DB* in the table below) — stored in the database. `config.php` / environment values are
  only the **initial defaults**: the `0013_settings` migration copies them into the `settings` table on a new install or
  an upgrade, and *Reset to defaults* in the panel goes back to them. After that, change them in the panel or with
  `php bin/settings.php set KEY VALUE`; editing the environment no longer changes an existing database.

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

| `config.php` key | Environment variable | Default | Where | Meaning |
|---|---|---|---|---|
| `data_dir` | `DATA_DIR` | `<project>/data` (`/data` in the Docker image) | bootstrap | Served photos (`images/`), thumbnails (`thumbs/`), sessions (`sessions/`), temporary files (`tmp/`) and, by default, the SQLite file and the camera uploads (`originals/`). Created if missing. |
| `originals_dir` | `ORIGINALS_DIR` | *(empty → `<data_dir>/originals`)* | bootstrap | Byte-identical camera uploads. Empty, or a relative path, stays outside `public/`. An absolute path is used as given. Not served over HTTP. |
| `timezone` | `TZ` | `UTC` (`Europe/Athens` in `docker-compose.yml`) | DB — Currency, dates & time → Time zone | Time zone for displayed times, the statistics buckets and "today" (lending due dates). |
| `app_password` | `APP_PASSWORD` | *(empty)* | DB — Users | Password of the first account `admin` (stored as a hash when the database is created; sign-in on). Like every password it needs at least 8 characters, not only spaces: a shorter value stops the first start (Docker exit 78) or `bin/migrate.php` with a message that doesn't show it (hardening, #75 QA L-2). Empty means no login; the Docker image then refuses to start unless sign-in is already on in the database or `ALLOW_NO_LOGIN=1` is set (hardening, SEC-M3). Later: Settings → Users & sign-in or `php bin/settings.php user password admin`. |
| `db.driver` | `DB_DRIVER` | `sqlite` | bootstrap | `sqlite` or `pgsql`; anything else is an error. |
| `db.sqlite_path` | `SQLITE_PATH` | `<data_dir>/catalog.sqlite` | bootstrap | SQLite database file. |
| `db.dsn` | `DB_DSN` | *(empty)* | bootstrap | Full PDO DSN for PostgreSQL; overrides host, port and name. |
| `db.host` | `DB_HOST` | `127.0.0.1` | bootstrap | PostgreSQL host. |
| `db.port` | `DB_PORT` | `5432` | bootstrap | PostgreSQL port. |
| `db.name` | `DB_NAME` | `apotheke` | bootstrap | PostgreSQL database. |
| `db.user` | `DB_USER` | `apotheke` | bootstrap | PostgreSQL user. |
| `db.password` | `DB_PASSWORD` | *(empty)* | bootstrap | PostgreSQL password. |
| `db.schema` | `DB_SCHEMA` | *(empty)* | bootstrap | Optional PostgreSQL schema (`search_path`). Created only when missing. |
| `uploads.max_mb` | `MAX_UPLOAD_MB` | `20` | DB — Photos & uploads | Server-side upload limit per photo in MB. Also raise PHP's `upload_max_filesize` / `post_max_size` (and the web server's body limit) if you increase it. |
| `uploads.full_max` | `IMAGE_FULL_MAX` | `1600` | DB — Photos & uploads | Longest side in px of stored photos. |
| `uploads.thumb_max` | `IMAGE_THUMB_MAX` | `480` | DB — Photos & uploads | Longest side in px of thumbnails. |
| `page_size` | `PAGE_SIZE` | `60` | DB — List → Items per page | Items per page in the list (*Show more* loads the next page). |
| `currency` | `CURRENCY` | `EUR` | DB — Currency, dates & time | Currency of purchase prices and value totals (ISO code or symbol, max. 8 characters). Only a label: no conversion. |
| `expiry_soon_days` | `EXPIRY_SOON_DAYS` | `30` | DB — Thresholds & limits | Window for *Expiring soon*: an item whose expiry/warranty date is between today and today + N days. Clamped to 1–3650. |
| `public_url` | `PUBLIC_URL` | *(from the request)* | DB — General → Public URL | Base URL encoded in QR labels, e.g. `http://192.168.1.10:9091`. Without it the scheme and `Host` of the current request are used (only a `Host` that passes the Host check). Its host name is always accepted by the Host check. With `https://`, the session cookie is `Secure` on requests for that host. |
| `allowed_hosts` | `ALLOWED_HOSTS` | *(empty)* | bootstrap | Extra host names the app answers to, comma-separated (`apotheke.lan,nas.lan`; ports ignored; `*` = any). IP addresses, `localhost`, the machine's host name and `PUBLIC_URL`'s host are always accepted; other names get 421, including on the health checks `/healthz` and `/health`, so probes must use an allowed host. See [Host names](deployment-and-operations.md#host-names). |
| `seed_defaults` | `SEED_DEFAULTS` | `true` | bootstrap | On a brand-new database, create starter locations (Box 1 ★, Living room, Kitchen, Garage, Basement, Attic) and categories (General ★, Tools, Electronics, Kitchen, Documents, Hobby, Clothing, Shoes, Books, Lighting). Only ever on a new database: upgrades never add categories. |
| `trusted_proxies` | `TRUSTED_PROXIES` | *(none)* | bootstrap | Addresses or CIDR blocks of your own reverse proxies, comma-separated in the environment (`127.0.0.1, 192.0.2.0/24`). `X-Forwarded-For` is read only when `trust_proxy` is on and the connecting peer is listed in `trusted_proxies` / `TRUSTED_PROXIES`. The client is the rightmost hop that isn't itself a listed proxy. With `trust_proxy` on and an empty list, the connecting address is used. |

The Control Panel adds more DB settings with built-in defaults only (accent, logo, theme, number/date/time formats,
list sort/view, new-item defaults, loan period, saved-filter and photo limits, JPEG quality); see
[control-panel.md](control-panel.md#sections).

**AI autofill image bytes** (`autofill_max_image_bytes`) is one of those. It lives only in the `settings` table
(Control Panel → AI autofill → Maximum image size). Default `auto`. There is no `config.php` key and no environment
variable. `auto` uses `max_image_bytes` from the service’s `GET /v1/info`, or 983040 bytes when `/v1/info` has not
been seen. An empty value is saved as `auto`. An explicit value is a whole number of bytes from 65536 to 10485760. It is capped to
`enforced_image_bytes` when `/v1/info` includes it, otherwise to `max_image_bytes` when that field is present,
and used as stored only when neither is known. A lower cap logs a warning once until that pair of sizes changes, and never the token. The database
connection itself stays the DSN in `db.dsn` / `DB_DSN` (or host, port and name). `AUTOFILL_UPLOAD_MAX_SIDE` and
`AUTOFILL_UPLOAD_JPEG_QUALITY` still choose the starting long edge and the first JPEG quality. They do not replace
this byte cap.

Values are normalised after loading: `data_dir` and `public_url` lose a trailing `/`, the numeric upload settings and
`page_size` are at least `1`, `seed_defaults` accepts the usual boolean spellings (`true/false`, `1/0`, `yes/no`,
`on/off`).

## Other environment variables

| Variable | Used by | Meaning |
|---|---|---|
| `AUTOFILL_UPLOAD_MAX_SIDE` | autofill | Longest side of the JPEG sent to the autofill service. Default `1600`, clamped to 64–4096. Never enlarges a smaller photo. The model’s image size, when the service lists one, is still the cap when it is smaller. |
| `AUTOFILL_UPLOAD_JPEG_QUALITY` | autofill | JPEG quality of that copy. Default `80`, clamped to 40–95. If the JPEG is still over the byte cap, quality steps down by 10 (60 is always tried), then the long edge shortens, until the JPEG fits. The cap applies to that JPEG, which is what the service limits; the multipart wrapper is not counted. This does not set the byte cap (`autofill_max_image_bytes` in the Control Panel). |
| `APOTHEKE_CONFIG` | app, CLI | Path of the config file instead of `<project>/config.php`. |
| `PORT` | Docker image | Port nginx listens on inside the container (default `9091`). Must be a number. |
| `ALLOW_NO_LOGIN` | Docker image | `1`, `true` or `yes` lets the container start with sign-in off (no `APP_PASSWORD`, no sign-in in the database). Use it only on a trusted network. Empty, `0`, `false` or `no` (the default) keeps the start-up check (hardening, SEC-M3): `bin/settings.php auth status` reads the database without migrating or seeding it (it may create an empty `DB_SCHEMA` or switch an older SQLite file to WAL, as the app does), and the container exits with code 78 when sign-in is off. Any other value exits with code 64. |
| `APOTHEKE_CLOCK` | app (tests) | Freezes the app's clock (`YYYY-MM-DD HH:MM:SS`, UTC). Used by the Playwright suite for deterministic screenshots; don't set it in production. |
| `TEST_PG_HOST`, `TEST_PG_PORT`, `TEST_PG_DB`, `TEST_PG_USER`, `TEST_PG_PASSWORD` | tests | PostgreSQL used by PHPUnit, `tests/run.sh` and the Playwright `pgsql-smoke` project. Defaults `127.0.0.1`, `5432`, `apotheke`, `apotheke`, `apotheke`. |
| `DB_DRIVER` | tests | `pgsql` runs PHPUnit / `tests/run.sh` against PostgreSQL. |
| `KEEP_TEST_DATA` | `tests/integration.php` | Keep the temporary data directories after the run. |
| `E2E_DOCKER_IMAGE` | Playwright | Adds the `docker-smoke` project, which runs the `@smoke` tests against that image instead of `php -S`. |
| `CI` | Playwright | CI mode: retries, the GitHub reporter, `forbidOnly`. |

## PHP and web server limits

A picked photo is uploaded unchanged when it fits **Maximum upload** (`uploads.max_mb`, default 20). Above that, the
browser saves a smaller JPEG and says the original file is not kept. The shipped limits already cover a typical
10–20 MB camera photo: `docker/php.ini` sets `upload_max_filesize=20M` and `post_max_size=22M`, and `docker/nginx.conf`
sets `client_max_body_size 22m`. Raise all three together with `uploads.max_mb` (the Control Panel allows up to 200);
`post_max_size` and `client_max_body_size` need to sit a little above `upload_max_filesize`. What the catalog shows
is still a stripped JPEG (longest side at most 1600 px). Backups uploaded
through the web restore can be much larger: the Docker image allows up to 520 MB on `/backup/restore` only (`client_max_body_size` and a `PHP_VALUE` override
in `docker/nginx.conf`; every other route keeps the 22 MB body limit, so a big zip gets HTTP 413). Otherwise use `bin/restore.php`.

`docker/php.ini` enables opcache and leaves JIT off. Installs that turn function JIT on are covered by `src/qr.php`:
the first request sets `opcache.jit=disable` for the whole worker. Tracing JIT stays on. The cost is negligible, and
`opcache_jit_blacklist()` does not help. On 2.5.x, function JIT on PHP 8.2, 8.3 and 8.4 could write a wrong QR code
on installs outside Docker that enabled it.

## Examples

SQLite with a password (`.env` for Docker Compose; the password, zone and currency are copied into the database on the first start and edited in the Control Panel afterwards):

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

Apotheke looks the schema up in `pg_namespace` and runs `CREATE SCHEMA` only when that name is missing.
PostgreSQL checks CREATE on the database before it checks whether the schema exists, so a role without CREATE
used to fail at startup even when the schema was already there. A role without CREATE on the database can use
an existing schema. Create the schema first and grant that role USAGE on it, plus CREATE on the schema when
migrations need to add tables. If the schema is missing and the role cannot create it, startup fails. The log
line (`[apotheke] …`) and the CLI error name the schema. They do not include the password or the DSN.
`bin/migrate.php` prints only the driver, host, port, dbname, and `DB_SCHEMA`. It never prints the user or
the password. SQLite prints the driver and the file path. A DSN that does not parse is shown as
`<unparsed dsn>`. When it can't connect to the database, the command prints `Could not connect to the database`
with those same fields and exits with status 1. It does not print or log the server's own error text (that
text names the database user), the password, or the full DSN, including when PHP includes exception arguments
in stack traces. `bin/seed.php`, `bin/backup.php`, `bin/restore.php` and `bin/settings.php` exit 1 with that
same line, and `/healthz` logs it. While the database is down, every route, including CSS, the manifest and `/login`, returns 500 and the log has only that sanitized line. A SQLite file that cannot be opened still
reports the engine's own error. The browser shows the generic error page.

QR labels that should point at a fixed address:

```
PUBLIC_URL=http://apotheke.lan:9091
```
