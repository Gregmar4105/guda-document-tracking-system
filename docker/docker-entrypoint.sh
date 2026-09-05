#!/bin/sh
set -e

# Run migrations if AUTO_MIGRATE is set to 1 or true
if [ "$AUTO_MIGRATE" = "1" ] || [ "$AUTO_MIGRATE" = "true" ]; then
    echo "[Entrypoint] AUTO_MIGRATE is enabled. Running database migrations..."
    php /var/www/html/migrate.php || echo "[Entrypoint] Migration finished with notices/errors."
fi

exec "$@"
