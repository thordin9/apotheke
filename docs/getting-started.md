# Getting started

* [Docker Compose (recommended)](#docker-compose-recommended)
* [PostgreSQL with the `postgres` profile](#postgresql-with-the-postgres-profile)
* [Plain `docker run`](#plain-docker-run)
* [Without Docker](#without-docker)
* [Local development](#local-development)

## Docker Compose (recommended)

```bash
git clone https://github.com/thordin9/apotheke.git && cd apotheke
docker compose up -d --build
```

Open `http://SERVER-IP:9091` on your phone (same network). The compose file maps host port **9091** to port 9091 in the
container and mounts `./data` as `/data`:

```
data/
  catalog.sqlite      SQLite database (created on first start)
  images/             served photos (JPEG, longest side ≤ IMAGE_FULL_MAX, 32-hex names, metadata stripped)
  thumbs/             thumbnails (longest side ≤ IMAGE_THUMB_MAX)
  originals/          camera uploads, byte-identical when they fit the upload limit, 64-hex names (not served)
  sessions/           PHP session files
  tmp/                short-lived files for backup downloads and restore uploads
  postgres/           only when the postgres profile is used
```

Settings go into a `.env` file next to `docker-compose.yml`, for example:

```
APP_PASSWORD=change-me
TZ=Europe/Athens
```

Run `docker compose up -d` again after changing it. The compose file passes `TZ`, `APP_PASSWORD`, `DB_DRIVER`,
`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD` through; to set any other variable from
[Configuration](configuration.md), add it under `environment:`.

**What's in the image:** `php:8.3-fpm-alpine` plus nginx in the same container, with the `gd`, `imagick` (HEIC/HEIF via
`libheif`), `exif`, `opcache`, `pdo_pgsql` and `zip` extensions (`pdo_sqlite` is built in). That default is the shipped 2.5.x image.
`docker build --build-arg PHP_VERSION=8.4` builds the CI test image from the same Dockerfile, including Imagick. Compose and the published image keep the default. Imagick is built from its
GitHub release tag, with pecl.php.net as a second source. Each is pinned by its own sha256 (`IMAGICK_SHA256` and
`IMAGICK_PECL_SHA256` in the `Dockerfile`, next to `IMAGICK_VERSION`), and the build fails if neither download matches.
To upgrade it, change all three. Opcache is on and JIT is
left off (`docker/php.ini` sets no JIT buffer). Installs without Docker stay on GD. When Imagick does decode a photo
(HEIC, or a file GD cannot read), it flattens transparent areas onto white and converts CMYK to sRGB, then drops
colour profiles, matching the JPEG GD writes. For a grayscale photo, Imagick writes a 1-channel JPEG, so
that match is approximate. GD itself is unchanged. `tini` is PID 1 and `docker/entrypoint.sh`:

1. renders `docker/nginx.conf` with the port from `PORT` (default `80`),
2. creates `images/`, `thumbs/`, `originals/` and `sessions/` in `DATA_DIR` and makes them writable for `www-data`,
3. starts php-fpm (unix socket `/run/php-fpm.sock`), waits for the socket, then starts nginx,
4. forwards `SIGTERM` to both on `docker stop` and exits if either process dies.

A `HEALTHCHECK` polls `/healthz`.

### Updating

```bash
git pull
docker compose up -d --build
```

Pending database migrations run automatically on the first request. See
[Deployment and operations](deployment-and-operations.md#upgrades).

## PostgreSQL with the `postgres` profile

SQLite is the default and needs nothing else. To use the bundled PostgreSQL 17 container instead, put this in `.env`:

```
DB_DRIVER=pgsql
DB_PASSWORD=a-strong-password
```

and start with the profile:

```bash
docker compose --profile postgres up -d --build
```

The `postgres` service only starts with `--profile postgres`. It refuses to start without `DB_PASSWORD`, keeps its
data in `./data/postgres` and has a `pg_isready` health check. The app reaches it as host `postgres` (the default
`DB_HOST` in the compose file).

To use an existing PostgreSQL server instead, set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD` (or a
full `DB_DSN`) and leave the profile off. Photos are always stored in `DATA_DIR`, whichever database you use.

`DB_SCHEMA` is optional. On connect, Apotheke looks that name up in `pg_namespace` and runs `CREATE SCHEMA` only
when it is missing. A database role does not need CREATE on the database when the schema already exists. Create
the schema first and grant the role USAGE on it, and CREATE on the schema so migrations can add tables. If the
schema is missing and the role cannot create it, startup fails. The log names the schema and does not
include the password or the DSN. The browser shows a generic page, and `/healthz` is not `ok`.

## Plain `docker run`

```bash
docker build -t apotheke .
docker run -d --name apotheke -p 9091:9091 -v "$PWD/data:/data" -e TZ=Europe/Athens apotheke
```

With `--network host`, set `-e PORT=8081` (or any free port) so nginx listens there.

## Without Docker

Requirements: PHP 8.2+ with `pdo_sqlite` or `pdo_pgsql`, `gd` (JPEG and WebP), `mbstring` and `zip`; `exif` is
recommended (photo orientation), `dom` for SVG logo uploads, `opcache` for speed. Optional `imagick` reads HEIC and
files GD cannot; it uses the same white background and sRGB JPEG as GD, and does not change the GD path.
For a grayscale photo, Imagick writes a 1-channel JPEG, so that match is approximate. Leave
function JIT off, or rely on the guard: when a function-JIT mode is active (`1235`, `function`, or any four-digit CRTO
value whose trigger digit is not 5), `src/qr.php` sets `opcache.jit=disable` for the whole PHP worker on the first
request. Tracing JIT (`tracing`, `on`, trigger digit 5) stays on. The cost is negligible, and
`opcache_jit_blacklist()` does not help. On 2.5.x, before that guard, function JIT on PHP 8.2, 8.3 and 8.4 could
print a wrong QR code.

```bash
git clone https://github.com/thordin9/apotheke.git && cd apotheke
cp config.example.php config.php        # optional
php -d upload_max_filesize=20M -d post_max_size=22M -S 0.0.0.0:9091 -t public public/index.php
```

`public/index.php` doubles as the router script for PHP's built-in server: real files under `public/` are served
directly, everything else goes through the app. For Apache or nginx see
[Deployment and operations](deployment-and-operations.md#behind-a-web-server-or-reverse-proxy).

## Local development

```bash
php -S 127.0.0.1:9091 -t public public/index.php       # legacy app on http://127.0.0.1:9091, data in ./data
php -S 127.0.0.1:9091 -t ci4/public ci4/public/router.php # CI4 skeleton (health endpoints; needs PHP 8.4 and ci4/vendor)
php bin/seed.php --demo                                 # optional demo items with generated photos
tests/run.sh                                            # lint + HTTP integration suite (no Composer needed)
composer install && vendor/bin/phpunit                  # PHPUnit (dev only)
npm ci && npx playwright install --with-deps chromium webkit && npm run test:e2e   # Playwright
```

There is nothing to build: edit `src/*.php`, `public/assets/app.css` or `app.js` and reload. `asset()` adds a
`?v=` hash of each file's modification time and size, so browsers always fetch the new version.

Useful command-line tools (they use the same configuration as the web app; in Docker run them with
`docker compose exec apotheke php /var/www/app/bin/…`):

```bash
php bin/migrate.php                      # apply pending migrations
php bin/migrate.php --status             # list applied / pending migrations
php bin/seed.php                         # starter locations/categories (idempotent)
php bin/seed.php --demo                  # … plus demo items
php bin/backup.php [file.zip]            # write a backup zip
php bin/restore.php file.zip --dry-run   # validate and show what would be restored
php bin/restore.php file.zip [--yes]     # replace all data with the backup
```

Next: [Configuration](configuration.md) · [User guide](user-guide.md) · [Testing](testing.md)
