FROM php:8.2-apache

# Instalar dependencias y extensiones PHP
RUN apt-get update && apt-get install -y libpq-dev libssl-dev && \
    docker-php-ext-install pdo_pgsql mysqli && \
    pecl install redis && docker-php-ext-enable redis && \
    a2enmod rewrite

# Copiar backend al document root
COPY backend/alojamiento/ /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80
