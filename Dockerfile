FROM php:8.2-fpm-alpine

# Actualizar repositorios (evitar cache corrupta)
RUN rm -rf /var/cache/apk/* && \
    apk update --no-cache && \
    apk add --no-cache nginx libpq-dev autoconf build-base

# Instalar extensiones PHP
RUN docker-php-ext-install pdo_pgsql mysqli

# Instalar redis usando pecl (con autoconf ya disponible)
RUN pecl install redis && docker-php-ext-enable redis

# Copiar configuraciones de Nginx
COPY backend/alojamiento/nginx.conf /etc/nginx/nginx.conf
COPY backend/alojamiento/default.conf /etc/nginx/conf.d/default.conf

# Copiar código de la aplicación
COPY backend/alojamiento/ /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80

# Iniciar PHP-FPM y Nginx
CMD php-fpm -D && nginx -g 'daemon off;'
