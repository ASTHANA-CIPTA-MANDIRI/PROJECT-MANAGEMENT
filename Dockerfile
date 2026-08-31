# syntax=docker/dockerfile:1

# =============================================================================
# base — PHP 8.2 (CLI + FPM) from the Sury repository, plus nginx and
# supervisor. Shared by the dependency stage and the final image so the PHP
# installation happens exactly once.
# =============================================================================
FROM debian:bullseye-slim AS base

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update -y && \
    apt-get install -y --no-install-recommends ca-certificates gnupg2 wget && \
    mkdir -p /etc/apt/keyrings && \
    wget -qO /etc/apt/keyrings/sury-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ bullseye main" \
        > /etc/apt/sources.list.d/sury-php.list && \
    apt-get update -y && \
    apt-get install -y --no-install-recommends \
        php8.2-cli php8.2-fpm \
        php8.2-bcmath php8.2-curl php8.2-gd php8.2-intl php8.2-mbstring \
        php8.2-mysql php8.2-xml php8.2-zip \
        nginx supervisor && \
    apt-get purge -y --auto-remove gnupg2 wget && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /app

# =============================================================================
# vendor — PHP dependencies, installed from the committed lock file
# (`composer install`, never `composer update`) so the image is reproducible
# and never pulls untested dependency versions.
# =============================================================================
FROM base AS vendor

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

COPY . .

# bootstrap/cache and storage/framework/views are excluded from the build
# context, but composer's post-autoload-dump hook (`artisan package:discover`)
# writes into bootstrap/cache and, while booting providers, resolves the
# Blade compiler against config('view.compiled') — which realpath()s
# storage/framework/views and gets `false` if that directory doesn't exist.
RUN mkdir -p bootstrap/cache storage/framework/views && \
    cp .env.example .env && \
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# =============================================================================
# assets — the front-end build. Node is needed only here; the runtime image
# ships the compiled output and no node_modules. Tailwind's content globs
# include ./vendor/filament/**/*.blade.php, so the PHP dependencies have to be
# in place before `npm run build` runs or those classes get purged.
# =============================================================================
FROM node:20-bullseye-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
COPY --from=vendor /app/vendor ./vendor

RUN npm run build

# =============================================================================
# app — the runtime image.
# =============================================================================
FROM base AS app

COPY . .
COPY --from=vendor /app/vendor ./vendor
# The package manifest composer's post-autoload-dump hook already produced.
# Laravel would rebuild it lazily on first use, but that would race between
# the FPM workers and the queue worker on a cold start.
COPY --from=vendor /app/bootstrap/cache ./bootstrap/cache
COPY --from=assets /app/public/build ./public/build

# .env only supplies defaults; real configuration is injected as environment
# variables at runtime (see docker-compose.yml), which take precedence because
# Dotenv does not overwrite variables that already exist.
#
# Two things are deliberately NOT done here:
#   * `php artisan key:generate` is not run. A key baked into a published
#     image is a key every installation shares, and whoever pulls the image
#     can forge session cookies and signed URLs on any instance that never
#     replaced it. APP_KEY is required at runtime instead — see run.sh.
#   * .env.example's local defaults are not kept. An image gets copied and
#     shared, so it must not default to APP_DEBUG=true, which would render
#     stack traces containing configuration values to anonymous visitors.
#
# LOG_CHANNEL is switched to stderr as well: the default `daily` writes log
# files inside the container, where they are invisible to `docker logs` and
# lost when it is recreated.
RUN cp .env.example .env && \
    sed -i \
        -e 's/^APP_ENV=.*/APP_ENV=production/' \
        -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' \
        -e 's/^LOG_CHANNEL=.*/LOG_CHANNEL=stderr/' \
        .env

COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /etc/php/8.2/fpm/php-fpm-docker.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf

# Directories Laravel needs to write to. They are excluded from the build
# context (they hold local caches, logs and uploads), so recreate them empty.
RUN mkdir -p \
        bootstrap/cache \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs && \
    chown -R www-data:www-data /app/storage /app/bootstrap/cache && \
    chmod -R u+rwX /app/storage /app/bootstrap/cache

# Drop root. nginx listens on 8000 (unprivileged), and both FPM workers and
# the queue worker run as www-data, so nothing in the container needs uid 0.
USER www-data

EXPOSE 8000

# Checks that nginx is accepting connections, which is what fails if supervisor
# cannot start a process. It deliberately does not request a page: that would
# make container health depend on the database being reachable.
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD php -r 'exit(@fsockopen("127.0.0.1", 8000) ? 0 : 1);'

CMD ["bash", "./run.sh"]
