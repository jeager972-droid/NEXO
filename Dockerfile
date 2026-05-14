FROM php:8.2-apache

# Instalar extensiones necesarias
RUN apt-get update && apt-get install -y libpq-dev && \
    docker-php-ext-install pdo_pgsql mysqli && \
    a2enmod rewrite

# Copiar el código del backend
COPY backend/alojamiento/ /var/www/html/

# Configurar Apache para que use el puerto que asigna Railway
RUN echo "Listen \${PORT:-80}" >> /etc/apache2/ports.conf && \
    sed -i "s/80/\${PORT:-80}/g" /etc/apache2/sites-available/000-default.conf

# Permisos
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
