#!/bin/sh
# Production entrypoint for every container of the app image (app, queue,
# queue-checks, scheduler). Each runs with its own filesystem, so each caches
# config/routes/views for itself, on every start, from the environment it was
# started with. A changed
# .env therefore needs a recreate (`up -d --force-recreate`), not a restart.
set -e

php artisan optimize --quiet

exec docker-php-entrypoint "$@"
