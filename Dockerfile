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

# Script de inicio: sobrescribe config Apache con PORT dinámico (no usa sed)
RUN printf '#!/bin/sh\n\
set -e\n\
PORT=${PORT:-8080}\n\
printf "Listen %%s\\n" "$PORT" > /etc/apache2/ports.conf\n\
printf "<VirtualHost *:%%s>\\n\\\n\
    DocumentRoot /var/www/html\\n\\\n\
    <Directory /var/www/html>\\n\\\n\
        Options -Indexes +FollowSymLinks\\n\\\n\
        AllowOverride All\\n\\\n\
        Require all granted\\n\\\n\
    </Directory>\\n\\\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\\n\\\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\\n\\\n\
</VirtualHost>\\n" "$PORT" > /etc/apache2/sites-available/000-default.conf\n\
echo "[NEXO] Apache configured for port $PORT"\n\
exec apache2-foreground\n' > /usr/local/bin/start.sh && chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]
