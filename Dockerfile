FROM thecodingmachine/php:8.2-v4-fpm-nginx

# Copiar todo el código del backend
COPY backend/alojamiento/ /var/www/html/

# Exponer el puerto estándar (luego Railway lo reasigna)
EXPOSE 80

# El CMD de la imagen ya inicia nginx+php-fpm correctamente,
# pero debemos asegurar que el puerto sea dinámico.
# Para eso, usamos un script que reemplaza listen 80 por listen $PORT.
# Si no haces esto, Railway seguirá dando 502.
RUN echo '#!/bin/sh' > /custom-start.sh && \
    echo 'PORT=${PORT:-80}' >> /custom-start.sh && \
    echo 'sed -i "s/listen 80;/listen ${PORT};/g" /etc/nginx/sites-enabled/default.conf' >> /custom-start.sh && \
    echo '/usr/local/bin/start-container' >> /custom-start.sh && \
    chmod +x /custom-start.sh

CMD ["/custom-start.sh"]
