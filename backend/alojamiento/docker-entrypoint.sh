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

        location ~* /\. { deny all; }
        location ^~ /_dev/ { deny all; }

        # Servir index.html directamente para /
        location = / {
            try_files /index.html =404;
        }

        # Rutas API → api.php
        location ~ ^/(v1|api)/ {
            try_files \$uri /api.php\$is_args\$args;
        }

        # Archivos estáticos (CSS, JS, imágenes)
        location ~* \.(css|js|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot)\$ {
            try_files \$uri =404;
            expires 1y;
            add_header Cache-Control "public, immutable";
        }

        # PHP solo para api.php
        location = /api.php {
            fastcgi_pass unix:/run/php/php-fpm.sock;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_hide_header X-Powered-By;
        }

        # Cualquier otra cosa → 404
        location / {
            try_files \$uri =404;
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
wait $NGINX_PID
