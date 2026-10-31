# Deployment and operations

* [Backup and restore](#backup-and-restore)
* [Upgrades](#upgrades)
* [Behind a web server or reverse proxy](#behind-a-web-server-or-reverse-proxy)
* [The FrankenPHP image](#the-frankenphp-image)
* [Cloudflare deploys (getapotheke.com)](#cloudflare-deploys-getapothekecom)
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

A backup that includes camera files is often bigger than the 22 MB body limit of a non-Docker install (Docker
allows 520 MB on `/backup/restore` only; see [PHP and web server limits](configuration.md#php-and-web-server-limits)).
The command line has no such limit:

```bash
php bin/backup.php                        # ./apotheke-backup-<date>.zip
php bin/backup.php /backups/home.zip
php bin/restore.php /backups/home.zip --dry-run
php bin/restore.php /backups/home.zip     # asks for confirmation
php bin/restore.php /backups/home.zip --yes
```

In Docker: `docker compose exec apotheke php /var/www/app/bin/backup.php /data/backup.zip`.

Run `bin/restore.php`, `bin/backup.php`, `bin/migrate.php`, `bin/seed.php` and `bin/settings.php` as the user that
owns the data folder (the user the web server's PHP runs as), for example
`sudo -u www-data php bin/restore.php /backups/home.zip`. Started as root, each one switches to the data folder's owner
before it reads the database or writes a file, so every file it writes (a restored photo, a backup zip, a new SQLite
database) belongs to that user; this is what happens with `docker compose exec apotheke php /var/www/app/bin/…`, where
`/data` belongs to `www-data`. The group is the data folder's group, or the owner's own group when the folder's group
is root's. When the data folder is missing or owned by root, the user cannot be told, or the switch is not possible,
the tool stops with exit 1 and changes nothing. `bin/settings.php key generate` uses no data and runs as anyone. A
backup written as the data folder's owner needs a target folder that user can write to.
`bin/restore.php`, `bin/backup.php`, `bin/migrate.php`, the `bin/settings.php` forms that run on
CI4, and every `php spark` command (`apotheke:restore` with or without `--dry-run`, and the framework's own
commands too) do the same: started as root they switch to the data folder's owner and group before CodeIgniter starts,
or stop with exit 1. A user that cannot be told counts as root, and a PHP build without the posix group functions stops
with exit 1 too.

The tools read `config.php` (or the file `APOTHEKE_CONFIG` names) before they switch, as root when started as root.
Keep that file owned by root or the data folder's owner and not writable by the web server's user.

A nightly cron example on the host:

```cron
15 3 * * * cd /srv/apotheke && docker compose exec -T apotheke php /var/www/app/bin/backup.php /data/backups/apotheke-$(date +\%F).zip
```

(create `data/backups/` first and prune old files yourself).

What the restore checks: that the file is a zip with only `backup.json`, `images/*.jpg`, `thumbs/*.jpg`,
`files/<hex>.<pdf|jpg|png|webp>`, `originals/index.json` and `originals/<64 hex>.<ext>` entries, the entry count and
unpacked size limits (each file other than `backup.json` at most 200 MiB, read from the zip's directory before anything
is unpacked), the format and version, that every table
and column is known to this version (a backup from a **newer** schema is refused), and that rows are well-formed.
Missing photo files produce a warning. Photos in `data/images` that the restored data doesn't reference are left on
disk.

One restore runs at a time: while one runs, another one (from the browser or `bin/restore.php`) is refused with
“Another restore is running. Try again when it has finished.” A restore that fails part-way removes the photo,
thumbnail, attachment and camera files it had added (also when a fatal error such as running out of memory stops it),
and links photos to the backup's camera files only once the data is in, so the catalog and the files in `data/` are as
they were. Folders it made (`images/`, `thumbs/`, `files/`, `originals/`) and the camera-files deny file can stay, and a
restore whose process is killed can leave the new files it had written; nothing refers to them. A link where a restore
would write a new photo, thumbnail, attachment or camera file, or at an existing camera file's name, stops the
restore and is left as it is. A photo, thumbnail or attachment that can only be written in part (a full disk) fails
the restore. Camera files and their links are optional: when `originals/` cannot take a camera file (full, not
writable, or a folder at that file's name), that camera file and the ones after it are skipped with a warning and the
restore continues; the photos are whole and keep their old camera-file links. The photos' camera-file links are
written after the data is in, each through a temp file renamed into place; a link or a folder at one of their names is
left as it is and that photo is not linked to its camera file, and a link that cannot be written (a full or read-only
map folder) skips the rest of the links with a warning; the restore still succeeds. `bin/restore.php` prints these
warnings after the `Restored:` line. A failed restore in the browser removes its upload at once; one refused because
another restore runs keeps it, so it can be confirmed again.

Other options: a copy of `data/` while the container is stopped, or `sqlite3 data/catalog.sqlite ".backup copy.sqlite"`
while it runs (SQLite), or `pg_dump` (PostgreSQL; photos are still in `data/`).

## Upgrades

1. Take a backup.
2. `git pull`, then `docker compose up -d --build` (or replace the files of a non-Docker install). After a PHP
   security release also run `docker compose build --pull`; the Dockerfile pins the PHP patch and CI checks that the
   image runs it.
3. Pending migrations run automatically on the first request, in one transaction under a lock; or run
   `php bin/migrate.php` yourself and check with `php bin/migrate.php --status`.

Browsers pick up new CSS and JS at once (`?v=` hashes), and older backups always restore into a newer version. Check
the **Upgrade note** of each release in the [changelog](../CHANGELOG.md). Notes that still matter for older installs:

* **From 1.x:** the image listens on port 9091 (it was 80) and compose publishes 9091 (it was 8080). Update bookmarks,
  home-screen icons, reverse proxies and the Public URL, and reprint QR labels that contain `:8080`, or keep the old
  address with `ports: ["127.0.0.1:8080:9091"]`.
* **Pre-migration SQLite installs** (schema v1–v3) are first copied to `data/catalog-v<N>-backup-<date>.sqlite`, then
  converted in one transaction: free-text locations become managed locations, and categories are added. Delete the
  snapshot once everything looks right.
* **2.4:** migration `0025` turns AI autofill's *Analyze photos automatically* off on every install; see
  [Upgrading from 2.3](autofill.md#upgrading-from-23).
* **2.5 (camera uploads):** existing photos are not rewritten and have no file under `originals/`; new uploads keep
  the camera file next to the stripped JPEG. `ORIGINALS_DIR` must not be inside `public/` (Apotheke refuses to start).
* **2.6.2 (host names):** the app answers only to known host names, health checks included; see
  [Host names](#host-names).
* **2.6.7 (Docker defaults):** the container needs sign-in and compose publishes `127.0.0.1` only; see
  [Docker defaults](#docker-defaults-localhost-and-sign-in).
* **3.0 (CodeIgniter 4):** a switch of the app on the same data, with one sign-in for everyone and two rollbacks;
  see [Upgrading to 3.0](upgrade-3.0.md).
* **CI4 and `;` in the database credentials.** On the CI4 app, a `DB_USER` or `DB_PASSWORD` that contains `;` cannot
  be combined with `user=` / `password=` text inside another `DB_DSN` setting (`options`, `application_name`, the
  `sslpassword` keyword); boot stops with a message saying so. Remove that text from `DB_DSN`, or change the
  credential so it has no `;`.

## Background jobs

From 3.2 long tasks can run as background jobs: a row in the `jobs` table (migration 0029) that
`php spark jobs:work` picks up. The first type is `import.commit`, the API's CSV import (`POST
/api/v1/imports/{id}/commit`; the import page still imports in the request); the backup restore moves to jobs later.
Both Docker images run the worker, so a queued job is picked up without any extra setup.

```bash
php spark jobs:work [--once] [--max-jobs=N] [--max-seconds=N] [--sleep=MS]
```

* It runs one job at a time until `--max-jobs` jobs ran or `--max-seconds` passed (default 3600, then a supervisor
  starts it again), waiting `--sleep` ms when no job is due (`--sleep=0` stops instead; `--once` runs at most one).
* A job is leased for five minutes (300 s) and the lease is renewed on every checkpoint; a job whose worker died is
  taken over once its lease runs out and resumes from its last checkpoint. So a worker killed with SIGKILL (or by the
  out-of-memory killer, or a crash) leaves its job `running` for up to 300 s after its last checkpoint before another
  worker resumes it; a restart does not shorten that. A job is tried at most three times.
* **Exit codes:** `0` after a clean stop, including SIGTERM or SIGINT when PHP has `pcntl` (the running job checkpoints
  and goes back to the queue); `1` for a bad argument or when the database cannot be reached. Without `pcntl` SIGTERM
  ends the worker at once (`143`), and its job is picked up again when its lease runs out. The image builds `pcntl`.

**In the Docker images.** The entrypoint of both images (nginx + php-fpm, and FrankenPHP) starts
`apotheke-jobs-worker` (`docker/jobs-worker.sh`) next to the web server, as `www-data`, under `tini` as PID 1:

* it runs `php spark --no-header jobs:work` with the defaults above. When that exits 0 (after `--max-seconds`) it is
  started again after 1 s; any other exit (no database yet, a crash, a kill) restarts it after 1, 2, 4 … 60 s, back
  to 1 s after a run of 60 s or more;
* a crash loop never stops the web server, and the web server does not wait for the worker. If the web server itself
  exits (FrankenPHP; nginx or php-fpm), the container stops with that exit status, or 1 if it was 0;
* `docker stop` (SIGTERM) reaches the worker too: the running job checkpoints and goes back to the queue (`pcntl`),
  and the container exits 0;
* logs go to the container's output: `apotheke-jobs: …` lines from the supervisor and one `job <id> (<type>): <status>`
  line per job. Neither prints a payload, the environment or a credential; an unexpected error's class and message
  go to stderr;
* **`APOTHEKE_JOBS_WORKER=0`** (or `false`, `no`) turns it off, for example to run the worker in a separate
  container (`docker compose run -u www-data apotheke php spark jobs:work`). Any value other than empty, `1`, `true`,
  `yes`, `0`, `false` or `no` exits 64.

A separate process was chosen over a FrankenPHP worker script: FrankenPHP's worker mode serves HTTP requests from a
long-lived script and would hold one of the few PHP threads for a loop that serves none, and it has no counterpart
in the nginx image. A plain process under the same entrypoint works the same way in both images and keeps the
worker's crashes, memory and restarts apart from the requests. `scripts/docker-jobs-checks.sh` tests it on both
images: the processes run as `www-data`, a queued job is worked, a killed `jobs:work` comes back after the measured
waits of 1, 2, 4 and 8 s while `/healthz` keeps answering, a clean exit and a run of 60 s or more bring the wait back
to 1 s, `docker stop` exits 0 (also when it arrives while `jobs:work` or the backoff sleep is starting), the web
server dying on its own stops the container with its exit status (137 for a kill), the log never carries
`APP_PASSWORD`, and `APOTHEKE_JOBS_WORKER=0` runs no worker.

Self-hosted images keep the worker on by default; the Cloudflare deploy (`apotheke-api`) turns it off with
`APOTHEKE_JOBS_WORKER=0` in `edge/wrangler.jsonc`.

* **On Cloudflare** (API plan §1A, the edge deployment) the same jobs run from a Cron Trigger or a Queue consumer that
  calls the runner instead of a long-lived `jobs:work` process, designed in P4 with the D1/R2 storage; until then the
  container runs no worker. The `jobs` table and its schema stay as they are: each
  step is one guarded statement with no transaction, which D1 supports.

## Behind a web server or reverse proxy

Rules for any web server in front of PHP-FPM:

* the document root is `public/`;
* every request that is not an existing file in `public/` goes to `public/index.php`;
* no other PHP file is ever executed, and `app/`, `modules/`, `vendor/`, `writable/`, `data/`, `migrations/`, `bin/`, `config.php` and any URL path
  containing `/originals` are not reachable, in any spelling (Docker nginx: `location ~* ^/originals(/|$) { return 404; }`; Apache:
  `public/.htaccess` `RedirectMatch` with `(?i)`, plus `Require all denied` and `Deny from all` inside the originals directory);
* allow a request body of ~22 MB normally and more on `/backup/restore` if you restore through the browser (the
  Docker image allows the larger body only after sign-in; see [Sessions and the restore upload](#sessions-and-the-restore-upload)).

[`docker/nginx.conf`](../docker/nginx.conf) and [`docker/fastcgi.conf`](../docker/fastcgi.conf) are a complete,
tested example (static assets with long-lived caching and gzip, blocked paths, the larger limit and timeouts on
`/backup/restore`, granted through `auth_request`).

### Docker defaults: localhost and sign-in

Two defaults keep a new Docker install private:

* **Localhost only.** `docker-compose.yml` publishes `127.0.0.1:9091:9091`, so only the server itself (and a reverse
  proxy on it) reaches the app. To open it from phones on your network, change the port line on purpose to
  `"9091:9091"` (all interfaces) or `"192.0.2.10:9091:9091"` (one LAN address of the server). With `docker run`, use
  `-p 127.0.0.1:9091:9091` or `-p 9091:9091` in the same way.
* **Sign-in required.** Before it starts nginx and php-fpm, the entrypoint runs `bin/settings.php auth status` as
  `www-data`. That reads the database without migrating or seeding it, and a missing SQLite file stays missing. Like
  the app a moment later, it may create an empty `DB_SCHEMA` that doesn't exist yet and switch an older SQLite file to
  WAL mode. When sign-in would be off, the
  container exits with code 78 and logs `sign-in is off` with both ways out (the `bin/settings.php password` command and `ALLOW_NO_LOGIN=1`). Sign-in counts as on when the stored *Require sign-in*
  setting is on and an account exists, or, on a new database, when `APP_PASSWORD` is set (it creates the account
  `admin` on the first request; it needs at least 8 characters, not only spaces, or the container exits with code 78
  and says `APP_PASSWORD is too short` without showing the value). The check tries for about 30 s while the database
  may still be starting (30 tries, 1 s apart; a PostgreSQL address that doesn't answer makes each try wait 5 s, so up
  to about 3 minutes) and then exits with code 78 too.
  * An install that already has sign-in on in its database starts without `APP_PASSWORD`; the variable is only used
    on a new database.
  * To run without sign-in on purpose, on a trusted network only, set `ALLOW_NO_LOGIN=1`. Any value other than
    `1`, `true`, `yes`, `0`, `false`, `no` or empty exits with code 64.
  * Turning sign-in off in Settings → Users & sign-in (or with `bin/settings.php auth off`) means the next start needs
    `ALLOW_NO_LOGIN=1`.
  * Other commands (`docker compose run --rm -u www-data apotheke php bin/settings.php …`) skip the check, so recovery
    always works.

**Upgrading an install that runs without sign-in:** after the update the container refuses to start. Either turn
sign-in on: run `docker compose run --rm -u www-data apotheke php bin/settings.php password` (it asks for the new
password of `admin`, creates that account if needed and turns sign-in on), then `docker compose up -d`. Setting
`APP_PASSWORD` alone doesn't do this, because the database already says sign-in is off. Or keep running without
sign-in: add `ALLOW_NO_LOGIN=1` to `.env`. If phones or other machines open the app directly, publish the port on your
network again as shown above; a reverse proxy on the same server keeps working with `127.0.0.1:9091`.

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
* the session cookie is always `HttpOnly` and `SameSite=Lax`. On HTTPS it is called `__Host-apothekesid` (`Secure`,
  `Path=/`, no `Domain`, so the browser keeps it to this exact host); on plain HTTP it keeps the name `apothekesid`. For
  this release a session that still carries the old name on HTTPS is kept and moved to the new name;
* `X-Forwarded-For` is read only when `trust_proxy` is on and the connecting peer is listed in `trusted_proxies` /
  `TRUSTED_PROXIES`. The client is the rightmost hop that isn't itself a listed proxy. With `trust_proxy` on and an
  empty list, the connecting address is used. The sign-in limit is 10 attempts per 15 minutes for one account from
  that client, and 30 per 15 minutes for all accounts from one address (an IPv6 client counts by its /64). Everyone
  behind one address shares that cap, so behind a reverse proxy list it in `TRUSTED_PROXIES`; otherwise every client
  has the proxy's address, and 30 failed tries from anyone hold up sign-in for all of them for up to 15 minutes.
  Clients behind one NAT share the cap too. Sessions already signed in are not affected.

### Host names

The app answers only to host names it knows. A request with any other `Host` header gets
**421 Misdirected Request**. Always accepted, with or without a port:

* IP addresses (IPv4, and IPv6 in brackets such as `[fd00::10]:9091`), so opening the app by its LAN address works
  with nothing configured;
* `localhost` (and `*.localhost`), and the machine's own host name (in Docker, the container's host name: the
  container ID unless `hostname:` is set in the compose file);
* the host of the Public URL (Control Panel → General; `PUBLIC_URL` sets it on a new install only);
* every name in `ALLOWED_HOSTS` (comma-separated, for example `ALLOWED_HOSTS=apotheke.lan,nas.lan`; a port in an
  entry is ignored). `*` accepts any name and turns the check off.

If you open the app by a name such as `apotheke.lan` or `nas.local`, add the name to `ALLOWED_HOSTS` or make it the
Public URL. `PUBLIC_URL` only works on a new install: the first start saves it in the database, and from then on an
existing install sets the address under Control Panel → General (Public URL). A reverse proxy must pass the
original `Host` (nginx: `proxy_set_header Host $host;`, which Caddy does by default) or a name on this list.

The health endpoints `/healthz` and `/health` follow the same rule. The image's own `HEALTHCHECK` uses
`127.0.0.1` and keeps working, but a container or orchestrator health check, or an outside monitor, must use an
allowed host: an IP address, `localhost`, the container's host name, the Public URL's host or an `ALLOWED_HOSTS`
entry. A probe that uses any other name gets 421.

With sign-in off (no `APP_PASSWORD` and no accounts; in Docker only with `ALLOW_NO_LOGIN=1`), a request that changes something (anything but GET, HEAD and
OPTIONS) must come from the app's own pages: the browser's `Sec-Fetch-Site` must be `same-origin` or `none`, or
`Origin` must be the app's own address or `PUBLIC_URL`. Requests that send neither header, such as `curl` and
scripts, are not affected. Behind a TLS proxy, set `PUBLIC_URL` to the `https://` address or turn on `trust_proxy`:
otherwise the app expects an `http://` `Origin`, and browsers that send only `Origin` (no `Sec-Fetch-Site`, such as
Safari before 16.4) get 403 on every change.

### Sessions and the restore upload

A session (a small file in `DATA_DIR/sessions`) is created only when a page with a form is shown or someone signs
in. 404 pages, the icons, the manifest and the theme CSS, health checks and anonymous redirects
to the sign-in page don't create one, and a session cookie whose session no longer exists is ignored. The sign-in
page has a form, so each visit to it without a session cookie still starts a session; PHP's session clean-up removes
old session files.

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
   any other answer from the check into 500; the `@restore_check_failed` location answers 421 for an
   unknown host name instead, with the same short text as the app's 421, and nginx
   still logs *auth request unexpected status: 421*.

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
       default_type text/plain;
       charset utf-8;
       add_header Cache-Control no-store always;
       if ($restore_check = 421) { return 421 "Unknown host name. Open the app by its address, or add this name to ALLOWED_HOSTS (or PUBLIC_URL).\n"; }
       return 500;
   }
   ```

3. Run `nginx -t`, then reload nginx.
4. Check it: `curl -s -o /dev/null -w '%{http_code}\n' https://apotheke.example/backup/restore/check` prints `401`
   while signed out (or `204` with sign-in off), a large restore upload while signed out gets `401` at once, and
   one with an unknown `Host` gets `421`.
   [`scripts/docker-auth-checks.sh`](../scripts/docker-auth-checks.sh) runs the full set of checks.

### ImageMagick policy

The Docker image ships a restrictive ImageMagick policy in `/etc/ImageMagick-7/policy.xml` (from [`docker/imagemagick-policy.xml`](../docker/imagemagick-policy.xml)). It caps an image at 16,384 px
wide or high and 128 MP, ImageMagick's memory and map at 256 MiB, its disk cache at 1 GiB and its time at 60
seconds, and it turns off the coders that read text, vector or page formats or fetch URLs, all external delegates
and `@file` arguments. HEIC/HEIF, JPEG, PNG, WebP and GIF photos decode as before. The image build fails if the
policy is not in effect (`scripts/imagick-policy-check.php`: the limits Imagick reports, the refused coders, and
from `magick -list policy` no rights for delegates or `@file` paths, including any narrower rule added later, and a
time limit of 60 s or less).

## The FrankenPHP image

The repository builds two images from the same code, data and settings:

| | `Dockerfile` (default) | `Dockerfile.frankenphp` |
|---|---|---|
| Web server and PHP | nginx + php-fpm, supervised by the entrypoint | FrankenPHP 1.13.4 (Caddy 2 + PHP 8.4 ZTS in one process) |
| Used by | self-hosting (default) | Apotheke on Cloudflare Containers (`edge/`), and self-hosting by choice |
| Size (PHP 8.4.27, podman) | about 146 MB | about 175 MB (+29 MB, +20%) |

Both run `tini` as PID 1 and the same entrypoint checks (sign-in required at start, SEC-M3; exit codes 64 and 78;
the data volume rules, L-430), serve `public/` only, and run the web server and PHP as `www-data` (uid 82). The
FrankenPHP image is a multi-stage build: the PHP extensions are compiled in a build stage on the official
`dunglas/frankenphp` Alpine image, and only the `frankenphp` and `php` binaries, `libphp.so`, the extensions and
their shared libraries are copied onto a plain Alpine base, so it has no compiler, headers, PHP sources, `php-cgi`,
`phpdbg` or `pecl`. Its `HEALTHCHECK` is the same `/healthz` probe. A slimmer base (for example a static
FrankenPHP build) is a possible later step.

**Switching with docker compose.** Set `APOTHEKE_DOCKERFILE=Dockerfile.frankenphp` in `.env` and run
`docker compose up -d --build`. To go back to nginx (the rollback), remove the line, or set it to `Dockerfile`,
and run the same command. The data directory, database, `APP_KEY` and settings are shared; nothing is migrated.
With plain Docker: `docker build -f Dockerfile.frankenphp -t apotheke:frankenphp .`.

**Threads.** `FRANKENPHP_NUM_THREADS` (1–64, default **2**) is the number of PHP threads, i.e. requests served at
once. Two fit Cloudflare's `basic` instance (1/4 vCPU, 1 GiB) with two large HEIC uploads at a time (each can take
about 300 MB while it is decoded). On a bigger server, raise it, for example to 8. A value outside 1–64 exits with
code 64. The nginx image ignores it (php-fpm's own settings apply).

**Request body limits.** Sizes are binary: 1 MiB = 1,048,576 bytes (nginx's `22m` is the same 22 MiB). The same
limits as the nginx image, enforced by Caddy per route:

* **22 MiB on every route.** A request that declares a larger `Content-Length` gets **413** before the body is read,
  signed in or not; a body without a length (chunked) gets **413** when it passes 22 MiB.
* **520 MiB only on `POST /backup/restore`** (exactly that path: not `/backup/restore/`, not another case, not
  another method), **and only after sign-in.** Caddy first asks the app (`/backup/restore/check`, the same as
  nginx's `auth_request`); a request that is not signed in with the Backup permission gets the app's answer (401,
  403 or 421) before the body is read, and only then is a declared size over 520 MiB a 413.

FrankenPHP has one PHP configuration, so PHP's own `upload_max_filesize` and `post_max_size` are the restore values
(512M and 520M, `docker/frankenphp/php-frankenphp.ini`); the caps above are what keeps every other route at 22 MiB.
The per-photo and per-attachment limits in the Control Panel still apply. `scripts/docker-auth-checks.sh` signs in
and checks that a 30 MiB POST to other routes (`/save`, photo and attachment uploads, the logo, the import,
`/backup/restore/confirm`, the API, an unknown path, `/Backup/restore`) gets 413, on both images.

**Slow uploads (M-1420).** FrankenPHP hands a request body to PHP as it arrives, so a client that uploads slowly
would hold one of the few PHP threads for as long as it likes, and two of them could stop even `/healthz`. The
Caddyfile therefore runs two servers in one process: the public one on `PORT` applies all the rules above and
passes the request to the PHP server (a unix socket under `/run`, unreachable from outside) through a reverse proxy
that first reads up to **64 KiB** of the body. That is everything a signed-out client can send (the sign-in form,
`POST /api/v1/session`), so a slow anonymous client holds a Caddy goroutine and at most 64 KiB, never a PHP thread.
A body over 64 KiB (any method), or a request that may have a body but declares no length, is only read after the
app said the client is signed in (or sign-in is off): Caddy asks `GET /upload/check` (204 or 401, no session, no
body) first. A `GET`, `HEAD` or `OPTIONS` request with a chunked body (no declared length) gets **411** before
PHP sees it, whoever sends it (M-1451); Go's server may still read and drop up to 256 KiB of that body in Caddy to
keep the connection open. Both servers speak HTTP/1.1 only (`protocols h1`, as nginx): no cleartext HTTP/2 (h2c),
whose bodies carry no `Transfer-Encoding` and would otherwise get past these rules (M-1454). Headers have 10 s (`read_header`), and a body 300 s (`read_body`, for a
large restore on a slow line). PHP sees the client's address, port and own `X-Forwarded-*` headers as before, on
the request itself and on both sign-in checks (`/upload/check`, `/backup/restore/check`): all three hops share one
header block (`php_hop`), so behind an HTTPS proxy with `trust_proxy` on the checks see the `__Host-` session cookie
too (M-1450). `scripts/docker-checks.sh` checks that HTTP/2 with prior knowledge fails and an h2c upgrade stays on HTTP/1.1,
keeps four slow uploads, then four slow chunked `GET`s, in flight (more than
the two threads) and checks that `/healthz`, the sign-in page and a form post still answer within 2 s, on both
images. `scripts/docker-auth-checks.sh` with `CONTAINER=` turns `trust_proxy` on and checks a signed-in 300 KB
upload and restore behind `X-Forwarded-Proto: https` (and that signed-out ones are still refused).

**Access log.** JSON on stdout, one line per request, without the request's `Cookie`, `Authorization`,
`Proxy-Authorization`, `X-CSRF-Token` and `X-Apotheke-Proxy` (the edge proxy's shared secret) headers, or the
response's `Set-Cookie` (L-1422). `scripts/docker-log-checks.sh` sends marker values in those headers and checks
that none reaches the log, on both images.

**Differences from the nginx image** (none changes security headers, blocked paths or status codes of the app):

* no `Server` header (nginx sends `Server: nginx` without a version);
* a blocked path traversal such as `/assets/../../app/Config/App.php` gets 404 instead of 400; neither serves a file;
* CSS and JavaScript are sent as `text/css; charset=utf-8` and `text/javascript; charset=utf-8`;
* responses under 512 bytes and `HEAD` answers are not gzipped;
* errors produced by the web server itself (413, a missing asset) are a plain-text status line;
* the access log is JSON on stdout (nginx: the combined format), without secret headers;
* a declared restore size over 520 MiB is a 401 before sign-in (nginx: 413, it checks the size first).

## Cloudflare deploys (getapotheke.com)

The hosted Apotheke runs on Cloudflare Workers. Self-hosting doesn't need any of this; to run a copy on your own
Cloudflare account, see [Self-hosting on Cloudflare](self-host-cloudflare.md). Two Workers are deployed from
this repository, and only these two:

| Worker | Code | Serves | Workflow | GitHub environment |
|---|---|---|---|---|
| `getapotheke-app` | `edge/app/` (routing and headers) + `frontend/dist` (static assets) | the landing page (`/`, `/privacy/`, `/404.html`, `robots.txt`, `sitemap.xml`, icons, `site.webmanifest`, `/assets/…`) and the SPA under `/app/` | `.github/workflows/deploy-frontend.yml` | `deploy-site` |
| `apotheke-api` | `edge/` (Worker + Container Durable Object) + `Dockerfile.frankenphp` | the API (Cloudflare Containers, instance `basic`, 1 instance, sleeps after 15 minutes) | `.github/workflows/deploy-edge.yml` | `deploy-edge` |

**`apotheke-api` settings** are vars in `edge/wrangler.jsonc` (not secrets; changed by a reviewed pull request) that
the Worker passes to the container: `SLEEP_AFTER_MS`, `ALLOWED_HOSTS` (`api.services.getapotheke.com`),
`APOTHEKE_JOBS_WORKER` (`0`: no `jobs:work` in the container; jobs will run through a Cron Trigger or Queues, see
[Background jobs](#background-jobs)), `CORS_ALLOWED_ORIGINS` (`https://getapotheke.com`: the front end calls the API
from another host) and `COOKIE_DOMAIN` (`getapotheke.com`). `COOKIE_DOMAIN` is the hosted value from
the 3.6.1 upgrade note: the consent cookie gets `Domain=getapotheke.com` (empty means host-only, the self-hosting
default), and it never applies to the `__Host-apothekesid` session cookie, which may not carry a `Domain` attribute
(see [Cookie consent](#cookie-consent)). The secrets `APP_PASSWORD` and `APP_KEY` are set separately (below).

**When they deploy.** Only for a published GitHub Release that is not marked pre-release (tag `vX.Y.Z`, created
by `release.yml`), or a manual *Run workflow* on `main` or a `vX.Y.Z` tag. The deploy job's first step fails on any
other tag (only `v` and three numbers, no suffix such as `-rc.1`), and on a tag it also reads that tag's GitHub Release
with the job's read-only token: it fails unless the release exists and is neither a draft nor a pre-release (the
release-by-tag endpoint returns only published releases, so a draft fails like a missing release). `main` needs no release. One deploy per environment runs at a time
(concurrency group `deploy-site` or `deploy-edge`, across refs); a newer one waits, and a running deploy is never
cancelled. Every install in these workflows (pull request, test and deploy jobs) is `npm ci --ignore-scripts`: no package
install script runs where the token is, and a dependency that needs one fails on the pull request already. Pull requests that touch them run the build, the Worker guard, the typecheck
and `wrangler deploy --dry-run` (which, for `apotheke-api`, builds the container image), with no secret and no
preview. The deploy is not a release check. Routes and custom domains are never in the wrangler configs CI uses
(`workers_dev` and `preview_urls` are off); the custom domains are attached in the Cloudflare dashboard.

**Secrets and variables** (set by the repository owner, never in the repository):

* in each environment, the secret `CLOUDFLARE_API_TOKEN` and the variable `CLOUDFLARE_ACCOUNT_ID`;
* both environments are limited to `main` and tags `v*`; `deploy-edge` also has a required reviewer;
* without the secret the deploy job prints a notice, skips its steps and stays green. Fork pull requests never get
  secrets and never reach the deploy job;
* the account id is masked in the logs (it is a variable, but it appears in container registry paths), and the token
  is only passed in the environment of the deploy step.

**Token scope.** The tokens are account-wide: `deploy-site` has *Account > Workers Scripts > Edit*; `deploy-edge` has
*Workers Scripts > Edit* and *Account > Containers > Edit*. Neither has Zone permissions, and CI makes no Zone API
calls; after the P4 storage flip, `deploy-edge` also needs *D1 > Edit* and *Workers R2 Storage > Edit*
([flip checklist](#p4-edge-storage-flip-checklist)). Account-wide means such a token could edit **any** Worker in the account, including ones this
repository must never touch (for example `apotheke-autofill`). So:

* each workflow hard-codes its Worker name (`getapotheke-app` or `apotheke-api`); no name comes from inputs,
  variables or files outside review;
* `scripts/cf-deploy.sh` and every `wrangler` call in the workflows pass `--config wrangler.jsonc` explicitly;
* before every deploy and dry run, `scripts/cf-worker-guard.mjs` reads the effective name from the wrangler config and
  fails the job unless it is exactly the workflow's Worker and on the allowlist (`getapotheke-app`, `apotheke-api`).
  It also fails on any other name (such as `apotheke-autofill` or `flaremo`), on `env` sections, routes or custom
  domains in the config, on `--name`, `--env`, `--route`, `--domain` or `--config` arguments, and on
  `CLOUDFLARE_ENV`/`WRANGLER_ENV`. It also fails when another config could win over that file: a `wrangler.json`
  or `wrangler.toml` next to it, or a `.wrangler/deploy/config.json` redirect in its directory or any directory
  above;
* changing the allowlist means changing that file, which needs a reviewed pull request.

When Cloudflare offers per-Worker roles (or token resources scoped to one Worker) for the account, switch each token
to its one Worker; the guard then stays as a second line of defence.

**When a deploy fails,** `scripts/cf-deploy.sh` names the likely cause: an authentication error or 403 names the
permission the environment's token probably lacks; a missing container push permission names *Containers > Edit*.
`apotheke-api` needs its two secrets set once on the Worker, outside CI; see
[One-time manual step before the first edge deploy](#one-time-manual-step-before-the-first-edge-deploy).

### Storage gate: EDGE_STORAGE_READY

The first `apotheke-api` deploy waits for the storage design (P4: D1 for data, R2 for files). Until then the deploy
job of `deploy-edge.yml` does not run, on any trigger (release and manual), unless the **repository variable**
`EDGE_STORAGE_READY` is exactly `true`:

* `EDGE_STORAGE_READY` unset or anything other than `true`: the deploy job is **skipped** by its own condition, before
  the `deploy-edge` environment gate, so a release asks no one for a deploy review and the run stays green;
* `EDGE_STORAGE_READY=true`, but no `CLOUDFLARE_API_TOKEN` in `deploy-edge`: the job waits for the environment review,
  then skips the deploy and stays green;
* `EDGE_STORAGE_READY=true` and a token: after the review, the deploy runs.

The job also checks the variable again in its first steps, before any wrangler call, and fails with *apotheke-api is
stateless until P4 storage (D1/R2) lands; set EDGE_STORAGE_READY=true only after that* if it is not `true` (a second
line of defence, should the job condition ever change).

Set it (Settings > Secrets and variables > Actions > Variables) only once storage has landed, following the
[flip checklist](#p4-edge-storage-flip-checklist). The pull request checks
and `deploy-frontend.yml` are not affected.

### P4 edge storage: flip checklist

The P4 storage for `apotheke-api` is prepared but off. Its bindings are in `edge/storage.jsonc`, not in
`edge/wrangler.jsonc`, so the pull request dry run, `wrangler dev` and the *Edge local test* use the default config
unchanged. Only the deploy job, which runs only with `EDGE_STORAGE_READY=true`, merges them in
(`scripts/edge-storage-config.mjs`, on the runner's checkout), deploys, and then checks `/healthz`:

| Binding | Resource | Notes |
|---|---|---|
| `DB` | D1 database `apotheke-api` | EU jurisdiction; `database_id` from the repository variable `APOTHEKE_D1_DATABASE_ID` |
| `STORAGE` | R2 bucket `apotheke-api-storage` | `"jurisdiction": "eu"` |
| `INSTALLATION` | Durable Object class `Installation`, SQLite storage | hosted mode; migration `v2` (`new_sqlite_classes`) |

Hosted mode keeps every cookie host-only (ADR-002, decision 5, [#203](https://github.com/thordin9/apotheke/pull/203)), so the merge also drops
`COOKIE_DOMAIN` from the flipped config: the container gets no cookie domain. The default config keeps
`getapotheke.com` for the stateless API.

The deploy runs no `wrangler d1 migrations apply`: the app's own migration runner applies `migrations/` through the
bridge. A control-plane D1 schema would later go in `edge/migrations/control/`, set as the binding's `migrations_dir`.

Flip in this order. Every step except the last two is additive; nothing is deleted.

1. **Pre-flip: the storage gate criteria hold.** Don't start until items 1 to 4 of *Opening the storage gate* in
   ADR-002 (`docs/adr/002-edge-storage.md`, added in [#203](https://github.com/thordin9/apotheke/pull/203)) are met:
   1. P4-2 to P4-5 are merged (so `edge/src/index.ts` exports `Installation`; until then a deploy with the merged
      config fails on *Durable Objects not exported*, and nothing is deployed);
   2. the manual `edge-local` workflow is green with D1 and R2 (local), including a backup round trip between
      SQLite on Docker and the edge;
   3. a preview D1 and R2 pass the smoke test and the guard cases (all-or-nothing batches, `expect` and `assert`
      aborts, concurrent guarded adds, 150 `Ref`s, 101 parameters refused);
   4. `edge/src` creates every `Installation` id through `env.INSTALLATION.jurisdiction("eu")`: the test for this is
      green, and on the preview an `Installation` object is checked to be in the EU jurisdiction before any real
      installation is created. **This can't be corrected after the ids exist.**

   The ADR's item 5 (the one-time steps) is the rest of this checklist.
2. **Token for the one-time steps** (the operator's own, from a trusted machine, not CI): *Account > D1 > Edit*,
   *Account > Workers R2 Storage > Edit* and *Account > Workers Scripts > Edit*, on this account only, ideally with an
   IP filter and a TTL. In the environment only:
   `read -rs CLOUDFLARE_API_TOKEN && export CLOUDFLARE_API_TOKEN` and `export CLOUDFLARE_ACCOUNT_ID=<account-id>`.
3. **Create D1 and R2 in the EU jurisdiction.** The jurisdiction can't be changed after creation, for either. With a
   jurisdiction, a D1 location hint (`--location weur`) is ignored, so don't pass one. In `edge/` (the pinned wrangler):

   ```sh
   npx --no-install wrangler d1 create apotheke-api --jurisdiction eu
   npx --no-install wrangler r2 bucket create apotheke-api-storage --jurisdiction eu
   npx --no-install wrangler d1 info apotheke-api                 # the database UUID
   npx --no-install wrangler r2 bucket list --jurisdiction eu     # apotheke-api-storage is listed
   ```

   Don't pass `--update-config`: the ids stay out of the repository.
4. **Repository variable** (Settings > Secrets and variables > Actions > Variables, or
   `gh variable set APOTHEKE_D1_DATABASE_ID --body <database-uuid>`). It is an identifier, not a secret; the deploy
   job fails before wrangler if it is missing or not a UUID.
5. **`deploy-edge` token**: besides *Workers Scripts > Edit* and *Containers > Edit*, give the environment's
   `CLOUDFLARE_API_TOKEN` *D1 > Edit* and *Workers R2 Storage > Edit*, so the deploy can bind the database and the
   bucket.
6. **Worker secrets and domain**: `APP_KEY` and `APP_PASSWORD` set once at go-live
   ([One-time manual step](#one-time-manual-step-before-the-first-edge-deploy)), and the custom domain for the first
   host in `ALLOWED_HOSTS` attached, since the smoke check calls it.
7. **Migrations**: nothing to run by hand; the app applies `migrations/` itself on start.
8. **Flip**: `gh variable set EDGE_STORAGE_READY --body true`, then publish a release or run *Deploy edge* on `main`
   and approve the `deploy-edge` review.
9. **Verify**: the job's *Storage config* step names the merged bindings, the deploy log lists `env.DB`,
   `env.STORAGE (apotheke-api-storage (eu))` and `env.INSTALLATION`, and *Smoke check* gets 200 from
   `https://<api-host>/healthz`. Then sign in, save an item, wait past `SLEEP_AFTER_MS` (or deploy again) and check
   that it is still there.
10. **Rollback**: `gh variable set EDGE_STORAGE_READY --body false` stops all further edge deploys (the job is
    skipped again). The deployed version keeps running until you replace it: `npx --no-install wrangler deployments
    list --name apotheke-api`, then `npx --no-install wrangler rollback <version-id> --name apotheke-api`. Cloudflare
    refuses a rollback across a Durable Object migration, so a version from before `v2` can't come back; roll back to
    an earlier storage version or fix forward. Nothing is deleted: the D1 database, the bucket and the Durable Object
    data stay. Deleting any of them (or a `deleted_classes` migration) needs the owner's approval.

### One-time manual step before the first edge deploy

Do this at the first real edge deploy, after the storage gate above is opened, not before. Until
`EDGE_STORAGE_READY` is `true` the deploy job is skipped (no review is requested), so there is nothing to do yet.
`edge/wrangler.jsonc` lists `APP_PASSWORD` and `APP_KEY` under `secrets.required`, so `wrangler deploy` of
`apotheke-api` fails until both exist on the Worker. CI never sets them: the deploy token is not used for secrets, and
the values never go through GitHub. The operator sets them once, from a trusted machine, with a token that can edit
the Worker (Account > Workers Scripts > Edit). The Worker passes both to the container as environment variables on
every container start (`edge/src/index.ts`).

`wrangler secret put` reads the value from standard input, so it never appears in the command line, shell history or
logs. Run these in `edge/` so wrangler uses that config, and keep `--name apotheke-api` so the target is explicit:

```sh
# APP_KEY: 32 random bytes, base64 (44 characters). Apotheke's SecretKey accepts base64 of exactly 32 bytes, with an
# optional "base64:" prefix; anything else is refused as "APP_KEY is not a 32-byte key". It encrypts stored secrets
# (libsodium secretbox), so keep a copy somewhere safe: without it they can't be decrypted.
umask 077
mkdir -p <private-dir>
openssl rand -base64 32 > <private-dir>/apotheke-api-app-key
npx wrangler secret put APP_KEY --name apotheke-api < <private-dir>/apotheke-api-app-key

# APP_PASSWORD: chosen by the operator, at least 8 characters, not only spaces, no null character. Typed at the prompt
# (wrangler asks for the value), or from a private file the same way as APP_KEY.
npx wrangler secret put APP_PASSWORD --name apotheke-api

npx wrangler secret list --name apotheke-api   # names only; values are never shown
```

**Data does not persist yet.** A Cloudflare Containers instance has no persistent disk: `/data` starts empty on every
cold start (after `sleepAfter`, a deploy or a restart). Each start is therefore a new install, where `APP_PASSWORD`
creates the account `admin` again and anything saved before is gone. Until storage is designed, treat `apotheke-api`
as stateless.

Never print, paste or commit either value, and never put them in `wrangler.jsonc`, `vars` or a GitHub secret.
If the Worker does not exist yet, `wrangler secret put` offers to create a draft Worker; answer no and deploy first
(the deploy then fails only on the missing secrets), or set them with the Cloudflare API's Worker secrets endpoint.

**getapotheke-app build.** The job checks out the full history with tags (`fetch-depth: 0`), so the build can read
the latest release tag and its date rather than the build date.

**getapotheke-app routing and headers.** The static assets serve the landing page and its files directly, with
the headers in `frontend/public/_headers` (copied to `dist/_headers`); the landing page's CSP is set there only.
Cloudflare does not apply `_headers` to responses a Worker makes, so only the paths in `run_worker_first`
(`edge/app/wrangler.jsonc`) go through `edge/app/src/index.ts`, which sets the same headers itself:

* `/app` answers 301 to `/app/`. Under `/app/`, an existing file is served; any other path without a file extension
  gets `/app/index.html` (the SPA's client-side routes), a missing file with an extension is a 404. Outside `/app/`
  there is no SPA fallback: a missing page is a 404 with `404.html`;
* `/config.json` is answered by the Worker from its non-secret var `API_BASE`
  (`{"apiBase": "https://api.services.getapotheke.com/api/v1"}`), with `Cache-Control: no-cache`;
* `/fonts/*` (unhashed) get one day (`max-age=86400`), like `/assets/brand/*` and `/assets/mascot/*` in `_headers`,
  on a 200 and on a 304 (a revalidation keeps the same `Cache-Control`). Everything else the Worker answers is
  `no-cache` (its 404s too), and its other errors `no-store`. Hashed Vite files (`/assets/:name-:hash.js` and
  `/assets/:name-:hash.css`) are served from the assets with `_headers` (immutable for a year), not by the Worker. A path
  under `/assets/` that does not match, including a missing file such as `/assets/missing.js`, keeps `no-cache`
  from `/*`. A missing hyphenated name such as `/assets/index-abc.js` still matches and is an immutable 404; hashed names are not reused;
* every response the Worker makes (the SPA fallback, the `/app` redirect, its 404, 405 and 500 answers) carries the `/*` headers of `_headers` (HSTS, nosniff, `X-Frame-Options`, `Referrer-Policy`,
  `Permissions-Policy`), and under `/app/` exactly its `/app/*` Content-Security-Policy. When `_headers` changes
  `/*` or `/app/*`, change `COMMON` or `APP_CSP` in the Worker too; the check below fails until they match. A change to
  the landing CSP needs `_headers` only.

`scripts/app-worker-check.mjs` checks a running `wrangler dev` of the Worker against `frontend/dist`: the routing
above, and that the Worker's headers match `dist/_headers`. Without `dist/_headers` it fails at once (only the
workflow's stub dist, before there was a frontend build, sets `STUB_DIST=1`). The pull request job runs it on every
change. `scripts/deploy-scripts.test.mjs` (`node --test`, no network) tests the Worker with a fake assets binding
(the headers on its own 500, 405 and 301, the caching of 200 and 304), the drift check without `_headers`, the guard
and `cf-deploy.sh`; both workflows run it. `tests/Unit/DeployWorkflowsTest.php` checks the workflows themselves
(triggers and deploy conditions, permissions, checkouts, the token and storage gates, concurrency, the wrangler pin).

**Local checks** without an account: `cd edge && npm ci && npx wrangler dev` (needs Docker for the container, and
`.dev.vars` from `.dev.vars.example`); the manual workflow *Edge local test* does the same on a GitHub runner.

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

When the database refuses the connection, `bin/migrate.php`, `bin/seed.php`, `bin/backup.php`, `bin/restore.php`
and `bin/settings.php` exit 1 with `Could not connect to the database` and only the driver, host, port, dbname and
`DB_SCHEMA` (SQLite: the file path); `/healthz` logs the same line. While the database is down every route returns
500. The database user, the password, the DSN and the server's own error text are never printed or logged.
`bin/migrate.php` (through `apotheke:migrate`) prints the same banner and the same line, and `/healthz` and
`/health` answer the same 500 with an empty body.

## Security

* **Headers** on every response from the app: `Content-Security-Policy`, `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: same-origin`. nginx hides its version and PHP's `X-Powered-By`.
* **CSP:**

  ```
  default-src 'self'; connect-src 'self' blob:; img-src 'self' blob: data:; style-src 'self'; script-src 'self';
  object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'
  ```

  No inline scripts, no inline styles, no third-party hosts. `blob:` is needed for client-side photo shrinking.
* **Cookie consent:** see [Cookie consent](#cookie-consent) below: no cookie at all until the user accepts.
* **CSRF:** every state-changing request (forms and fetch calls, including bulk actions, lending and duplicating)
  needs the per-session token; an expired token shows a friendly page (status 419) and changes nothing.
* **SQL:** prepared statements everywhere; dynamic parts (sort columns, table names in backups) come from fixed
  allow-lists.
* **Output:** every value is HTML-escaped (`h()`).
* **Uploads:** the camera file is kept byte-identical under `originals/` with a 64-hex name that is not the served
  name; a guessed `/originals/…` URL is 404 in any spelling. Everyone sees a stripped JPEG (EXIF/GPS dropped,
  orientation applied, transparency on white, CMYK as sRGB) served only through `/media/(full|thumb)/[a-f0-9]{32}.jpg`.
  Oversized frames are refused from the header before decoding, and Imagick runs with the same resource limits as the
  [ImageMagick policy](#imagemagick-policy); the per-format limits are in the [user guide](user-guide.md#photos).
* **Attachments** are checked by their magic bytes, stored under random names in `data/files/` and served only as
  downloads (`Content-Disposition: attachment`, `nosniff`, a `sandbox` CSP). Uploaded SVG logos are sanitized and
  served with a sandbox CSP.
* **Backups:** strict entry-name, size and schema validation before anything is written (see above).
* **Password:** `APP_PASSWORD` is compared in constant time; a wrong password costs 0.4 s; the session id is
  regenerated on sign-in and sign-out.
* **Host names:** only known host names are answered (421 otherwise), and with sign-in off a state-changing
  request must come from the app's own pages; see [Host names](#host-names).
* **HSTS:** the app does not send `Strict-Transport-Security` itself, because it often runs on plain HTTP on a LAN.
  Add it in your TLS proxy (see the Caddy and nginx examples above).
* **Stored secrets:** with `APP_KEY` set, recoverable secrets (the autofill access token) are encrypted in the
  database; a configured key that cannot be used refuses secret saves, and with no key they stay plaintext. The
  database file and the upload directories are not encrypted by Apotheke: encrypt the disk or volume that holds
  `DATA_DIR` (and the PostgreSQL volume). Keep the key out of the data volume and its backups; a lost key means the
  secrets count as unset. See [Stored secrets](configuration.md#stored-secrets).
* **Docker defaults:** compose publishes the app on `127.0.0.1` only, and the container refuses to start with sign-in
  off unless `ALLOW_NO_LOGIN=1`; see [Docker defaults: localhost and sign-in](#docker-defaults-localhost-and-sign-in).
* Apotheke is meant for a trusted network. If you expose it to the internet, use HTTPS and keep sign-in on.

### Cookie consent

From 3.4 the app sets no cookie in the browser until the user clicks Accept on the consent banner (the hosted SPA's
banner, or the notice the app's own pages show at the top until then). The contract, kept in `app/Config/Consent.php`:

* **The cookie:** `apotheke_consent=v1`, set by the banner script, never by the server: `Path=/`,
  `Max-Age=15552000` (180 days), `SameSite=Lax`, `Secure` on HTTPS, not `HttpOnly`. It is host-only unless
  `COOKIE_DOMAIN` is set (below). It is the first cookie the app ever has.
* **What counts:** the server compares only the name and the exact value (attributes never reach it), so both
  forms count. Any other value (`v0`, `1`, empty, another case) is no consent and gets exactly the same answer as no
  cookie. A later banner text adds its version (`v2`) to `Consent::ACCEPTED`. There is no header form.
* **Without consent:** no `Set-Cookie` on any answer but `POST /consent`'s (no session cookie, no CSRF token, no `theme`,
  `stats_open` or recently-viewed cookie, and the page scripts write none), redirects and 404, 419 and 500 pages included; no session is loaded or started, so an old
  session cookie is not used until consent is back. Reads that need no session still answer. Every POST, PUT,
  PATCH and DELETE (sign-in, sign-up, sign-out, forms, `/api` writes) is `403`: under `/api` problem+json with
  `"code": "consent_required"` and `"error": "consent_required"`, for a fetch from the pages
  `{"ok": false, "error": "…", "code": "consent_required"}`, otherwise the error page with the notice. A
  `Set-Cookie` that reaches the answer anyway is removed (`Core\Filters\ConsentFilter`).
* **With consent** everything is as before.
* **Without JavaScript** the notice's Accept is a form: `POST /consent` with `consent=accept` and `return` (the
  page's path). It needs no token but must be strictly same-origin (`Sec-Fetch-Site: same-origin`, or this
  server's own `Origin`; anything else is `403`), sets only the consent cookie (`Secure` on HTTPS, `Domain` as
  above) and answers `303` to `return` when that is a path on this site, else to `/`. Another `consent` value
  is `400`. There is no decline button: ignoring the notice keeps the browser cookieless.
* **`COOKIE_CONSENT`** (environment): `required` (the default) or `off`. With `off` the app behaves as before
  3.4: cookies from the first request, no notice, no `403 consent_required`. Any other value counts as
  `required`. In Docker set it in `.env`.
* **`COOKIE_DOMAIN`** (environment, empty by default: host-only): the parent domain the consent cookie is set
  for, so it also reaches sibling hosts (hosted installs: `COOKIE_DOMAIN=getapotheke.com`, so the cookie also
  reaches `api.services.getapotheke.com`). It is lower-cased and a leading dot is dropped, and it is used only when
  the request's host is that domain or ends with `.` and it; on any other host the cookie stays host-only (no
  error). An IP address, a single label or bare TLD (`com`), or a value with a port, a path or a wildcard is
  ignored. It never applies to the session cookie, which stays host-only (`__Host-apothekesid` on HTTPS has no
  `Domain`). The app passes the same domain to the notice's script. Only the request's `Host` header is compared,
  never `X-Forwarded-Host`.
  Set it to a registrable domain you control (`getapotheke.com`, `example.org`), never a public suffix such as
  `co.uk` or `github.io`: browsers drop a cookie whose Domain is a public suffix, and the broader the value, the
  more sibling hosts share the consent.
* Answers without a cookie get `Cache-Control: no-store` when they set no caching of their own (no session sends
  its no-cache headers). 2.6.x (branch `old`) has no banner and stays security-only.

## Monitoring and logs

* `GET /healthz` returns `ok` after connecting to the database (the Docker `HEALTHCHECK` uses it) and sends the running
  version in `X-Apotheke-Version`. A missing PostgreSQL schema that this role cannot create is an error, not `ok`.
* `GET /health` returns `{"status":"ok","version":"X.Y.Z"}` (JSON, no login needed; `version` is the running release from `VERSION`) for monitoring tools.
* Both health endpoints are behind the [Host check](#host-names): a probe must use an IP address, `localhost`, the
  container's host name, the Public URL's host or an `ALLOWED_HOSTS` entry, or it gets 421.
* In Docker, nginx and php-fpm log to the container output: `docker compose logs -f apotheke`. Unexpected exceptions
  are logged as `[apotheke] …` and shown to the user as a generic error page.
* Disk usage is mostly `data/originals` (about the size of each camera file), plus the smaller stripped JPEG in
  `data/images` and the thumbnail in `data/thumbs`.
