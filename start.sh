#!/bin/sh
set -e

# Build the database tables on every start (safe to repeat).
php artisan migrate --force

# Optional: load the demo clinician and sample patients. Set SEED_DEMO=true for the first deploy only.
if [ "$SEED_DEMO" = "true" ]; then
    php artisan db:seed --force
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
