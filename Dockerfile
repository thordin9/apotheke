# Default is the shipped image. Bump this patch pin on each PHP security release.
# CI's Docker job passes the same exact PHP_VERSION build-arg (see .github/workflows/ci.yml).
# The CI4 app (APOTHEKE_APP=ci4) needs PHP 8.4; the entrypoint refuses it on an older image.
ARG PHP_VERSION=8.4.27

# CI4's Composer packages without dev dependencies, and its optimised autoloader. intl is checked in the image below.
FROM composer:2.10.3 AS ci4-vendor
WORKDIR /ci4
COPY ci4/composer.json ci4/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader --ignore-platform-req=ext-intl
COPY ci4/app ./app
COPY ci4/modules ./modules
RUN composer dump-autoload --no-dev --optimize --ignore-platform-req=ext-intl

FROM php:${PHP_VERSION}-fpm-alpine

LABEL org.opencontainers.image.title="Apotheke" \
      org.opencontainers.image.description="Apotheke, the storehouse for your stuff: a self-hosted PHP catalog for your belongings (nginx + php-fpm)" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.authors="Stathis Papandreou"

# GD (JPEG/PNG/WebP), Imagick with HEIC/HEIF (libheif), EXIF, OPcache, pdo_pgsql, zip, pgsql and intl (CI4).
# pdo_sqlite ships with the official image. nginx serves public/, tini is PID 1 (signals, zombies).
# Installs without this image stay on GD: HEIC is kept and shown as a placeholder.
# Imagick is built from its GitHub release tag, with pecl.php.net as a second source. Each source
# has its own sha256 pin, both are fetched over https only with retries, and the build fails if
# neither download matches its pin. This avoids `pecl install`, so one outage can't break the build.
# The version sed fills the GitHub archive's @PACKAGE_VERSION@ placeholder; on the PECL tarball it is a no-op.
# set -e does not apply inside fetch() because it runs in an if, so keep its && chain.
ARG IMAGICK_VERSION=3.8.0
ARG IMAGICK_SHA256=a964e54a441392577f195d91da56e0b3cf30c32e6d60d0531a355b37bb1e1a59
ARG IMAGICK_PECL_SHA256=bda67461c854f20d6105782b769c524fc37388b75d4481d951644d2167ffeec6
RUN set -eux; \
    apk add --no-cache nginx tini libjpeg-turbo libpng libwebp libzip libpq imagemagick libheif libgomp icu-libs; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libjpeg-turbo-dev libpng-dev libwebp-dev libzip-dev postgresql-dev imagemagick-dev libheif-dev icu-dev; \
    docker-php-source extract; \
    fetch() { \
        rm -f /tmp/imagick.tar.gz; \
        curl -fsSL --proto '=https' --proto-redir '=https' --retry 5 --retry-all-errors --retry-delay 5 \
            --connect-timeout 20 -o /tmp/imagick.tar.gz "$1" \
        && echo "$2  /tmp/imagick.tar.gz" | sha256sum -c -; \
    }; \
    if fetch "https://github.com/Imagick/imagick/archive/refs/tags/${IMAGICK_VERSION}.tar.gz" "${IMAGICK_SHA256}"; then \
        echo "imagick source: github"; \
    elif fetch "https://pecl.php.net/get/imagick-${IMAGICK_VERSION}.tgz" "${IMAGICK_PECL_SHA256}"; then \
        echo "imagick source: pecl"; \
    else \
        echo "imagick: no source matched its sha256 pin" >&2; exit 1; \
    fi; \
    mkdir -p /usr/src/php/ext/imagick; \
    tar -xzf /tmp/imagick.tar.gz -C /usr/src/php/ext/imagick --strip-components=1; \
    rm /tmp/imagick.tar.gz; \
    sed -i "s/@PACKAGE_VERSION@/${IMAGICK_VERSION}/" /usr/src/php/ext/imagick/php_imagick.h; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd exif opcache pdo_pgsql pgsql zip imagick intl; \
    docker-php-source delete; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/pear /usr/local/etc/php-fpm.d/www.conf.default; \
    php -m | grep -qi pdo_sqlite; \
    php -m | grep -qi imagick; \
    php -m | grep -qx sodium; \
    php -m | grep -qx intl; \
    php -m | grep -qx sqlite3; \
    php -m | grep -qx pgsql; \
    php -r "exit(phpversion('imagick') === '${IMAGICK_VERSION}' ? 0 : 1);"; \
    php -r 'exit(function_exists("imagewebp") && function_exists("imagecreatefromjpeg") ? 0 : 1);'; \
    php -r 'exit(in_array("HEIC", array_map("strtoupper", Imagick::queryFormats()), true) ? 0 : 1);'

# ImageMagick policy (hardening, S-M1 and S-L1): resource limits, risky coders and delegates off.
# The build fails unless `magick -list policy` shows the file and Imagick reports the limits and refuses the coders.
COPY docker/imagemagick-policy.xml /etc/ImageMagick-7/policy.xml
COPY scripts/imagick-policy-check.php /usr/local/lib/apotheke/imagick-policy-check.php
RUN set -eux; \
    magick -list policy > /tmp/im-policy.txt; \
    grep -qF '/etc/ImageMagick-7/policy.xml' /tmp/im-policy.txt; \
    for want in 16384 128MP 256MiB 1GiB '{MVG,MSL,TEXT,TXT,LABEL,CAPTION,URL,HTTP,HTTPS,FTP,XPS,PS,EPS,PDF,SVG,MSVG,EPHEMERAL}' '@*'; do \
        grep -qF -- "$want" /tmp/im-policy.txt || { cat /tmp/im-policy.txt; echo "ImageMagick policy is missing $want" >&2; exit 1; }; \
    done; \
    rm /tmp/im-policy.txt; \
    php /usr/local/lib/apotheke/imagick-policy-check.php

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
# CI4 (APOTHEKE_APP=ci4): nginx serves ci4/public; app, modules, vendor, writable and spark stay outside it.
COPY --chown=root:root ci4 /var/www/app/ci4
COPY --from=ci4-vendor --chown=root:root /ci4/vendor /var/www/app/ci4/vendor
# The autoloader check runs on 8.4 or later only: CI4's Composer platform check refuses PHP < 8.4, so an image built
# with an older PHP_VERSION ships CI4 but cannot run it (the entrypoint says so).
RUN set -eux; \
    mkdir -p /var/www/app/ci4/writable/cache /var/www/app/ci4/writable/logs /var/www/app/ci4/writable/session \
        /var/www/app/ci4/writable/uploads /var/www/app/ci4/writable/debugbar; \
    chown -R www-data:www-data /var/www/app/ci4/writable; \
    php -r 'if (PHP_VERSION_ID < 80400) exit(0); require "/var/www/app/ci4/vendor/autoload.php"; exit(class_exists("CodeIgniter\\CodeIgniter") && class_exists("Apotheke\\Auth\\SignInPace") ? 0 : 1);'

# legacy (default) or ci4: which app the container runs. Override at run time with -e APOTHEKE_APP=ci4.
ARG APOTHEKE_APP=legacy
ENV APOTHEKE_APP=${APOTHEKE_APP} \
    CI_ENVIRONMENT=production \
    DATA_DIR=/data \
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
