FROM thecodingmachine/php:8.2-v4-fpm-nginx

# Copiar código (ya lo hace la imagen, pero explícito)
COPY backend/alojamiento/ /var/www/html/

# Script para cambiar el puerto de nginx al que asigna Railway
RUN echo '#!/bin/sh' > /usr/local/bin/start.sh && \
    echo 'PORT=${PORT:-80}' >> /usr/local/bin/start.sh && \
    echo 'sed -i "s/listen 80;/listen ${PORT};/g" /etc/nginx/sites-enabled/default.conf' >> /usr/local/bin/start.sh && \
    echo 'nginx -g "daemon off;"' >> /usr/local/bin/start.sh && \
    chmod +x /usr/local/bin/start.sh

# Exponer el puerto dinámico (Railway lo usa)
EXPOSE 80

# Comando personalizado
CMD ["/usr/local/bin/start.sh"]
