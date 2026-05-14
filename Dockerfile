FROM php:8.2-fpm-alpine

RUN apk add --no-cache nginx libpq-dev autoconf build-base && \
    docker-php-ext-install pdo_pgsql mysqli && \
    pecl install redis && docker-php-ext-enable redis

RUN sed -i 's|listen = /var/run/php-fpm.sock|listen = 127.0.0.1:9000|g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|^;listen.owner.*||g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|^;listen.group.*||g' /usr/local/etc/php-fpm.d/www.conf && \
    sed -i 's|^listen.mode.*||g' /usr/local/etc/php-fpm.d/www.conf

COPY backend/alojamiento/nginx.conf /etc/nginx/nginx.conf
COPY backend/alojamiento/default.conf /etc/nginx/conf.d/default.conf
COPY backend/alojamiento/ /var/www/html/

RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80

CMD sh -c "php-fpm -D && sleep 2 && nginx -g 'daemon off;'"
