FROM php:8.2.28-fpm-alpine

# Instalar extensiones necesarias
RUN docker-php-ext-install pdo_pgsql mysqli && \
    docker-php-ext-enable pdo_pgsql

# Instalar Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Instalar Nginx
RUN apk add --no-cache nginx

# Copiar configuración de Nginx
COPY nginx.conf /etc/nginx/nginx.conf
COPY default.conf /etc/nginx/conf.d/default.conf

# Copiar aplicación
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Script de inicio

EXPOSE 80

CMD ["sh", "-c", "php-fpm -D && nginx -g "daemon off;""]
