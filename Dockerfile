# ── Stage 1: Build React WebApp ───────────────────────────────────────────────
FROM node:20-alpine AS builder
WORKDIR /build
COPY WebApp/package.json WebApp/package-lock.json* ./
RUN npm ci --ignore-scripts
COPY WebApp/ ./
RUN npm run build

# ── Stage 2: Apache + mod_php (producción) ────────────────────────────────────
FROM php:8.2-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql mysqli redis sockets

# Habilitar mod_rewrite y mod_headers
RUN a2enmod rewrite headers

# FIX CRÍTICO: php:8.2-apache trae AllowOverride None → .htaccess se ignora
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY backend/alojamiento/composer.json backend/alojamiento/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction
COPY backend/alojamiento/ ./
COPY --from=builder /build/dist ./app/

# Inyectar regla React SPA ANTES del front-controller en .htaccess
RUN sed -i 's|# ROUTING: Front Controller Pattern|# React SPA: /app/* sin archivo → /app/index.html\n    RewriteCond %{REQUEST_URI} ^/app/\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ /app/index.html [L]\n\n    # ROUTING: Front Controller Pattern|' .htaccess

EXPOSE 8080

# FIX CRÍTICO: PORT se sustituye en RUNTIME (no en build time)
CMD ["sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-8080}/\" /etc/apache2/ports.conf && sed -i \"s/*:80/*:${PORT:-8080}/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]
