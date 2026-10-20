# Default stays the shipped 2.5.x image. CI passes PHP_VERSION=8.4 for the test image.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-fpm-alpine

LABEL org.opencontainers.image.title="Apotheke" \
      org.opencontainers.image.description="Apotheke, the storehouse for your stuff: a self-hosted PHP catalog for your belongings (nginx + php-fpm)" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.authors="Stathis Papandreou"

# GD (JPEG/PNG/WebP), Imagick with HEIC/HEIF (libheif), EXIF, OPcache, pdo_pgsql, zip.
# pdo_sqlite ships with the official image. nginx serves public/, tini is PID 1 (signals, zombies).
# Installs without this image stay on GD: HEIC is kept and shown as a placeholder.
RUN set -eux; \
    apk add --no-cache nginx tini libjpeg-turbo libpng libwebp libzip libpq imagemagick libheif libgomp; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev postgresql-dev imagemagick-dev libheif-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd exif opcache pdo_pgsql zip; \
    pecl install imagick-3.8.0; \
    docker-php-ext-enable imagick; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear /usr/local/etc/php-fpm.d/www.conf.default; \
    php -m | grep -qi pdo_sqlite; \
    php -m | grep -qi imagick; \
    php -r 'exit(function_exists("imagewebp") && function_exists("imagecreatefromjpeg") ? 0 : 1);'; \
    php -r 'exit(in_array("HEIC", array_map("strtoupper", Imagick::queryFormats()), true) ? 0 : 1);'

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-apotheke.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zzz-apotheke.conf
COPY docker/nginx.conf /etc/nginx/apotheke.conf.template
COPY docker/fastcgi.conf /etc/nginx/apotheke-fastcgi.conf
COPY docker/entrypoint.sh /usr/local/bin/apotheke-entrypoint
RUN chmod +x /usr/local/bin/apotheke-entrypoint

COPY --chown=root:root public /var/www/app/public
COPY --chown=root:root src /var/www/app/src
COPY --chown=root:root migrations /var/www/app/migrations
COPY --chown=root:root bin /var/www/app/bin
COPY --chown=root:root config.example.php VERSION /var/www/app/

ENV DATA_DIR=/data \
    APP_PASSWORD="" \
    TZ=UTC \
    PORT=9091

WORKDIR /var/www/app
VOLUME ["/data"]
EXPOSE 9091
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s CMD wget -q -O /dev/null "http://127.0.0.1:${PORT}/healthz" || exit 1

ENTRYPOINT ["/sbin/tini", "--", "apotheke-entrypoint"]
CMD ["apotheke"]
