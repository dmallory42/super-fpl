FROM php:8.2-fpm-alpine

# Install SQLite and build dependencies
RUN apk add --no-cache sqlite-dev

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy and set up entrypoint
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

WORKDIR /var/www/html/api

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
