FROM php:8.2.28-fpm-alpine

# Instalar dependencias y extensiones
RUN apk add --no-cache nginx libpq-dev
RUN docker-php-ext-install pdo_pgsql mysqli && docker-php-ext-enable pdo_pgsql
RUN pecl install redis && docker-php-ext-enable redis

# Configurar Nginx directamente (Sin necesidad de archivos externos)
RUN printf "server { \n    listen 80; \n    root /var/www/html; \n    index api.php index.php index.html; \n    location / { \n        try_files $uri $uri/ /api.php?$query_string; \n    } \n    location ~ \.php$ { \n        fastcgi_pass 127.0.0.1:9000; \n        fastcgi_index index.php; \n        include fastcgi_params; \n        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \n    } \n}" > /etc/nginx/http.d/default.conf

# Preparar la aplicación
WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# Comando de arranque (PHP + Nginx)
CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
