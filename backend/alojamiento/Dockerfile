FROM php:8.2-apache

# 1. Instalador profesional de extensiones y Composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 2. Instalar utilidades sin descargar basura extra
RUN apt-get update && \
    apt-get install -y --no-install-recommends git unzip && \
    rm -rf /var/lib/apt/lists/*

# 3. Instalar extensiones de la base de datos, Redis y Zip
RUN install-php-extensions pdo_pgsql mysqli redis sockets zip

# 4. ARREGLO AL ERROR DE MPM: Forzar el MPM correcto (prefork) y matar los que generan conflicto
RUN a2dismod mpm_event mpm_worker || true && \
    a2enmod mpm_prefork rewrite headers

# 5. Copiar código y dependencias
WORKDIR /var/www/html
COPY composer.json composer.lock* ./

ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . .

# 6. Permisos de seguridad
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# 7. Inyectar el puerto dinámico de Railway en RUNTIME
CMD sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf && \
    sed -i "s/:80/:${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf && \
    docker-php-entrypoint apache2-foreground
