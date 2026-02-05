#!/bin/sh
set -e

# Install PHP dependencies if vendor directory doesn't exist
if [ ! -d "/var/www/html/api/vendor" ]; then
    echo "Installing PHP dependencies..."
    cd /var/www/html/api && composer install --no-interaction --no-progress
fi

# Run database migrations
echo "Running database migrations..."
php /var/www/html/api/bin/migrate.php

# Execute the main command
exec "$@"
