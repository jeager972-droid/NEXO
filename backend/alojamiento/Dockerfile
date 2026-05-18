FROM php:8.2-apache

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Congelar paquetes de apache2 para que apt-get no los actualice ni dispare mpm_event
RUN apt-mark hold apache2 apache2-bin apache2-data apache2-utils \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Las extensiones PHP (mlocati instala sus propias dependencias de sistema)
RUN install-php-extensions pdo_pgsql mysqli redis sockets zip

# Módulos Apache para .htaccess + AllowOverride
RUN a2enmod rewrite headers \
    && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY composer.json composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .
RUN chown -R www-data:www-data /var/www/html

# CORRECCIÓN MPM EN RUNTIME: corre justo antes de Apache, nada puede interferir después.
# Borra todos los MPM cargados, fuerza solo mpm_prefork, luego inyecta el puerto y arranca.
CMD rm -f /etc/apache2/mods-enabled/mpm_* \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load \
    && ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf \
    && sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf \
    && sed -i "s/:80/:${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground
