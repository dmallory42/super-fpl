FROM php:8.2-fpm-alpine

# Install SQLite and build dependencies
RUN apk add --no-cache sqlite-dev

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Use hardened php.ini defaults.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/php-prod.ini /usr/local/etc/php/conf.d/zz-superfpl.ini

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy and set up entrypoint
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

RUN mkdir -p /var/log/cron
COPY deploy/crontab /etc/crontabs/root

WORKDIR /var/www/html/api

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
