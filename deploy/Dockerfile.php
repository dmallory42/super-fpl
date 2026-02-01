FROM php:8.2-fpm-alpine

# Install SQLite and build dependencies
RUN apk add --no-cache sqlite-dev

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html/api
