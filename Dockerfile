FROM php:8.2-fpm-alpine

# Instalar Nginx y dependencias
RUN apk add --no-cache nginx libpq-dev && \
    docker-php-ext-install pdo_pgsql mysqli && \
    pecl install redis && docker-php-ext-enable redis

# Configuración de Nginx
RUN echo 'server {
    listen 80;
    server_name _;
    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /api.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}' > /etc/nginx/http.d/default.conf

# Copiar código
COPY backend/alojamiento/ /var/www/html/

# Permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80

CMD sh -c "php-fpm -D && nginx -g 'daemon off;'"
