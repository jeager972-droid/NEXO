FROM php:8.2-fpm

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# nginx — sin apache2, sin MPM, sin conflictos
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx git unzip net-tools \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql mysqli redis sockets zip

WORKDIR /var/www/html
COPY composer.json composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .
RUN chown -R www-data:www-data /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
