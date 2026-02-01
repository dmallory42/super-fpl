#!/bin/bash
# Setup script for DigitalOcean Ubuntu droplet

# Install dependencies
apt update && apt upgrade -y
apt install -y nginx php8.2-fpm php8.2-sqlite3 php8.2-curl certbot python3-certbot-nginx

# Create directories
mkdir -p /var/www/html

# Clone repo
cd /var/www/html
git clone https://github.com/dmallory42/super-fpl.git .

# Install PHP dependencies
cd api && composer install --no-dev
cd ../packages/fpl-client && composer install --no-dev

# Build frontend
cd /var/www/html/frontend
npm ci && npm run build

# Set permissions
chown -R www-data:www-data /var/www/html

# Setup SSL
certbot --nginx -d superfpl.com -d www.superfpl.com

# Enable and start services
systemctl enable nginx php8.2-fpm
systemctl restart nginx php8.2-fpm

echo "Setup complete!"
