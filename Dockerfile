FROM php:8.3-fpm-alpine

LABEL org.opencontainers.image.title="Apotheke" \
      org.opencontainers.image.description="Apotheke, the storehouse for your stuff: a self-hosted PHP catalog for your belongings (nginx + php-fpm)" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.authors="Stathis Papandreou"

# GD (JPEG/PNG/WebP), Imagick with HEIC/HEIF (libheif), EXIF, OPcache, pdo_pgsql, zip.
# pdo_sqlite ships with the official image. nginx serves public/, tini is PID 1 (signals, zombies).
# Installs without this image stay on GD: HEIC is kept and shown as a placeholder.
# Imagick is built from its GitHub release tag, pinned by sha256 and fetched with retries,
# instead of `pecl install`, so a pecl.php.net outage can't break the build.
ARG IMAGICK_VERSION=3.8.0
ARG IMAGICK_SHA256=a964e54a441392577f195d91da56e0b3cf30c32e6d60d0531a355b37bb1e1a59
RUN set -eux; \
    apk add --no-cache nginx tini libjpeg-turbo libpng libwebp libzip libpq imagemagick libheif libgomp; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev postgresql-dev imagemagick-dev libheif-dev; \
    docker-php-source extract; \
    curl -fsSL --retry 5 --retry-all-errors --retry-delay 5 --connect-timeout 20 \
        -o /tmp/imagick.tar.gz "https://github.com/Imagick/imagick/archive/refs/tags/${IMAGICK_VERSION}.tar.gz"; \
    echo "${IMAGICK_SHA256}  /tmp/imagick.tar.gz" | sha256sum -c -; \
    mkdir -p /usr/src/php/ext/imagick; \
    tar -xzf /tmp/imagick.tar.gz -C /usr/src/php/ext/imagick --strip-components=1; \
    rm /tmp/imagick.tar.gz; \
    sed -i "s/@PACKAGE_VERSION@/${IMAGICK_VERSION}/" /usr/src/php/ext/imagick/php_imagick.h; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd exif opcache pdo_pgsql zip imagick; \
    docker-php-source delete; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear /usr/local/etc/php-fpm.d/www.conf.default; \
    php -m | grep -qi pdo_sqlite; \
    php -m | grep -qi imagick; \
    php -r "exit(phpversion('imagick') === '${IMAGICK_VERSION}' ? 0 : 1);"; \
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
