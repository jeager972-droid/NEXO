FROM php:8.2.28-fpm-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache nginx composer gettext && \
    mkdir -p /run/nginx && \
    install-php-extensions pdo_pgsql mysqli redis sockets

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

WORKDIR /var/www/html
COPY backend/alojamiento/composer.json backend/alojamiento/composer.lock* /var/www/html/
RUN composer install --no-dev --optimize-autoloader --no-interaction
COPY backend/alojamiento/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE ${PORT:-8080}

CMD ["sh", "-c", "export PORT=${PORT:-8080} && envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"]
