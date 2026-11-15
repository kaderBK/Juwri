# syntax=docker/dockerfile:1
#
# juwri self-host image — FrankenPHP (official image, PHP 8.4) running the app in Laravel Octane worker mode.
# One image, three roles chosen by the command (see docker/README.md):
#   app        (default)  FrankenPHP + Octane worker
#   queue                 php core/artisan queue:work --queue=heavy,default
#   scheduler             php core/artisan schedule:work
#
# No secrets are baked in: configuration is read from the container's environment at run time (docker/env.example lists
# the keys, with no values). Build:  docker build -t juwri/app:local .
# pinned 2026-11-15; refresh with the registry digest of 1-php8.4
ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4@sha256:8f0d509ad824fa86da88b55c5ea9f1605380f423ab55ec3a72864cc223491bfd

# ---------------------------------------------------------------------------------------------------------------------
FROM ${PHP_IMAGE} AS base

# the extensions juwri uses (gd for images, intl, bcmath for money, sodium for licences, pcntl for Octane / queue signals)
RUN install-php-extensions bcmath exif gd intl mbstring opcache pcntl pdo_mysql pdo_pgsql redis sodium zip \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl procps unzip git mariadb-client postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# pinned 2026-11-15; refresh with the registry digest of 2
COPY --from=composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca /usr/bin/composer /usr/bin/composer

# ---------------------------------------------------------------------------------------------------------------------
FROM base AS vendor
WORKDIR /app
COPY . /app
# Production dependencies only. (The Enterprise package is a Composer path repository: a source tree without it is the
# Community build and its composer.json does not require it.)
RUN cd core \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --no-dev --optimize --no-scripts

# ---------------------------------------------------------------------------------------------------------------------
FROM base AS app
WORKDIR /app
COPY --from=vendor /app /app

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini ${PHP_INI_DIR}/conf.d/zz-juwri.ini
COPY docker/entrypoint.sh /usr/local/bin/juwri-entrypoint

# Octane's worker script lives in the package; Caddy runs it from the web root (see docker/Caddyfile).
# Uploads are written next to the front controller (/app/storage) and the framework writes under core/storage:
# both are volumes in the compose files.
# The worker script requires bootstrap.php from its own directory, so both files are copied (docker test 2026-10-07: without
# bootstrap.php the worker never starts).
RUN cp core/vendor/laravel/octane/bin/frankenphp-worker.php core/vendor/laravel/octane/bin/bootstrap.php /app/ \
    && chmod +x /usr/local/bin/juwri-entrypoint \
    && mkdir -p core/storage/framework/cache/data core/storage/framework/sessions core/storage/framework/views core/storage/logs core/bootstrap/cache storage \
    && chown -R www-data:www-data /app/core/storage /app/core/bootstrap/cache /app/storage /data /config

ENV SERVER_NAME=":8080" \
    OCTANE_MAX_REQUESTS="1000" \
    APP_ENV="production" \
    APP_DEBUG="false" \
    LOG_CHANNEL="stderr" \
    CACHE_DRIVER="file" \
    SESSION_DRIVER="file"

# `docker exec` / `docker run ... php artisan ...` start in the Laravel app (the entrypoint also cd's here for every role).
WORKDIR /app/core
USER www-data
EXPOSE 8080
VOLUME ["/app/storage", "/app/core/storage"]

# The app answers 400 to a Host that is not APP_URL's host (trusted hosts), so the probe sends APP_URL's host.
HEALTHCHECK --interval=30s --timeout=5s --start-period=180s --retries=3 \
    CMD sh -c 'h="${APP_URL#*://}"; h="${h%%/*}"; curl -fsS -H "Host: ${h:-localhost}" http://127.0.0.1:8080/up >/dev/null || exit 1'

ENTRYPOINT ["juwri-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
