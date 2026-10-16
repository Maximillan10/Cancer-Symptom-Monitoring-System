#!/bin/sh
set -e

# Build the database tables on every start (safe to repeat).
php artisan migrate --force

# Create the editor account (first address in EDITOR_EMAILS) if it is missing. Safe to repeat.
php artisan app:ensure-editor

# Optional: load the demo patients. Safe to repeat: it adds data only while there are no patients.
if [ "$SEED_DEMO" = "true" ]; then
    php artisan demo:seed || echo "Demo seeding skipped."
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
