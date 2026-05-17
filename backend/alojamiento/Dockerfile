# ═══════════════════════════════════════════════════════════════════════════════
# STAGE 1: Composer Dependencies (imagen limpia con git/unzip ya incluidos)
# ═══════════════════════════════════════════════════════════════════════════════
FROM composer:2 AS composer-stage

WORKDIR /app

# Copiar archivos de dependencias
COPY composer.json composer.lock* ./

# Instalar dependencias de Composer (esta imagen YA tiene git y unzip nativos)
# No contamina Apache, no genera conflictos de MPM
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --no-scripts \
    --prefer-dist

# ═══════════════════════════════════════════════════════════════════════════════
# STAGE 2: Runtime Apache (imagen VIRGEN sin tocar apt-get)
# ═══════════════════════════════════════════════════════════════════════════════
FROM php:8.2-apache

# 1. Instalar extensiones PHP SIN tocar apt-get (mlocati lo hace internamente de forma segura)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql mysqli redis sockets zip

# 2. SOLUCIÓN AGRESIVA AL BUG DE MPM:
#    - NO usamos apt-get (que activa mpm_event/mpm_worker automáticamente)
#    - Borramos físicamente los symlinks conflictivos si existen
#    - Forzamos mpm_prefork (único compatible con mod_php)
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load \
          /etc/apache2/mods-enabled/mpm_event.conf \
          /etc/apache2/mods-enabled/mpm_worker.load \
          /etc/apache2/mods-enabled/mpm_worker.conf && \
    a2enmod mpm_prefork rewrite headers

# 3. HARDENING: Habilitar AllowOverride para que .htaccess funcione
#    (La imagen base trae AllowOverride None por defecto)
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' \
    /etc/apache2/apache2.conf

# 4. Copiar código de la aplicación
WORKDIR /var/www/html

# Copiar vendor/ desde el stage de Composer (evita instalar git/unzip en Apache)
COPY --from=composer-stage /app/vendor ./vendor

# Copiar el resto del código
COPY . .

# 5. Permisos de seguridad (www-data es el usuario de Apache)
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# 6. INYECCIÓN DINÁMICA DEL PUERTO DE RAILWAY (Runtime, no Build time)
#    Railway asigna $PORT en el arranque del contenedor, no en la construcción
#    Usamos docker-php-entrypoint (wrapper oficial) para garantizar que PHP se inicialice
CMD sed -i "s/Listen 80/Listen ${PORT:-8080}/g" /etc/apache2/ports.conf && \
    sed -i "s/:80/:${PORT:-8080}/g" /etc/apache2/sites-available/000-default.conf && \
    docker-php-entrypoint apache2-foreground
