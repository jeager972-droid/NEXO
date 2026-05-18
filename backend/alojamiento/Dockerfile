FROM php:8.2-apache

# 1. Instalar dependencias del sistema de un solo golpe
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# 2. Instalador de extensiones PHP (mlocati)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql mysqli redis sockets zip

# 3. SOLUCIÓN DE RAÍZ AL MPM (Cirugía Física)
# Borramos TODOS los archivos de MPM activados y activamos SOLO prefork manualmente
RUN rm -f /etc/apache2/mods-enabled/mpm_* && \
    ln -s /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load && \
    ln -s /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# 4. Habilitar módulos necesarios para tu .htaccess
RUN a2enmod rewrite headers

# 5. Configurar Apache para que respete tu .htaccess (AllowOverride All)
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# 6. Preparar el código
WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 7. Copiar el resto del proyecto
COPY . .

# 8. Permisos correctos para que Apache pueda escribir si lo necesita
RUN chown -R www-data:www-data /var/www/html

# 9. ARRANQUE: Inyección de puerto en caliente
# Este comando reconfigura el puerto justo antes de encender el motor
CMD sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf && \
    sed -i "s/:80/:${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf && \
    apache2-foreground
