FROM php:8.2-cli

# Instalar extensiones PHP necesarias
RUN apt-get update && apt-get install -y libpq-dev autoconf make gcc \
    && docker-php-ext-install pdo_pgsql mysqli \
    && pecl install redis && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# Copiar backend
COPY backend/alojamiento/ /var/www/html/
WORKDIR /var/www/html

# Escuchar en el puerto que asigne Railway (variable $PORT)
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-80} api.php"]
