# syntax=docker/dockerfile:1
FROM dunglas/frankenphp:1-php8.5-trixie AS base
# OPcache is built into PHP 8.5.
RUN install-php-extensions pdo_pgsql intl zip pcntl
# Global ceiling for request bodies; Caddy applies the smaller per-route limits.
RUN printf '%s\n' 'expose_php=Off' 'post_max_size=22M' 'upload_max_filesize=21M' 'max_file_uploads=1' \
    > "$PHP_INI_DIR/conf.d/zz-cortex.ini"
RUN groupadd -g 10001 app \
    && useradd -u 10001 -g app -M -d /tmp app \
    && setcap -r /usr/local/bin/frankenphp \
    && chown -R app:app /data/caddy /config/caddy
WORKDIR /app
ENV SERVER_NAME=:8080
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint
COPY --chmod=755 docker/release.sh /usr/local/bin/release
COPY --chmod=755 docker/tick.sh /usr/local/bin/tick
COPY --chmod=755 docker/run-tests.sh /usr/local/bin/run-tests
COPY --chmod=755 docker/init-secrets.sh /usr/local/bin/init-secrets
ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

FROM base AS composer-base
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache

FROM composer-base AS vendor-prod
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM composer-base AS vendor-dev
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM composer-base AS dev
# HOST_UID and HOST_GID match the developer, so bind-mounted files keep the right owner.
ARG HOST_UID=1000
ARG HOST_GID=1000
RUN groupmod -o -g "${HOST_GID}" app && usermod -o -u "${HOST_UID}" app \
    && chown -R app:app /data/caddy /config/caddy
# Interactive tools (tinker) need a writable configuration directory.
ENV XDG_CONFIG_HOME=/tmp COMPOSER_CACHE_DIR=/tmp/composer-user-cache
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
ENV APP_OPTIMIZE=false
COPY --from=vendor-dev --chown=app:app /app/vendor ./vendor
COPY --chown=app:app . .
RUN composer dump-autoload && chown -R app:app vendor/composer vendor/autoload.php bootstrap/cache
USER app

FROM dev AS test
# Laravel looks for a .env file at boot. It stays empty: settings come from the container.
USER root
RUN touch .env
USER app

FROM composer-base AS build-prod
COPY --from=vendor-prod /app/vendor ./vendor
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM base AS prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
# Code belongs to root: the application user cannot change it.
COPY --from=build-prod /app /app
RUN mkdir -p storage/app/private storage/app/public storage/logs storage/framework/cache/data \
        storage/framework/sessions storage/framework/testing storage/framework/views bootstrap/cache \
    && chown -R app:app storage bootstrap/cache
USER app
HEALTHCHECK CMD curl -fsS http://127.0.0.1:8080/up || exit 1
