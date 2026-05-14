FROM php:8.2-fpm-alpine

# Instalar dependencias + autoconf para pecl
RUN apk add --no-cache nginx libpq-dev autoconf build-base && \
    docker-php-ext-install pdo_pgsql mysqli && \
    pecl install redis && docker-php-ext-enable redis

# Copiar configuración de Nginx
COPY backend/alojamiento/nginx-default.conf /etc/nginx/http.d/default.conf

# Copiar código
COPY backend/alojamiento/ /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80

CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
