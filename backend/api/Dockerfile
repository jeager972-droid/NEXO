# Stage 1: Build Landing Page
FROM node:20-alpine AS frontend-builder
WORKDIR /app
COPY landing/package*.json ./
RUN npm ci
COPY landing/ .
RUN npm run build

# Stage 2: PHP API
FROM php:8.2-fpm
# CORS fix applied - nginx catch-all → api.php - v3.0

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# nginx — sin apache2, sin MPM, sin conflictos
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx git unzip net-tools curl mosquitto mosquitto-clients \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql mysqli redis sockets zip pcntl

WORKDIR /var/www/html

# Build context is the monorepo root
COPY backend/api/composer.json backend/api/composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY backend/api/ .
# Copy frontend build output
COPY --from=frontend-builder /app/dist/ ./

RUN chown -R www-data:www-data /var/www/html

RUN cp /var/www/html/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

CMD []
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
