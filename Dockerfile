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

# Crear .htaccess para React SPA en /app/
RUN echo "RewriteEngine On" > /var/www/html/app/.htaccess && \
    echo "RewriteCond %{REQUEST_FILENAME} !-f" >> /var/www/html/app/.htaccess && \
    echo "RewriteCond %{REQUEST_FILENAME} !-d" >> /var/www/html/app/.htaccess && \
    echo "RewriteRule ^ index.html [QSA,L]" >> /var/www/html/app/.htaccess

# Script de inicio: construido línea por línea para evitar colapso de \n
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh && \
    echo 'set -e' >> /usr/local/bin/start.sh && \
    echo 'PORT=${PORT:-8080}' >> /usr/local/bin/start.sh && \
    echo 'echo "Listen $PORT" > /etc/apache2/ports.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "<VirtualHost *:$PORT>" > /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "    DocumentRoot /var/www/html" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "    <Directory /var/www/html>" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "        Options Indexes FollowSymLinks" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "        AllowOverride All" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "        Require all granted" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "    </Directory>" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "    ErrorLog \${APACHE_LOG_DIR}/error.log" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "    CustomLog \${APACHE_LOG_DIR}/access.log combined" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'echo "</VirtualHost>" >> /etc/apache2/sites-available/000-default.conf' >> /usr/local/bin/start.sh && \
    echo 'exec apache2-foreground' >> /usr/local/bin/start.sh && \
    chmod +x /usr/local/bin/start.sh

EXPOSE 8080

CMD ["/usr/local/bin/start.sh"]
