#!/bin/sh
set -e

PORT="${PORT:-8080}"
echo "[nexo] Iniciando nginx+php-fpm en puerto: $PORT"

# Escribir nginx.conf completo con el puerto correcto
cat > /etc/nginx/sites-available/default <<NGINXCONF
server {
    listen ${PORT};
    root /var/www/html;
    index index.html index.php;
    server_tokens off;

    # Headers de seguridad (equivalente al mod_headers del .htaccess)
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';" always;

    # Bloquear archivos sensibles
    location ~ /\.(git|env|htaccess|dockerignore) { deny all; return 403; }
    location ~ \.(md|log|sh|gitignore|lock)$       { deny all; return 403; }
    location ^~ /_dev/                              { deny all; return 403; }
    location = /Dockerfile                          { deny all; return 403; }

    # Bloquear cualquier PHP que NO sea api.php
    location ~ ^/(?!api\.php\$).+\.php\$ { deny all; return 403; }

    # Front Controller: si no es archivo/directorio real → api.php
    location / {
        try_files \$uri \$uri/ /api.php\$is_args\$args;
    }

    # PHP-FPM
    location ~ \.php\$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_hide_header X-Powered-By;
    }
}
NGINXCONF

# Arrancar php-fpm en background
php-fpm -D
echo "[nexo] php-fpm arrancado"

# Arrancar nginx en foreground (PID principal)
echo "[nexo] nginx listo en puerto ${PORT}"
exec nginx -g "daemon off;"
