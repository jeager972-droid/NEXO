FROM php:8.2-apache

# 1. Instalador profesional de extensiones
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 2. Instalar extensiones de la base de datos y Redis
RUN install-php-extensions pdo_pgsql mysqli redis sockets

# 3. Habilitar módulos de Apache (¡Vital para que tu .htaccess funcione!)
RUN a2enmod rewrite headers

# 4. Copiar código y dependencias
WORKDIR /var/www/html
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction
COPY . .

# 5. Permisos de seguridad
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# 6. LA SOLUCIÓN AL 502: Inyectar el puerto dinámico de Railway en RUNTIME
CMD sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf && \
    sed -i "s/:80/:${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf && \
    docker-php-entrypoint apache2-foreground
