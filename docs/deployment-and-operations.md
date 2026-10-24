# Deployment and operations

* [Backup and restore](#backup-and-restore)
* [Upgrades](#upgrades)
* [Behind a web server or reverse proxy](#behind-a-web-server-or-reverse-proxy)
* [HTTPS](#https)
* [PostgreSQL role](#postgresql-role)
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

Bump the pinned PHP patch in the Dockerfile on each PHP security release, then rebuild with `docker compose build --pull` and restart. CI checks that the built image's PHP version matches that pin.

```bash
docker compose build --pull
docker compose up -d
```

Notes:

* **Pre-migration SQLite installs** (schema v1–v3) are first copied to `data/catalog-v<N>-backup-<date>.sqlite`, then
  converted and adopted into the migration system in one transaction. Delete the snapshot once everything looks right.
* Migrations `0006` (low-stock level), `0007` (lending), `0008` (parent location) and `0009` (expiry date) only
  add nullable columns (`0010` only an index); existing items have no alert, are not lent and have no expiry date, existing locations stay
  top-level.
* Migration `0025` (2.4) turns AI autofill's *Analyze photos automatically* **off** on every install: photos are
  only analyzed when someone taps *Analyze*. An admin can turn it back on under Control Panel → AI autofill (see
  [Upgrading from 2.3](autofill.md#upgrading-from-23)).
* **Host names (this version).** The app now answers only to IP addresses, `localhost`, the machine's own host
  name, `PUBLIC_URL`'s host and `ALLOWED_HOSTS`. If you open it by another name (for example `nas.local:9091`),
  add that name to `ALLOWED_HOSTS` or set `PUBLIC_URL` before upgrading; otherwise the browser shows *Unknown host
  name* (421). Health checks (`/healthz`, `/health`) follow the same rule: point container, orchestrator and
  monitoring probes at an allowed host. See [Host names](#host-names).
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
* allow a request body of ~22 MB normally and more on `/backup/restore` if you restore through the browser (the
  Docker image allows the larger body only after sign-in; see [Sessions and the restore upload](#sessions-and-the-restore-upload)).

[`docker/nginx.conf`](../docker/nginx.conf) and [`docker/fastcgi.conf`](../docker/fastcgi.conf) are a complete,
tested example (static assets with long-lived caching and gzip, blocked paths, the larger limit and timeouts on
`/backup/restore`, granted through `auth_request`).

Upgrading from 1.x: the image listens on port 9091 (it was 80) and compose publishes 9091 (it was 8080) — point your
reverse proxy at the new port, or publish the old one with `ports: ["8080:9091"]`.

With the Docker image you usually only put a TLS-terminating reverse proxy in front of port 9091, for example Caddy:

```
apotheke.example.lan {
    reverse_proxy 127.0.0.1:9091
    header Strict-Transport-Security "max-age=31536000"
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
    add_header Strict-Transport-Security "max-age=31536000" always;
    client_max_body_size 520m;
    location / {
        proxy_pass http://127.0.0.1:9091;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 300s;
    }
}
```

`Strict-Transport-Security` tells browsers to use HTTPS for this name from then on. Add it only once HTTPS works
for that name.

Behind a TLS proxy:

* set **`PUBLIC_URL=https://apotheke.example.lan`** so QR labels use the public HTTPS address. That host name is
  also accepted by the Host check (see [Host names](#host-names)), and the session cookie is marked `Secure` on
  requests for that name;
* Apotheke reads `X-Forwarded-Proto` only when **Trust the reverse proxy** (`trust_proxy`, Control Panel →
  Users & sign-in, off by default) is on. With it on, a request the proxy marks as HTTPS counts as HTTPS, which
  also marks the session cookie `Secure`. Turn it on only when every request reaches the app through your own
  proxy that sets that header; leave it off when the app is also reachable directly;
* the session cookie is always `HttpOnly` and `SameSite=Lax`;
* `X-Forwarded-For` is read only when `trust_proxy` is on and the connecting peer is listed in `trusted_proxies` /
  `TRUSTED_PROXIES`. The client is the rightmost hop that isn't itself a listed proxy. With `trust_proxy` on and an
  empty list, the connecting address is used. The sign-in limit is 10 attempts per 15 minutes for one account from
  that client.

### Host names

The app answers only to host names it knows (hardening, SEC-M3). A request with any other `Host` header gets
**421 Misdirected Request**. Always accepted, with or without a port:

* IP addresses (IPv4, and IPv6 in brackets such as `[fd00::10]:9091`), so opening the app by its LAN address works
  with nothing configured;
* `localhost` (and `*.localhost`), and the machine's own host name (in Docker, the container's host name: the
  container ID unless `hostname:` is set in the compose file);
* the host of `PUBLIC_URL`;
* every name in `ALLOWED_HOSTS` (comma-separated, for example `ALLOWED_HOSTS=apotheke.lan,nas.lan`; a port in an
  entry is ignored). `*` accepts any name and turns the check off.

If you open the app by a name such as `apotheke.lan` or `nas.local`, set `PUBLIC_URL` to that address or add the name
to `ALLOWED_HOSTS`. A reverse proxy must pass the original `Host` (nginx: `proxy_set_header Host $host;`, which Caddy
does by default) or a name on this list.

The health endpoints `/healthz` and `/health` follow the same rule (#70 L-2). The image's own `HEALTHCHECK` uses
`127.0.0.1` and keeps working, but a container or orchestrator health check, or an outside monitor, must use an
allowed host: an IP address, `localhost`, the container's host name, `PUBLIC_URL`'s host or an `ALLOWED_HOSTS`
entry. A probe that uses any other name gets 421.

With sign-in off (no `APP_PASSWORD` and no accounts), a request that changes something (anything but GET, HEAD and
OPTIONS) must come from the app's own pages: the browser's `Sec-Fetch-Site` must be `same-origin` or `none`, or
`Origin` must be the app's own address or `PUBLIC_URL`. Requests that send neither header, such as `curl` and
scripts, are not affected. Behind a TLS proxy, set `PUBLIC_URL` to the `https://` address or turn on `trust_proxy`:
otherwise the app expects an `http://` `Origin`, and browsers that send only `Origin` (no `Sec-Fetch-Site`, such as
Safari before 16.4) get 403 on every change (#70 N-6).

### Sessions and the restore upload

A session (a small file in `DATA_DIR/sessions`) is created only when a page with a form is shown or someone signs
in (hardening, SEC-L2). 404 pages, the icons, the manifest and the theme CSS, health checks and anonymous redirects
to the sign-in page don't create one, and a session cookie whose session no longer exists is ignored. The sign-in
page has a form, so each visit to it without a session cookie still starts a session (the rest of SEC-L2, deferred);
PHP's session clean-up removes old session files (#70 N-5).

In the Docker image, nginx asks the app (`/backup/restore/check`, through `auth_request`) before it accepts a
restore upload. Only a signed-in user with the Backup permission (or anyone while sign-in is off) may send the up to
520 MB body; anyone else gets 401 or 403 before the body is read.

**Self-hosted nginx (without the Docker image).** Keep the 22 MB limit everywhere and restore large archives with
`bin/restore.php`, or raise the limit for `/backup/restore` only behind the same check:

1. Check that your nginx has the `auth_request` module: `nginx -V 2>&1 | grep -o with-http_auth_request_module`
   must print the module name (Debian, Ubuntu and Alpine packages include it).
2. Add both locations below to the `server` block that serves Apotheke, next to your front-controller location.
   Replace `php` in `fastcgi_pass` with your PHP-FPM upstream or socket, and include your own FastCGI settings in the
   first location (the Docker image uses [`docker/fastcgi.conf`](../docker/fastcgi.conf)). Keep
   `client_max_body_size 520m` in **both** locations: the check is a subrequest that sees the upload's
   `Content-Length`, and with a smaller limit nginx refuses the check itself and answers 500. nginx also turns
   any other answer from the check into 500; the `@restore_check_failed` location passes the app's 421 for an
   unknown host name on (hardening, #70 N-3), and nginx still logs *auth request unexpected status: 421*.

   ```nginx
   location = /backup/restore {
       auth_request /_restore_check;
       auth_request_set $restore_check $upstream_status;
       error_page 500 = @restore_check_failed;
       client_max_body_size 520m;
       client_body_timeout 300s;
       fastcgi_read_timeout 300s;
       fastcgi_send_timeout 300s;
       include /etc/nginx/apotheke-fastcgi.conf;   # your FastCGI settings for public/index.php
       fastcgi_param PHP_VALUE "upload_max_filesize=512M
   post_max_size=520M
   max_execution_time=300";
   }
   location = /_restore_check {
       internal;
       client_max_body_size 520m;
       fastcgi_pass php;
       fastcgi_pass_request_body off;
       fastcgi_param SCRIPT_FILENAME $document_root/index.php;
       fastcgi_param SCRIPT_NAME /index.php;
       fastcgi_param DOCUMENT_ROOT $document_root;
       fastcgi_param GATEWAY_INTERFACE CGI/1.1;
       fastcgi_param SERVER_PROTOCOL $server_protocol;
       fastcgi_param REQUEST_METHOD GET;
       fastcgi_param REQUEST_URI /backup/restore/check;
       fastcgi_param QUERY_STRING "";
       fastcgi_param CONTENT_TYPE "";
       fastcgi_param CONTENT_LENGTH "";
       fastcgi_param REMOTE_ADDR $remote_addr;
       fastcgi_param SERVER_ADDR $server_addr;
       fastcgi_param SERVER_PORT $server_port;
       fastcgi_param SERVER_NAME $server_name;
       fastcgi_param HTTPS $https if_not_empty;
       fastcgi_param PATH_INFO "";
       fastcgi_param HTTP_PROXY "";
   }
   location @restore_check_failed {
       if ($restore_check = 421) { return 421; }
       return 500;
   }
   ```

3. Run `nginx -t`, then reload nginx.
4. Check it: `curl -s -o /dev/null -w '%{http_code}\n' https://apotheke.example/backup/restore/check` prints `401`
   while signed out (or `204` with sign-in off), a large restore upload while signed out gets `401` at once, and
   one with an unknown `Host` gets `421`.
   [`scripts/docker-auth-checks.sh`](../scripts/docker-auth-checks.sh) runs the full set of checks.

### ImageMagick policy

The Docker image ships a restrictive ImageMagick policy in `/etc/ImageMagick-7/policy.xml` (hardening, S-M1 and
S-L1, from [`docker/imagemagick-policy.xml`](../docker/imagemagick-policy.xml)). It caps an image at 16,384 px
wide or high and 128 MP, ImageMagick's memory and map at 256 MiB, its disk cache at 1 GiB and its time at 60
seconds, and it turns off the coders that read text, vector or page formats or fetch URLs, all external delegates
and `@file` arguments. HEIC/HEIF, JPEG, PNG, WebP and GIF photos decode as before. The image build fails if the
policy is not in effect (`scripts/imagick-policy-check.php`: the limits Imagick reports, the refused coders, and
from `magick -list policy` no rights for delegates or `@file` paths and a time limit of 60 s or less; #70 N-2).

## HTTPS

Plain HTTP on a trusted LAN works for every feature, including camera uploads. Use HTTPS when the app is reachable
from outside your network or when you want the `Secure` session cookie and HSTS. Options for a home network: a reverse proxy with a certificate from your own CA (e.g. Caddy's
internal CA, trusted on your phones), or a real domain with a DNS-01 certificate. Camera uploads do not need HTTPS.

## PostgreSQL role

The application role does not need CREATE on the database when `DB_SCHEMA` already exists. PostgreSQL checks that
privilege before it notices that a schema is there, so Apotheke looks the name up in `pg_namespace` and runs
`CREATE SCHEMA` only when it is missing.

Create the schema as a role that is allowed to, and let the application role own it (or grant USAGE, and CREATE
on the schema so a later migration can add tables):

```sql
CREATE ROLE apotheke_app LOGIN PASSWORD '…' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE SCHEMA apotheke AUTHORIZATION apotheke_app;
```

Then set `DB_USER=apotheke_app` and `DB_SCHEMA=apotheke`. If the schema is missing and this role cannot create
it, startup fails. The message is in the PHP log (`[apotheke] …`) and on the CLI. It names the schema and does
not include the password or the DSN. The browser shows the generic error page.

`php bin/migrate.php` prints only the driver, host, port, dbname, and `DB_SCHEMA`. It never prints the user
or the password. SQLite prints the driver and the file path. A DSN that does not parse is shown as
`<unparsed dsn>`. When it can't connect to the database, the command prints `Could not connect to the database`
with those same fields and exits with status 1. It does not print or log the server's own error text (that
text names the database user), the password, or the full DSN, including when PHP includes exception arguments
in stack traces. `bin/seed.php`, `bin/backup.php`, `bin/restore.php` and `bin/settings.php` exit 1 with that
same line, and `/healthz` logs it. While the database is down, every route, including CSS, the manifest and `/login`, returns 500 and the log has only that sanitized line. A SQLite file that cannot be opened still reports the engine's own error.

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
  `/media/(full|thumb)/[a-f0-9]{32}.jpg`. GD does that for JPEG, PNG, WebP and GIF: transparent pixels are flattened
  onto white, and a CMYK JPEG is written as sRGB with no colour profile. The Docker image also has Imagick
  with libheif, so a HEIC upload gets the same stripped JPEG (GD still writes the file everyone sees) and autofill
  can build an analysis JPEG from it. Imagick does the same white background and sRGB conversion before encoding.
  For a grayscale photo, only the reduced JPEG sent to autofill is 1-channel. The displayed copy is re-encoded
  by GD as 3-channel and stays within a few levels.
  Photos at or under 40 megapixels still go through GD. A larger JPEG is decoded already reduced, and that
  decoded frame stays within 128 megapixels; a JPEG over 250 megapixels or 30,000 px on a side is refused
  from the header. A larger PNG, WebP or GIF is refused. A HEIC/HEIF photo over 128 megapixels or 16,384 px
  on a side is refused from the header before pixels are read. Imagick is limited to memory 256 MiB, map
  256 MiB, disk 1 GiB, area 128 megapixels, and width and height 16,384 px, the same limits as the policy the
  image ships ([ImageMagick policy](#imagemagick-policy)). Any decode
  Imagick stops for a resource limit is the same refusal. Only that analysis JPEG forces 4:2:0 chroma subsampling. A
  GD-only install stores the HEIC and shows a plain placeholder, never the HEIC bytes. A JPEG above 40
  megapixels needs Imagick; without it that photo is refused. A guessed `/originals/…` URL,
  including `/Originals/` and `/ORIGINALS/`, is 404.
* **Backups:** strict entry-name, size and schema validation before anything is written (see above).
* **Password:** `APP_PASSWORD` is compared in constant time; a wrong password costs 0.4 s; the session id is
  regenerated on sign-in and sign-out.
* **Host names:** only known host names are answered (421 otherwise), and with sign-in off a state-changing
  request must come from the app's own pages; see [Host names](#host-names).
* **HSTS:** the app does not send `Strict-Transport-Security` itself, because it often runs on plain HTTP on a LAN.
  Add it in your TLS proxy (see the Caddy and nginx examples above).
* Apotheke is meant for a trusted network. If you expose it to the internet, use HTTPS and set `APP_PASSWORD`.

## Monitoring and logs

* `GET /healthz` returns `ok` after connecting to the database (the Docker `HEALTHCHECK` uses it) and sends the running
  version in `X-Apotheke-Version`.
* `GET /health` returns `{"status":"ok","version":"1.1.0"}` (JSON, no login needed) for monitoring tools.
* Both health endpoints are behind the [Host check](#host-names): a probe must use an IP address, `localhost`, the
  container's host name, `PUBLIC_URL`'s host or an `ALLOWED_HOSTS` entry, or it gets 421.
* In Docker, nginx and php-fpm log to the container output: `docker compose logs -f apotheke`. Unexpected exceptions
  are logged as `[apotheke] …` and shown to the user as a generic error page.
* Disk usage is mostly `data/originals` (about the size of each camera file), plus the smaller stripped JPEG in
  `data/images` and the thumbnail in `data/thumbs`.
