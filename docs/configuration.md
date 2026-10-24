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
| `app_password` | `APP_PASSWORD` | *(empty)* | DB — Users | Password of the first account `admin` (stored as a hash when the database is created; sign-in on). Like every password it needs at least 8 characters, not only spaces: a shorter value stops the first start (Docker exit 78) or `bin/migrate.php` with a message that doesn't show it. Empty means no login; the Docker image then refuses to start unless sign-in is already on in the database or `ALLOW_NO_LOGIN=1` is set. Later: Settings → Users & sign-in or `php bin/settings.php user password admin`. |
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
list sort/view, new-item defaults, loan period, saved-filter and photo limits, JPEG quality, and the sign-in limits
`session_days` and `session_idle_hours`); see [control-panel.md](control-panel.md#sections). `session_days` (default
30, at most 365) is how long a sign-in lasts, counted from sign-in. The server ends it when that time has passed;
later visits do not extend it. A session from before this check gets its sign-in time on the next request and is
not ended immediately. `session_idle_hours` (default 168, at least 1, at most 8760) ends that sign-in after that
many hours with no request. The idle clock moves at most once a minute, and lowering the limit applies on the next
request. The favicon, the manifest, the theme and label styles, and `/healthz` and `/health` do not refresh it.
There is no `config.php` key for either.

The AI autofill byte cap (`autofill_max_image_bytes`, default `auto`) is one of those; see
[Maximum image size](autofill.md#maximum-image-size).

Values are normalised after loading: `data_dir` and `public_url` lose a trailing `/`, the numeric upload settings and
`page_size` are at least `1`, `seed_defaults` accepts the usual boolean spellings (`true/false`, `1/0`, `yes/no`,
`on/off`).

## Other environment variables

| Variable | Used by | Meaning |
|---|---|---|
| `AUTOFILL_UPLOAD_MAX_SIDE` | autofill | Longest side of the JPEG sent to the autofill service. Default `1600`, clamped to 64–4096. Never enlarges a smaller photo. The model’s image size, when the service lists one, is still the cap when it is smaller. |
| `AUTOFILL_UPLOAD_JPEG_QUALITY` | autofill | JPEG quality of that copy. Default `80`, clamped to 40–95. If the JPEG is still over the byte cap, quality steps down by 10 (60 is always tried), then the long edge shortens, until the JPEG fits. The cap applies to that JPEG, which is what the service limits; the multipart wrapper is not counted. This does not set the byte cap (`autofill_max_image_bytes` in the Control Panel). |
| `APOTHEKE_CONFIG` | app, CLI | Path of the config file instead of `<project>/config.php`. |
| `APP_KEY` | app, CLI | Base64 of 32 bytes. Encrypts stored secrets. Recommended, including for Docker. Empty leaves them unencrypted. A value that is set but unusable refuses secret saves. See [Stored secrets](#stored-secrets). |
| `APP_KEY_FILE` | app, CLI | Optional file with the same key text, outside the data directory and outside backup paths, readable by the app user. Ignored when `APP_KEY` is set. |
| `APP_KEY_NEW` | `bin/settings.php key rotate` | Replacement key for one rotation. Never put it in the command arguments. |
| `PORT` | Docker image | Port nginx listens on inside the container (default `9091`). Must be a number. |
| `ALLOW_NO_LOGIN` | Docker image | `1`, `true` or `yes` lets the container start with sign-in off (no `APP_PASSWORD`, no sign-in in the database). Use it only on a trusted network. Empty, `0`, `false` or `no` (the default) keeps the start-up check, which exits with code 78 when sign-in is off ([Docker defaults](deployment-and-operations.md#docker-defaults-localhost-and-sign-in)). Any other value exits with code 64. |
| `APOTHEKE_CLOCK` | app (tests) | Freezes the app's clock (`YYYY-MM-DD HH:MM:SS`, UTC). Used by the Playwright suite for deterministic screenshots; don't set it in production. |
| `TEST_PG_HOST`, `TEST_PG_PORT`, `TEST_PG_DB`, `TEST_PG_USER`, `TEST_PG_PASSWORD` | tests | PostgreSQL used by PHPUnit, `tests/run.sh` and the Playwright `pgsql-smoke` project. Defaults `127.0.0.1`, `5432`, `apotheke`, `apotheke`, `apotheke`. |
| `DB_DRIVER` | tests | `pgsql` runs PHPUnit / `tests/run.sh` against PostgreSQL. |
| `KEEP_TEST_DATA` | `tests/integration.php` | Keep the temporary data directories after the run. |
| `E2E_DOCKER_IMAGE` | Playwright | Adds the `docker-smoke` project, which runs the `@smoke` tests against that image instead of `php -S`. |
| `CI` | Playwright | CI mode: retries, the GitHub reporter, `forbidOnly`. |

## Stored secrets

Recoverable secrets the app stores are encrypted when a key is configured. Today that is the AI autofill access token (`autofill_token`). Password hashes are left as hashes. The whole database and the upload directories are not encrypted here; see [Deployment and operations](deployment-and-operations.md#security).

`APP_KEY` and `APP_KEY_FILE` are environment variables only. They are not keys in `config.php`, and they are not copied into the database.

### Envelope

The `settings` value is still a JSON string. With a key, the string is:

```
enc:v1:<base64>
```

`<base64>` is standard base64 (RFC 4648, with `=` padding) of a 24-byte nonce followed by the libsodium secretbox ciphertext. The nonce is new on every write (`random_bytes(24)`, `SODIUM_CRYPTO_SECRETBOX_NONCEBYTES`). The ciphertext is `sodium_crypto_secretbox`: the plaintext with a 16-byte Poly1305 tag at the end. The key is 32 raw bytes. A value without the `enc:v1:` prefix is older plaintext and is still read.

A port that reads this database, including a CodeIgniter 4 port, uses the same envelope and the same key source: strip `enc:v1:`, base64-decode, take the first 24 bytes as the nonce, and open the rest with `crypto_secretbox_open` and the 32-byte key from `APP_KEY` or from the file named by `APP_KEY_FILE`. The key is not in the database and not in a backup.

### Key

`APP_KEY` is the recommended source, and the one to use with Docker, so the key is not on the data volume. It is the standard base64 of 32 bytes. An optional `base64:` prefix is accepted, and surrounding space is ignored. Generate one before you save a secret. The placeholder in [`.env.example`](../.env.example) is empty; do not commit a real key.

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
php bin/settings.php key generate
```

`key generate` prints a key and stores nothing.

A non-empty `APP_KEY` wins over `APP_KEY_FILE`, including when `APP_KEY` does not decode. A configured key that cannot be used does not fall back to plaintext. The save is refused, the previous row stays as it was, and the Control Panel names the cause. The log names that same cause and does not include the key. A malformed `APP_KEY` logs `apotheke: APP_KEY is not a 32-byte key; stored secrets will not be saved.`

`APP_KEY_FILE` points at a file you create, with the same text. Keep it outside the data directory and outside every directory a backup can include (the data directory, `images/`, `thumbs/`, `files/`, `originals/`, and `ORIGINALS_DIR` when that path is elsewhere). The file has to be readable by the user the app runs as. Inside the image that user is `www-data` (uid 82, gid 82). `chown` runs on the host, where `www-data` is gid 33 on Debian and Ubuntu and some hosts have no such group, so use the number: `chown root:82 secrets/app_key && chmod 0440 secrets/app_key`. Outside Swarm, plain `docker compose` bind-mounts a `file:` secret with the host file's owner and mode and ignores uid, gid and mode. The same `root:82` and `0440` apply to that file. A path inside the refused directories is not used, including a symlink that resolves inside them, and a secret save is refused. The log says `apotheke: APP_KEY_FILE is inside the data directory or a backup path; stored secrets will not be saved.` A file the app cannot read is refused the same way (`apotheke: APP_KEY_FILE could not be read; stored secrets will not be saved.`). Apotheke does not create a key file for you.

PHP's `sodium` extension does the encryption. It is part of the Docker image. With a key configured and the extension missing, secret saves are refused and the log says `apotheke: sodium extension is missing; stored secrets will not be saved.` With no key configured, a missing extension leaves secrets plaintext, as in [No key](#no-key), and the log says `apotheke: ext-sodium is missing; stored secrets stay unencrypted.`

### No key

The app still starts, and those secrets stay plaintext. The Control Panel shows *Set APP_KEY to encrypt stored secrets.* next to the access token, and each process logs `apotheke: set APP_KEY to encrypt stored secrets` once. After a key is set, the next save writes the envelope. Reading a plaintext value rewrites it encrypted as well. That plaintext path is only when `APP_KEY` and `APP_KEY_FILE` are both unset. A key that is set but unusable shows a warning that names the cause, and refuses the save.

### Wrong or lost key

An `enc:v1:` value that does not open counts as unset. The secret is not shown, the request does not fail, and the stored text is left unchanged. The log names the setting and not the value: `apotheke: setting autofill_token could not be decrypted and is treated as unset.`

### Rotation

1. Generate a new key and keep it aside. Leave the current `APP_KEY` or `APP_KEY_FILE` in place.
2. Re-encrypt. The new key comes from `APP_KEY_NEW`, or from stdin when that variable is empty. It is not read from the command arguments.

```bash
APP_KEY_NEW='(new key)' php bin/settings.php key rotate
printf '%s' '(new key)' | php bin/settings.php key rotate
```

In Docker the current key is already in the container, and the new one is passed for that command only:

```bash
docker compose exec -e APP_KEY_NEW='(new key)' apotheke php /var/www/app/bin/settings.php key rotate
```

The command prints `rotated N, encrypted M` and no secret. `rotated` counts values that were already envelopes. `encrypted` counts older plaintext. If any envelope does not open with the current key, the command exits 1, names that setting, and changes nothing.

3. Put the new key in `APP_KEY` (or replace the file `APP_KEY_FILE` names), remove `APP_KEY_NEW`, and restart.

Backups and settings exports omit stored secrets and do not include the key.

## PHP and web server limits

A picked photo is uploaded unchanged when it fits **Maximum upload** (`uploads.max_mb`, default 20). Above that, the
browser saves a smaller JPEG and says the original file is not kept. The shipped limits already cover a typical
10–20 MB camera photo: `docker/php.ini` sets `upload_max_filesize=20M` and `post_max_size=22M`, and `docker/nginx.conf`
sets `client_max_body_size 22m`. Raise all three together with `uploads.max_mb` (the Control Panel allows up to 200);
`post_max_size` and `client_max_body_size` need to sit a little above `upload_max_filesize`. What the catalog shows
is still a stripped JPEG (longest side at most 1600 px). Backups uploaded
through the web restore can be much larger: the Docker image allows up to 520 MB on `/backup/restore` only (`client_max_body_size` and a `PHP_VALUE` override
in `docker/nginx.conf`; every other route keeps the 22 MB body limit, so a big zip gets HTTP 413). Otherwise use `bin/restore.php`.

`docker/php.ini` enables opcache and leaves JIT off. Function JIT can miscompile the QR encoder on PHP 8.2 to 8.4,
so when a function-JIT mode is active (`1235`, `function`, or any four-digit CRTO value whose trigger digit is not 5)
`src/qr.php` sets `opcache.jit=disable` for the whole worker on the first request. Tracing JIT stays on, and
`opcache_jit_blacklist()` does not help.

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

The role needs USAGE on an existing schema (and CREATE on it for migrations), but not CREATE on the database; see
[PostgreSQL role](deployment-and-operations.md#postgresql-role).

QR labels that should point at a fixed address:

```
PUBLIC_URL=http://apotheke.lan:9091
```
