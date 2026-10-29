# Getting started

* [Docker Compose (recommended)](#docker-compose-recommended)
* [PostgreSQL with the `postgres` profile](#postgresql-with-the-postgres-profile)
* [Plain `docker run`](#plain-docker-run)
* [Without Docker](#without-docker)
* [Local development](#local-development)
* [Command-line tools](#command-line-tools)

## Docker Compose (recommended)

```bash
git clone https://github.com/thordin9/apotheke.git && cd apotheke
echo 'APP_PASSWORD=change-me' > .env    # pick your own (8+ characters); it becomes the password of the account admin
docker compose up -d --build
```

The container needs sign-in: without `APP_PASSWORD` on the first start it stops with `sign-in is off` in
`docker compose logs`. To run without sign-in on purpose (a trusted network only), set `ALLOW_NO_LOGIN=1` instead.
Details: [Docker defaults](deployment-and-operations.md#docker-defaults-localhost-and-sign-in).

The compose file publishes port **9091** on `127.0.0.1` only, so at first the app opens just on the server itself
(`http://127.0.0.1:9091`). To use it from your phone, change the port line in `docker-compose.yml` to `"9091:9091"`
(all interfaces) or `"192.0.2.10:9091:9091"` (one LAN address of the server), run `docker compose up -d`, and open
`http://SERVER-IP:9091` on your phone (same network). In Safari tap Share → **Add to Home Screen**. For another host
port, change the part before the last `:9091` (e.g. `"8080:9091"`).

The compose file mounts `./data` as `/data`:

```
data/
  catalog.sqlite      SQLite database (created on first start)
  images/             served photos (JPEG, longest side ≤ IMAGE_FULL_MAX, 32-hex names, metadata stripped)
  thumbs/             thumbnails (longest side ≤ IMAGE_THUMB_MAX)
  originals/          camera uploads, byte-identical when they fit the upload limit, 64-hex names (not served)
  files/              item attachments
  sessions/           PHP session files
  tmp/                short-lived files for backup downloads and restore uploads
  postgres/           only when the postgres profile is used
```

Settings go into a `.env` file next to `docker-compose.yml` (copy [`.env.example`](../.env.example)). Generate an
`APP_KEY` so stored secrets are encrypted; do not commit a real key.

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
# or, once the container is up (prints a key, stores nothing):
docker compose exec apotheke php /var/www/app/bin/settings.php key generate
```

```
APP_KEY=(paste the line above)
APP_PASSWORD=change-me
TZ=Europe/Athens
```

Run `docker compose up -d` again after changing `.env`. The compose file passes `TZ`, `APP_PASSWORD`,
`ALLOW_NO_LOGIN`, `APP_KEY`, `APP_KEY_FILE`, `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` and `DB_PASSWORD`
through; add any other variable from [Configuration](configuration.md) under `environment:`. `APP_KEY` is the key
source to use with Docker, because it stays out of the `./data` volume. For `APP_KEY_FILE` and its file permissions
see [Stored secrets](configuration.md#stored-secrets).

**What's in the image:** `php:8.4.27-fpm-alpine` with nginx in the same container and the `gd`, `imagick` (HEIC/HEIF
via `libheif`), `exif`, `opcache`, `pdo_pgsql`, `sodium` and `zip` extensions. Imagick is built from its GitHub
release tag, with pecl.php.net as a second source, each pinned by its own sha256 (`IMAGICK_SHA256` and
`IMAGICK_PECL_SHA256` next to `IMAGICK_VERSION` in the `Dockerfile`; change all three to upgrade). Opcache is on and
JIT is off. The default `ARG PHP_VERSION=8.4.27` is the shipped image. It serves the CodeIgniter 4 app;
`APOTHEKE_APP=legacy` exits with code 64. `tini` is PID 1 and `docker/entrypoint.sh`:

1. creates `images/`, `thumbs/`, `originals/` and `sessions/` in `DATA_DIR` and makes them writable for `www-data`
   (it refuses to start, exit code 78, when one of them is a symbolic link),
2. checks that sign-in is on (see above),
3. renders `docker/nginx.conf` with the port from `PORT` (default `9091`),
4. starts php-fpm (unix socket `/run/php-fpm.sock`), waits for the socket, then starts nginx,
5. forwards `SIGTERM` to both on `docker stop` and exits if either process dies.

A `HEALTHCHECK` polls `/healthz`.

### Updating

```bash
git pull
docker compose up -d --build
```

After a PHP security release, fetch the new base image too: `docker compose build --pull`, then
`docker compose up -d`. Pending database migrations run automatically on the first request. See
[Upgrades](deployment-and-operations.md#upgrades); for the CodeIgniter 4 app (3.0) see
[Upgrading to 3.0](upgrade-3.0.md).

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
full `DB_DSN`), optionally `DB_SCHEMA`, and leave the profile off. Photos are always stored in `DATA_DIR`, whichever
database you use. For a role without CREATE on the database see [PostgreSQL role](deployment-and-operations.md#postgresql-role).

## Plain `docker run`

```bash
docker build --pull -t apotheke .
docker run -d --name apotheke -p 127.0.0.1:9091:9091 -v "$PWD/data:/data" \
  -e TZ=Europe/Athens -e APP_PASSWORD=change-me -e APP_KEY='(paste the generated key)' apotheke
```

`-p 127.0.0.1:9091:9091` keeps the app on the server itself; use `-p 9091:9091` to reach it from your network. Without
`APP_PASSWORD` (and with sign-in not yet on in the database) the container refuses to start unless `-e ALLOW_NO_LOGIN=1`
is set. With `--network host`, set `-e PORT=8081` (or any free port) so nginx listens there.

## Without Docker

The supported install is the Docker image ([Upgrading to 3.0](upgrade-3.0.md)); running without Docker suits
development and testing. Requirements: PHP 8.4 (as the Docker image) and Composer, with `pdo_sqlite` or `pdo_pgsql`, `gd` (JPEG and WebP),
`mbstring`, `sodium` (stored secrets) and `zip` (backups); `exif` is recommended (photo orientation), `dom` for SVG
logo uploads, `opcache` for speed. Optional `imagick` reads HEIC and files GD cannot; without it a HEIC upload is kept
and shown as a placeholder. SQLite is built in; PostgreSQL is tested with 17. If you turn on function JIT, see
[PHP and web server limits](configuration.md#php-and-web-server-limits).

```bash
git clone https://github.com/thordin9/apotheke.git && cd apotheke
composer install --no-dev              # CodeIgniter 4 and its pinned dependencies
cp config.example.php config.php        # optional; or use environment variables
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # or: php bin/settings.php key generate
export APP_KEY='(paste that line)'      # empty keeps stored secrets unencrypted; do not commit the key
php -d upload_max_filesize=20M -d post_max_size=22M -S 0.0.0.0:9091 -t public public/router.php
```

Data goes to `./data` (or `DATA_DIR`). `public/router.php` is the router script for PHP's built-in server (static
assets, then `public/index.php`), which is fine for a home LAN. For Apache or nginx see
[Behind a web server or reverse proxy](deployment-and-operations.md#behind-a-web-server-or-reverse-proxy).

## Local development

```bash
composer install                                        # PHP 8.4
php -S 127.0.0.1:9091 -t public public/router.php       # http://127.0.0.1:9091, data in ./data
php bin/seed.php --demo                                 # optional demo items with generated photos
tests/run.sh                                            # lint + the module suite
```

There is nothing to build: edit `modules/`, `public/assets/app.css` or `app.js` and reload. `asset()` adds a
`?v=` hash of each file's modification time and size, so browsers always fetch the new version. Tests:
[Testing](testing.md).

## Command-line tools

They read the same configuration as the web app. In Docker, run them as
`docker compose exec apotheke php /var/www/app/bin/…`.

```bash
php bin/migrate.php                      # apply pending migrations (also adopts legacy SQLite installs)
php bin/migrate.php --status             # list applied / pending migrations
php bin/seed.php                         # starter locations/categories (idempotent, skips existing names)
php bin/seed.php --demo                  # … plus a few demo items with generated photos
php bin/backup.php [file.zip]            # write a backup zip (default ./apotheke-backup-<date>.zip)
php bin/restore.php file.zip --dry-run   # validate and show what would be restored
php bin/restore.php file.zip [--yes]     # REPLACE all data with the backup (asks first unless --yes); as the data folder's owner
php bin/settings.php list                # Control Panel settings: get KEY · set KEY VALUE · reset SECTION · export · import FILE
php bin/settings.php users               # accounts; user add|password|role|approve|rename|delete|signout NAME …; roles [reset]; auth on|off
php bin/settings.php key generate        # print a new APP_KEY; stores nothing
```

`bin/migrate.php`, `bin/seed.php`, `bin/backup.php`, `bin/restore.php` and `bin/settings.php` (except `key generate`,
which needs no app) run the CodeIgniter commands (`php spark apotheke:migrate|seed|backup|restore|settings|accounts`)
with the same output and exit codes. See [Upgrading to 3.0](upgrade-3.0.md).

Settings, accounts and keys: [Control Panel → Command line](control-panel.md#command-line). Backups and cron:
[Backup and restore](deployment-and-operations.md#backup-and-restore).

Next: [Configuration](configuration.md) · [User guide](user-guide.md) · [Testing](testing.md)
