FROM node:20-alpine AS builder
WORKDIR /build
COPY WebApp/package.json WebApp/package-lock.json* ./
RUN npm ci
COPY WebApp/ ./
RUN npm run build

FROM php:8.2-apache

# Instalar dependencias
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN install-php-extensions pdo_pgsql mysqli redis sockets

# Habilitar mods
RUN a2enmod rewrite headers

# Configurar Apache para el puerto de Railway
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# Copiar proyecto
WORKDIR /var/www/html
COPY backend/alojamiento/composer.json backend/alojamiento/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader
COPY backend/alojamiento/ .
COPY --from=builder /build/dist ./app/

# Permisos
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Asegurar que Apache use el puerto asignado por Railway
ENV PORT=8080
EXPOSE 8080
