# PHP API Dockerfile
FROM php:8.2-fpm
# CORS fix applied - nginx catch-all → api.php - v3.0

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# nginx — sin apache2, sin MPM, sin conflictos
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx git unzip net-tools curl wget mosquitto mosquitto-clients \
    && rm -rf /var/lib/apt/lists/* \
    && wget -qO /usr/local/bin/supercronic https://github.com/aptible/supercronic/releases/download/v0.2.1/supercronic-linux-amd64 \
    && chmod +x /usr/local/bin/supercronic \
    && mkdir -p /etc/supercronic

RUN install-php-extensions pdo_pgsql mysqli redis sockets zip pcntl

WORKDIR /var/www/html

# Build context is backend/api directory
COPY composer.json composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

RUN chown -R www-data:www-data /var/www/html

RUN cp /var/www/html/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

CMD []
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
