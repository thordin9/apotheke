FROM php:8.3-apache

LABEL org.opencontainers.image.title="Apotheke" \
      org.opencontainers.image.description="Apotheke, the storehouse for your stuff: a self-hosted PHP + SQLite catalog for your belongings" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.authors="Stathis Papandreou"

# GD (JPEG/PNG/WebP), EXIF (photo orientation), OPcache, pdo_pgsql (PostgreSQL), zip (backups). pdo_sqlite ships with the official image.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libjpeg62-turbo-dev libpng-dev libwebp-dev libpq-dev libzip-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd exif opcache pdo_pgsql zip; \
    php -m | grep -qi pdo_sqlite; \
    rm -rf /var/lib/apt/lists/*; \
    a2enmod headers expires; \
    a2dissite 000-default

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-apotheke.ini
COPY docker/apache.conf /etc/apache2/sites-available/apotheke.conf
COPY docker/entrypoint.sh /usr/local/bin/apotheke-entrypoint
RUN chmod +x /usr/local/bin/apotheke-entrypoint && a2ensite apotheke

COPY --chown=root:root public /var/www/app/public
COPY --chown=root:root src /var/www/app/src
COPY --chown=root:root migrations /var/www/app/migrations
COPY --chown=root:root bin /var/www/app/bin
COPY --chown=root:root config.example.php /var/www/app/config.example.php

ENV DATA_DIR=/data \
    APP_PASSWORD="" \
    TZ=UTC

VOLUME ["/data"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s CMD curl -fsS http://127.0.0.1/healthz || exit 1

ENTRYPOINT ["apotheke-entrypoint"]
CMD ["apache2-foreground"]
