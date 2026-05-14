FROM php:8.2-fpm-alpine

RUN apk add --no-cache libpq-dev && \
    docker-php-ext-install pdo_pgsql mysqli

COPY backend/alojamiento/ /var/www/html/

RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 9000

CMD ["php-fpm", "-F"]
