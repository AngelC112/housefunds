# ============================================================
# HouseFunds - single container image
#
# PHP 8.5 + Apache, with an embedded MariaDB that starts only when
# no external database is configured (DB_HOST not set).
# Everything that must persist lives in /data (mount a volume there).
# ============================================================

# bookworm, not trixie: trixie ships a newer MariaDB, and the embedded
# databases already in /data were created with the bookworm one.
FROM php:8.5-apache-bookworm

ARG DEBIAN_FRONTEND=noninteractive

# PHP extensions (pdo_mysql, pdo_pgsql, gd, zip, intl) and the embedded MariaDB.
# Since PHP 8.5 OPcache is part of PHP itself: it is built as an extension
# only on older versions.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      mariadb-server mariadb-client \
      libpq-dev libzip-dev libicu-dev \
      libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql zip gd intl \
 && { php -m | grep -qi '^Zend OPcache$' || docker-php-ext-install opcache; } \
 && rm -rf /var/lib/apt/lists/* /var/lib/mysql/*

# Apache: .htaccess support and the modules it uses
RUN a2enmod rewrite headers expires \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername \
 && echo 'ServerTokens Prod' >> /etc/apache2/conf-available/security.conf \
 && echo 'ServerSignature Off' >> /etc/apache2/conf-available/security.conf

# PHP settings
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'expose_php = Off'; \
      echo 'memory_limit = 256M'; \
      echo 'upload_max_filesize = 10M'; \
      echo 'post_max_size = 12M'; \
      echo 'max_execution_time = 120'; \
    } > "$PHP_INI_DIR/conf.d/housefunds.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first (better layer caching)
COPY app/composer.json app/composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts \
 && rm -f /usr/bin/composer

COPY app/ ./
RUN rm -rf data \
 && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/housefunds-entrypoint
RUN chmod +x /usr/local/bin/housefunds-entrypoint

ENV HF_DATA_DIR=/data

VOLUME ["/data"]
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
  CMD php -r 'exit(@file_get_contents("http://127.0.0.1/index.html") === false ? 1 : 0);'

# The entrypoint script is process 1 and stops Apache and MariaDB in order
# on SIGTERM; the base image's SIGWINCH would be ignored by it.
STOPSIGNAL SIGTERM
ENTRYPOINT ["housefunds-entrypoint"]
