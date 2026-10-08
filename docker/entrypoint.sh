#!/bin/sh
# Container entrypoint. CONTAINER_ROLE decides what this container does (see Dockerfile header).
set -eu

# Ad-hoc commands (Render's pre-deploy command, `docker run IMAGE php artisan ...`, one-off jobs):
# run them as the unprivileged app user instead of starting a role.
if [ "$#" -gt 0 ]; then
  cd /var/www/html
  exec su-exec www-data "$@"
fi

ROLE="${CONTAINER_ROLE:-web}"
PORT="${PORT:-10000}"
cd /var/www/html

as_app() { su-exec www-data "$@"; }

# Production caches. Needs the real environment variables, so it runs at start-up, not at build time.
warm_caches() {
  as_app php artisan config:cache
  as_app php artisan route:cache
  as_app php artisan event:cache
  as_app php artisan view:cache || true
}

case "$ROLE" in
  web)
    if [ -z "${APP_KEY:-}" ]; then echo "FATAL: APP_KEY is not set" >&2; exit 1; fi
    # Migrations are opt-in: with several instances only one should run them.
    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
      echo "Running migrations..."
      as_app php artisan migrate --force --isolated
    fi
    warm_caches

    # Optional: run the queue worker and/or scheduler inside this container (one service, no paid worker needed).
    # They stop whenever this container stops, so on a sleeping free instance pair them with an external pinger.
    if [ "${RUN_WORKER_IN_WEB:-false}" = "true" ]; then
      cat >> /etc/supervisord.conf <<'SUP'

[program:queue-worker]
command=php artisan queue:work --tries=3 --sleep=3 --max-time=3600 --max-jobs=500
directory=/var/www/html
user=www-data
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
SUP
    fi
    if [ "${RUN_SCHEDULER_IN_WEB:-false}" = "true" ]; then
      cat >> /etc/supervisord.conf <<'SUP'

[program:scheduler]
command=php artisan schedule:work
directory=/var/www/html
user=www-data
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
SUP
    fi

    # Let nginx listen on whatever port the platform assigned.
    export PORT
    envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
    nginx -t
    echo "Starting web on 0.0.0.0:${PORT}"
    exec /usr/bin/supervisord -c /etc/supervisord.conf
    ;;

  worker)
    warm_caches
    exec su-exec www-data php artisan queue:work --tries=3 --sleep=3 --max-time=3600 --max-jobs=1000
    ;;

  scheduler)
    warm_caches
    exec su-exec www-data php artisan schedule:work
    ;;

  cron)
    warm_caches
    exec su-exec www-data php artisan schedule:run --no-interaction
    ;;

  # Realtime WebSocket server (optional). Needs its own public address; point VITE_REVERB_* at it.
  reverb)
    warm_caches
    exec su-exec www-data php artisan reverb:start --host=0.0.0.0 --port="${PORT}" --no-interaction
    ;;

  migrate)
    exec su-exec www-data php artisan migrate --force --isolated
    ;;

  *)
    echo "Unknown CONTAINER_ROLE '$ROLE' (use web|worker|scheduler|cron|reverb|migrate)" >&2
    exit 64
    ;;
esac
