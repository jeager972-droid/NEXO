FROM php:8.2-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# RUN ATÓMICO: apt + extensiones + cirugía MPM en una sola capa
# Razón: apt-get e install-php-extensions disparan el trigger de Debian que activa
# mpm_event. Al hacerlo todo en un solo RUN, la limpieza del MPM ocurre en la
# misma capa, sin ningún estado intermedio que Docker pueda cachear.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_pgsql mysqli redis sockets zip \
    && rm -f /etc/apache2/mods-enabled/mpm_* \
    && ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && a2enmod rewrite headers \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY composer.json composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .
RUN chown -R www-data:www-data /var/www/html

CMD sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf && \
    sed -i "s/:80/:${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf && \
    apache2-foreground
