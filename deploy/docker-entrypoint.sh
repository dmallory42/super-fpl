#!/bin/sh
set -e

# Install PHP dependencies if vendor directory doesn't exist
if [ ! -d "/var/www/html/api/vendor" ]; then
    echo "Installing PHP dependencies..."
    cd /var/www/html/api && composer install --no-interaction --no-progress
fi

# Execute the main command
exec "$@"
