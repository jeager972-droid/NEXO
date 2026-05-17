FROM php:8.2.28-fpm-alpine

# 1. Instalador profesional de extensiones PHP (esto arregla el error de autoconf/phpize)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# 2. Instalar Nginx, Composer y extensiones necesarias de un solo golpe
RUN apk add --no-cache nginx composer gettext && \
    mkdir -p /run/nginx && \
    install-php-extensions pdo_pgsql mysqli redis sockets

# 3. Inyectar configuración de Nginx (Self-contained)
RUN printf "server { \n\
    listen ${PORT:-8080}; \n\
    root /var/www/html; \n\
    index api.php index.php index.html; \n\
    location / { \n\
        try_files \$uri \$uri/ /api.php?\$query_string; \n\
    } \n\
    location ~ \\.php$ { \n\
        fastcgi_pass 127.0.0.1:9000; \n\
        fastcgi_index index.php; \n\
        include fastcgi_params; \n\
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name; \n\
    } \n\
}" > /etc/nginx/http.d/default.conf.template

# 4. Preparar la aplicación
WORKDIR /var/www/html
COPY composer.json composer.lock* /var/www/html/
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE ${PORT:-8080}

# 5. Comando de arranque (PHP + Nginx)
CMD ["sh", "-c", "export PORT=${PORT:-8080} && envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"
