#!/bin/bash
# Cloud Run entrypoint.
#
# No migrations to run — this example has no database. The only job is to bind
# Octane to the port Cloud Run hands us in $PORT.

set -euo pipefail

php artisan config:cache
php artisan route:cache

exec php artisan octane:start \
    --server=swoole \
    --host=0.0.0.0 \
    --port="${PORT:-8080}"
