# Usar PHP con Apache (más simple, sin archivos extra)
FROM php:8.2-apache

# Instalar extensiones necesarias
RUN apt-get update && apt-get install -y libpq-dev && \
    docker-php-ext-install pdo_pgsql mysqli && \
    a2enmod rewrite

# Copiar todo el código del backend (asumiendo que está en backend/alojamiento/)
# Si renombraste la carpeta a "backend", usa:
COPY backend/alojamiento/ /var/www/html/

# Si todavía tienes "Logica de negocio", usa comodín:
# COPY Logica*de*negocio/alojamiento/ /var/www/html/

# Configurar permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Exponer el puerto 80 (Railway lo usará)
EXPOSE 80
