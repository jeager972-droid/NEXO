FROM php:8.2-cli

RUN apt-get update && apt-get install -y libpq-dev autoconf make gcc \
    && docker-php-ext-install pdo_pgsql mysqli \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY backend/alojamiento/ /var/www/html/
WORKDIR /var/www/html

# Railway asigna un puerto dinámico a la variable PORT. Si no existe, usa 8080 por defecto.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} api.php"]
