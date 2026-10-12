#!/bin/sh
set -e
# Make sure the data volume layout exists and is writable by Apache (www-data),
# even when ./data was created on the host by root.
DATA_DIR="${DATA_DIR:-/data}"
mkdir -p "$DATA_DIR/images" "$DATA_DIR/thumbs" "$DATA_DIR/sessions"
find "$DATA_DIR" \! -user www-data -exec chown www-data:www-data {} + 2>/dev/null || true
chmod 0770 "$DATA_DIR/sessions" 2>/dev/null || true
# Pass APP_PASSWORD / DATA_DIR / TZ through to PHP under Apache.
exec docker-php-entrypoint "$@"
