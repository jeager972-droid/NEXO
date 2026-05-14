FROM php:8.2-apache

# Instalar extensiones necesarias
RUN apt-get update && apt-get install -y libpq-dev && \
    docker-php-ext-install pdo_pgsql mysqli && \
    a2enmod rewrite

# Copiar todo el contenido del backend (usa comodines para ignorar el espacio)
COPY Logica*de*negocio/alojamiento/ /var/www/html/

# Configurar permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80
