FROM php:8.2-apache

# 1. Herramientas base necesarias para Composer y extensiones
RUN apt-get update && apt-get install -y git unzip libpq-dev libzip-dev && rm -rf /var/lib/apt/lists/*

# 2. Instalador de extensiones y Composer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 3. Instalar extensiones (Aseguramos zip para que composer no falle)
RUN install-php-extensions pdo_pgsql mysqli redis sockets zip

# 4. SOLUCIÓN DE RAÍZ AL MPM: 
# Forzamos la desactivación de los módulos que Debian activa por error
# Y limpiamos físicamente los archivos para que no haya duda
RUN a2dismod mpm_event mpm_worker || true && \
    rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf && \
    rm -f /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf && \
    a2enmod mpm_prefork rewrite headers

# 5. Configurar Apache para que lea el .htaccess y use el puerto de Railway
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# 6. Composer (como superusuario para evitar bloqueos)
COPY composer.json composer.lock* ./
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 7. Copiar el resto del código
COPY . .

# 8. Permisos correctos
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# 9. ARRANQUE: Inyección de puerto en caliente
# Usamos un script de una sola línea para evitar errores de interpretación de señales
CMD sh -c "sed -i 's/Listen 80/Listen ${PORT:-8080}/g' /etc/apache2/ports.conf && \
    sed -i 's/:80/:${PORT:-8080}/g' /etc/apache2/sites-available/000-default.conf && \
    apache2-foreground"
