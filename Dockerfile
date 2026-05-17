# ── Stage 1: Build React WebApp ───────────────────────────────────────────────
FROM node:20-alpine AS builder
WORKDIR /build
COPY WebApp/package.json WebApp/package-lock.json* ./
RUN npm ci
COPY WebApp/ ./
RUN npm run build

# ── Stage 2: Apache + mod_php (producción) ────────────────────────────────────
FROM php:8.2-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql mysqli redis sockets

RUN a2enmod rewrite headers

RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY backend/alojamiento/composer.json backend/alojamiento/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY backend/alojamiento/ ./

COPY --from=builder /build/dist ./app/

RUN sed -i 's|# ROUTING: Front Controller Pattern|# React SPA: /app/* sin archivo → /app/index.html\n    RewriteCond %{REQUEST_URI} ^/app/\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ /app/index.html [L]\n\n    # ROUTING: Front Controller Pattern|' .htaccess

RUN echo "RewriteEngine On" > /var/www/html/app/.htaccess && \
    echo "RewriteCond %{REQUEST_FILENAME} !-f" >> /var/www/html/app/.htaccess && \
    echo "RewriteCond %{REQUEST_FILENAME} !-d" >> /var/www/html/app/.htaccess && \
    echo "RewriteRule ^ index.html [QSA,L]" >> /var/www/html/app/.htaccess

RUN chown -R www-data:www-data /var/www/html

CMD sed -i "s/80/${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground
