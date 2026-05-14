FROM php:8.2-cli

RUN apt-get update && apt-get install -y libpq-dev && \
    docker-php-ext-install pdo_pgsql mysqli

COPY backend/alojamiento/ /var/www/html/
WORKDIR /var/www/html

EXPOSE 8080

CMD php -S 0.0.0.0:8080 -t . api.php
