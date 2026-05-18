FROM php:8.2-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Congelar paquetes de apache2 para que apt-get no los actualice ni dispare mpm_event
RUN apt-mark hold apache2 apache2-bin apache2-data apache2-utils \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions pdo_pgsql mysqli redis sockets zip

RUN a2enmod rewrite headers \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY composer.json composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .
RUN chown -R www-data:www-data /var/www/html

# Copiar y activar el entrypoint DESPUÉS del COPY . . para que no sobreescriba
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
