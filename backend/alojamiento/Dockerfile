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
# STAGE 2: Runtime Apache (imagen VIRGEN - dejamos que maneje su propio MPM)
# ═══════════════════════════════════════════════════════════════════════════════
FROM php:8.2-apache

# 1. Instalar extensiones PHP (mlocati lo hace de forma segura sin tocar apt-get)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql mysqli redis sockets zip

# 2. Habilitar módulos de Apache necesarios para .htaccess
#    NO tocamos los MPM - la imagen base ya viene con mpm_prefork configurado correctamente
RUN a2enmod rewrite headers

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
