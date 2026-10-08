# syntax=docker/dockerfile:1
#
# DeskFlow API: one image, four roles (set CONTAINER_ROLE):
#   web        nginx + php-fpm, listens on $PORT           (Render: Web Service)
#   worker     php artisan queue:work                      (Render: Background Worker)
#   scheduler  php artisan schedule:work (runs forever)    (Render: Background Worker, alternative to cron)
#   cron       php artisan schedule:run (runs once, exits) (Render: Cron Job, schedule "* * * * *")
#   migrate    php artisan migrate --force (runs once)     (Render: pre-deploy command / one-off job)
#
# Build:  docker build -t deskflow-api .
# Run:    docker run --env-file .env.docker -e PORT=8000 -p 8000:8000 deskflow-api

# ---- Stage 1: PHP dependencies (production only) --------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --ignore-platform-reqs: this stage is only a download tool; the final image has the real extensions.
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ---- Stage 2: runtime ----------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS app

# System packages: nginx (web server), supervisor (runs nginx + php-fpm), tini (clean PID 1 / signals),
# su-exec (drop root for worker roles), gettext (envsubst for the nginx $PORT template), curl (health check).
RUN apk add --no-cache nginx supervisor tini su-exec gettext curl

# PHP extensions this app needs beyond the base image:
#   pdo_pgsql (Supabase/Postgres), pdo_mysql (optional MySQL), redis (cache/queue/presence),
#   intl (Carbon/locale), bcmath, pcntl (queue worker signals), zip, opcache (speed).
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pdo_mysql redis intl bcmath pcntl zip opcache

# Config
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-deskflow.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-deskflow.conf
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

WORKDIR /var/www/html

# App code, then the vendor folder from stage 1.
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor

# Writable runtime folders; discover packages (the stage-1 install skipped scripts); drop build leftovers.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && su-exec www-data php artisan package:discover --ansi \
    && rm -rf /var/www/html/tests /tmp/* /var/cache/apk/*

# Render sets PORT (default 10000) and probes 0.0.0.0:$PORT. The entrypoint renders nginx to listen on it.
ENV PORT=10000 \
    CONTAINER_ROLE=web \
    RUN_MIGRATIONS=false \
    APP_ENV=production \
    LOG_CHANNEL=stderr
EXPOSE 10000

# Only meaningful for the web role; other roles have no HTTP server.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD [ "$CONTAINER_ROLE" != "web" ] || curl -fsS "http://127.0.0.1:${PORT}/up" >/dev/null || exit 1

ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/docker-entrypoint"]
