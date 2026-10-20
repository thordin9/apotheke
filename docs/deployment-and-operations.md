# Deployment and operations

* [Backup and restore](#backup-and-restore)
* [Upgrades](#upgrades)
* [Behind a web server or reverse proxy](#behind-a-web-server-or-reverse-proxy)
* [HTTPS](#https)
* [Security](#security)
* [Monitoring and logs](#monitoring-and-logs)

## Backup and restore

A backup is one zip file:

```
backup.json      format "apotheke-backup": schema versions, source driver, creation time and every table
                 (locations, categories, tags, items, item_photos, item_tags, app_settings, activity) as columns + rows
images/<hex>.jpg            served photos (stripped JPEG, 32 hex characters)
thumbs/<hex>.jpg            thumbnails
originals/index.json        served name → camera-file name, when one was kept
originals/<64 hex>.<ext>    that camera file, byte for byte (jpg, png, webp, gif or heic)
```

A backup from an older Apotheke has no `originals/` entries and still restores. CSV export and the settings JSON stay
sharing copies: they name the served photo, not the camera file, and they do not contain its bytes.

Deleting an item (once undo has expired) or deleting or replacing a photo removes the served JPEG, the thumbnail and
the camera file. Nothing from that photo is left in `images/`, `thumbs/` or `originals/`.

The dump is database-independent, so a backup taken on SQLite restores on PostgreSQL and the other way round. Every
column is exported automatically, which is why newer fields (low-stock level, lending) are included without any extra
code.

**From the browser:** ☰ → *Backup & restore*. *Download backup* streams the zip (built in `DATA_DIR/tmp` and deleted
afterwards). *Restore* uploads a zip, validates it and shows a dry-run comparison; only after you tick the
confirmation and press *Restore* is **all data replaced**, in one transaction. Uploaded restore files wait in
`DATA_DIR/tmp` under a random token until confirmed.

**From the command line** (cron, and any archive larger than the browser upload limit):

A backup that includes camera files is often bigger than 22 MB. On a non-Docker install, and on every Docker route
except `/backup/restore`, the body limit is about 22 MB (`upload_max_filesize` 20M, `post_max_size` 22M,
`client_max_body_size 22m`), so a browser restore answers **413**. Docker allows 520 MB on `/backup/restore` only.
Raise those three together if you restore large zips in the browser (`post_max_size` and `client_max_body_size` a
little above `upload_max_filesize`). Otherwise use the command line, which does not pass through that limit:

```bash
php bin/backup.php                        # ./apotheke-backup-<date>.zip
php bin/backup.php /backups/home.zip
php bin/restore.php /backups/home.zip --dry-run
php bin/restore.php /backups/home.zip     # asks for confirmation
php bin/restore.php /backups/home.zip --yes
```

In Docker: `docker compose exec apotheke php /var/www/app/bin/backup.php /data/backup.zip`.

A nightly cron example on the host:

```cron
15 3 * * * cd /srv/apotheke && docker compose exec -T apotheke php /var/www/app/bin/backup.php /data/backups/apotheke-$(date +\%F).zip
```

(create `data/backups/` first and prune old files yourself).

What the restore checks: that the file is a zip with only `backup.json`, `images/*.jpg`, `thumbs/*.jpg`,
`files/<hex>.<pdf|jpg|png|webp>`, `originals/index.json` and `originals/<64 hex>.<ext>` entries, the entry count and
unpacked size limits, the format and version, that every table
and column is known to this version (a backup from a **newer** schema is refused), and that rows are well-formed.
Missing photo files produce a warning. Photos in `data/images` that the restored data doesn't reference are left on
disk.

Other options: a copy of `data/` while the container is stopped, or `sqlite3 data/catalog.sqlite ".backup copy.sqlite"`
while it runs (SQLite), or `pg_dump` (PostgreSQL; photos are still in `data/`).

## Upgrades

1. Take a backup.
2. `git pull`, then `docker compose up -d --build` (or replace the files of a non-Docker install).
3. Pending migrations run automatically on the first request, in one transaction under a lock; or run
   `php bin/migrate.php` yourself and check with `php bin/migrate.php --status`.

Notes:

* **Pre-migration SQLite installs** (schema v1–v3) are first copied to `data/catalog-v<N>-backup-<date>.sqlite`, then
  converted and adopted into the migration system in one transaction. Delete the snapshot once everything looks right.
* Migrations `0006` (low-stock level), `0007` (lending), `0008` (parent location) and `0009` (expiry date) only
  add nullable columns (`0010` only an index); existing items have no alert, are not lent and have no expiry date, existing locations stay
  top-level.
* Migration `0025` (2.4) turns AI autofill's *Analyze photos automatically* **off** on every install: photos are
  only analyzed when someone taps *Analyze*. An admin can turn it back on under Control Panel → AI autofill (see
  [Upgrading from 2.3](autofill.md#upgrading-from-23)).
* Browsers pick up new CSS/JS immediately (`?v=` hashes).
* Older backups can always be restored into a newer version.
* **Camera uploads (this version).** Existing photos are not rewritten. They keep working and simply have no file
  under `originals/`. New uploads are stored twice: the camera file (about the size of the file that was sent) and the
  stripped JPEG you already had. A file over the upload limit is reduced in the browser first, and that smaller copy
  is what is kept. Set `ORIGINALS_DIR` only if that directory should not be `<data_dir>/originals`. It must not
  be inside `public/` (Apotheke refuses to start if it is). Backups taken after the upgrade include the camera files; older zips restore as before.

## Behind a web server or reverse proxy

Rules for any web server in front of PHP-FPM:

* the document root is `public/`;
* every request that is not an existing file in `public/` goes to `public/index.php`;
* no other PHP file is ever executed, and `src/`, `data/`, `migrations/`, `bin/`, `config.php` and any URL path
  containing `/originals` are not reachable, in any spelling (Docker nginx: `location ~* ^/originals(/|$) { return 404; }`; Apache:
  `public/.htaccess` `RedirectMatch` with `(?i)`, plus `Require all denied` and `Deny from all` inside the originals directory);
* allow a request body of ~22 MB normally and more on `/backup/restore` if you restore through the browser.

[`docker/nginx.conf`](../docker/nginx.conf) and [`docker/fastcgi.conf`](../docker/fastcgi.conf) are a complete,
tested example (static assets with long-lived caching and gzip, blocked paths, the larger limit and timeouts on
`/backup/restore`).

Upgrading from 1.x: the image listens on port 9091 (it was 80) and compose publishes 9091 (it was 8080) — point your
reverse proxy at the new port, or publish the old one with `ports: ["8080:9091"]`.

With the Docker image you usually only put a TLS-terminating reverse proxy in front of port 9091, for example Caddy:

```
apotheke.example.lan {
    reverse_proxy 127.0.0.1:9091
    request_body {
        max_size 520MB
    }
}
```

or nginx:

```nginx
server {
    listen 443 ssl;
    server_name apotheke.example.lan;
    # ssl_certificate …; ssl_certificate_key …;
    client_max_body_size 520m;
    location / {
        proxy_pass http://127.0.0.1:9091;
        proxy_set_header Host $host;
        proxy_read_timeout 300s;
    }
}
```

Apotheke does not read `X-Forwarded-*` headers. Behind a TLS proxy, therefore:

* set **`PUBLIC_URL=https://apotheke.example.lan`** so QR labels use the public HTTPS address;
* the session cookie's `Secure` flag is only set when PHP itself sees HTTPS; the cookie is still `HttpOnly` and
  `SameSite=Lax`.

## HTTPS

Plain HTTP on a trusted LAN works for every feature, including camera uploads. Use HTTPS when the app is reachable
from outside your network or when you want the `Secure` session cookie. Options for a home network: a reverse proxy with a certificate from your own CA (e.g. Caddy's
internal CA, trusted on your phones), or a real domain with a DNS-01 certificate. Camera uploads do not need HTTPS.

## Security

* **Headers** on every response from the app: `Content-Security-Policy`, `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`. nginx hides its version and PHP's `X-Powered-By`.
* **CSP:**

  ```
  default-src 'self'; connect-src 'self' blob:; img-src 'self' blob: data:; style-src 'self'; script-src 'self';
  object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'
  ```

  No inline scripts, no inline styles, no third-party hosts. `blob:` is needed for client-side photo shrinking.
* **CSRF:** every state-changing request (forms and fetch calls, including bulk actions, lending and duplicating)
  needs the per-session token; an expired token shows a friendly page (status 419) and changes nothing.
* **SQL:** prepared statements everywhere; dynamic parts (sort columns, table names in backups) come from fixed
  allow-lists.
* **Output:** every value is HTML-escaped (`h()`).
* **Uploads:** the camera file is kept byte-identical under `originals/` with a 64-hex name that is not the served
  name, when the picked file fits the upload limit. A larger file is reduced in the browser, and the form says the
  original is not kept. The copy everyone sees is a stripped JPEG (EXIF/GPS dropped, orientation applied) and served only through
  `/media/(full|thumb)/[a-f0-9]{32}.jpg`. GD does that for JPEG, PNG, WebP and GIF. The Docker image also has Imagick
  with libheif, so a HEIC upload gets the same stripped JPEG (GD still writes the file everyone sees) and autofill
  can build an analysis JPEG from it. Only that analysis JPEG forces 4:2:0 chroma subsampling. A
  GD-only install stores the HEIC and shows a plain placeholder, never the HEIC bytes. A guessed `/originals/…` URL,
  including `/Originals/` and `/ORIGINALS/`, is 404.
* **Backups:** strict entry-name, size and schema validation before anything is written (see above).
* **Password:** `APP_PASSWORD` is compared in constant time; a wrong password costs 0.4 s; the session id is
  regenerated on sign-in and sign-out.
* Apotheke is meant for a trusted network. If you expose it to the internet, use HTTPS and set `APP_PASSWORD`.

## Monitoring and logs

* `GET /healthz` returns `ok` after connecting to the database (the Docker `HEALTHCHECK` uses it) and sends the running
  version in `X-Apotheke-Version`.
* `GET /health` returns `{"status":"ok","version":"1.1.0"}` (JSON, no login needed) for monitoring tools.
* In Docker, nginx and php-fpm log to the container output: `docker compose logs -f apotheke`. Unexpected exceptions
  are logged as `[apotheke] …` and shown to the user as a generic error page.
* Disk usage is mostly `data/originals` (about the size of each camera file), plus the smaller stripped JPEG in
  `data/images` and the thumbnail in `data/thumbs`.
