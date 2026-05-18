#!/bin/sh
set -e

PORT="${PORT:-8080}"
echo "[nexo] PORT=$PORT"

# Reemplazar nginx.conf COMPLETO — sin depender de symlinks ni includes problemáticos
cat > /etc/nginx/nginx.conf <<EOF
worker_processes auto;
pid /run/nginx.pid;
error_log /dev/stderr warn;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    sendfile on;
    keepalive_timeout 65;
    server_tokens off;
    access_log /dev/stdout;

    server {
        listen 0.0.0.0:${PORT} default_server;
        root /var/www/html;
        index index.html index.php;

        add_header X-Frame-Options "DENY" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-XSS-Protection "1; mode=block" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';" always;

        # Bloquear archivos sensibles
        location ~ /\. { deny all; return 403; }
        location ^~ /_dev/ { deny all; return 403; }

        # Root: servir index.html
        location = / {
            index index.html;
        }

        # API routes
        location /api.php {
            fastcgi_pass unix:/run/php/php-fpm.sock;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root/api.php;
            fastcgi_hide_header X-Powered-By;
        }

        # Catch-all: archivos estáticos o 404
        location / {
            try_files \$uri \$uri/ =404;
        }
    }
}
EOF

echo "[nexo] Configurando php-fpm para socket Unix..."
mkdir -p /run/php
cat > /usr/local/etc/php-fpm.d/www.conf <<FPMCONF
[www]
user = www-data
group = www-data
listen = /run/php/php-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
FPMCONF

echo "[nexo] VERIFICANDO ARCHIVOS EN /var/www/html:"
ls -lah /var/www/html/ | head -20

echo "[nexo] Probando config nginx..."
nginx -t

echo "[nexo] Iniciando php-fpm..."
php-fpm -D

echo "[nexo] Arrancando nginx en 0.0.0.0:${PORT}..."
nginx -g "daemon off;" &
NGINX_PID=$!

sleep 2
echo "[nexo] DIAGNÓSTICO - Puertos escuchando:"
netstat -tlnp 2>/dev/null || ss -tlnp 2>/dev/null || echo "netstat/ss no disponible"

echo "[nexo] nginx PID: $NGINX_PID"

echo "[nexo] TEST LOCAL - curl a localhost:${PORT}:"
curl -v http://localhost:${PORT}/ 2>&1 | head -30

echo "[nexo] Si el curl funcionó, el problema es Railway proxy. Si falló, es nginx."
wait $NGINX_PID
