FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev libssl-dev && \
    docker-php-ext-install pdo_pgsql mysqli && \
    pecl install redis && docker-php-ext-enable redis && \
    a2dismod mpm_event mpm_worker || true && \
    a2enmod mpm_prefork && \
    a2enmod rewrite

COPY backend/alojamiento/ /var/www/html/

RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80
