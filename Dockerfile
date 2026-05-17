FROM node:20-alpine AS builder
WORKDIR /build
# 1. Compilar el Frontend (React)
COPY WebApp/package.json WebApp/package-lock.json* ./
RUN npm ci
COPY WebApp/ ./
RUN npm run build

FROM php:8.2-apache
# 2. Instalar dependencias de PHP
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN install-php-extensions pdo_pgsql mysqli redis sockets

# 3. Habilitar módulos críticos para que tu .htaccess funcione
RUN a2enmod rewrite headers

# Permitir .htaccess en el directorio raíz
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# 4. Copiar e instalar el Backend
WORKDIR /var/www/html
COPY backend/alojamiento/composer.json backend/alojamiento/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction
COPY backend/alojamiento/ ./

# 5. Copiar el Frontend compilado a la carpeta /app
COPY --from=builder /build/dist ./app/

# 6. Permisos correctos
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# 7. EL ARREGLO DE RAÍZ: Inyectar el puerto dinámico en RUNTIME (CMD), no en build time
CMD sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf && \
    sed -i "s/:80/:${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf && \
    docker-php-entrypoint apache2-foreground
