FROM php:8.2.28-fpm-alpine

# 1. Instalador profesional de extensiones PHP (esto arregla el error de autoconf/phpize)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# 2. Instalar Nginx, Composer y extensiones necesarias de un solo golpe
RUN apk add --no-cache nginx composer gettext && \
    mkdir -p /run/nginx && \
    install-php-extensions pdo_pgsql mysqli redis sockets

# 3. Inyectar configuración de Nginx (comillas simples garantizan que envsubst funcione)
RUN echo 'server {' > /etc/nginx/http.d/default.conf.template && \
    echo '    listen ${PORT};' >> /etc/nginx/http.d/default.conf.template && \
    echo '    root /var/www/html;' >> /etc/nginx/http.d/default.conf.template && \
    echo '    index api.php index.php index.html;' >> /etc/nginx/http.d/default.conf.template && \
    echo '    location / {' >> /etc/nginx/http.d/default.conf.template && \
    echo '        try_files $uri $uri/ /api.php?$query_string;' >> /etc/nginx/http.d/default.conf.template && \
    echo '    }' >> /etc/nginx/http.d/default.conf.template && \
    echo '    location ~ \.php$ {' >> /etc/nginx/http.d/default.conf.template && \
    echo '        fastcgi_pass 127.0.0.1:9000;' >> /etc/nginx/http.d/default.conf.template && \
    echo '        fastcgi_index index.php;' >> /etc/nginx/http.d/default.conf.template && \
    echo '        include fastcgi_params;' >> /etc/nginx/http.d/default.conf.template && \
    echo '        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' >> /etc/nginx/http.d/default.conf.template && \
    echo '    }' >> /etc/nginx/http.d/default.conf.template && \
    echo '}' >> /etc/nginx/http.d/default.conf.template

# 4. Preparar la aplicación
WORKDIR /var/www/html
COPY composer.json composer.lock* /var/www/html/
RUN composer install --no-dev --optimize-autoloader --no-interaction

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html

EXPOSE ${PORT:-8080}

# 5. Comando de arranque (PHP + Nginx)
CMD ["sh", "-c", "export PORT=${PORT:-8080} && envsubst '${PORT}' < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf && php-fpm -D && nginx -g 'daemon off;'"]
