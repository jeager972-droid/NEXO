FROM php:8.2-fpm-alpine

# Instalar Nginx, PostgreSQL dev, autoconf (para pecl), y utilidades
RUN apk add --no-cache nginx libpq-dev autoconf build-base && \
    docker-php-ext-install pdo_pgsql mysqli && \
    pecl install redis && docker-php-ext-enable redis

# CRITICO: Configurar PHP-FPM para escuchar en TCP 127.0.0.1:9000 (no socket UNIX)
RUN sed -i 's|listen = /var/run/php-fpm.sock|listen = 127.0.0.1:9000|g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|^;listen.owner.*||g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|^;listen.group.*||g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|^listen.mode.*||g' /usr/local/etc/php-fpm.d/www.conf

# Copiar configuracion de Nginx
COPY backend/alojamiento/default.conf /etc/nginx/conf.d/default.conf

# Copiar codigo del backend
COPY backend/alojamiento/ /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80

CMD sh -c "php-fpm -D && sleep 2 && nginx -g 'daemon off;'"
