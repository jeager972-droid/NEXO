FROM php:8.2-fpm-alpine

# Instalar Nginx y dependencias
RUN apk add --no-cache nginx libpq-dev && \
    docker-php-ext-install pdo_pgsql mysqli && \
    pecl install redis && docker-php-ext-enable redis

# Copiar la configuración de Nginx (ya tienes default.conf y nginx.conf en backend/alojamiento/)
COPY backend/alojamiento/nginx.conf /etc/nginx/nginx.conf
COPY backend/alojamiento/default.conf /etc/nginx/conf.d/default.conf

# Copiar el código de la aplicación
COPY backend/alojamiento/ /var/www/html/

# Ajustar permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80

# Iniciar PHP-FPM y Nginx
CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
