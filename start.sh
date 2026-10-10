#!/bin/sh
set -e

# Build the database tables on every start (safe to repeat).
php artisan migrate --force

# Create the editor account (first address in EDITOR_EMAILS) if it is missing. Safe to repeat.
php artisan app:ensure-editor

# Optional: load the demo data. Leave SEED_DEMO=false, the seeder cannot run twice.
if [ "$SEED_DEMO" = "true" ]; then
    php artisan db:seed --force || echo "Seeding skipped (it was already done)."
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
