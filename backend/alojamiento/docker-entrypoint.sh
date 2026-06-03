#!/bin/sh
set -e

echo "[nexo] ===== VARIABLES DE ENTORNO COMPLETAS ====="
env | grep -E '(PORT|RAILWAY|HOST|BIND)' | sort
echo "[nexo] =========================================="

PORT="${PORT:-8080}"
echo "[nexo] USANDO PORT=$PORT"

# Reemplazar nginx.conf COMPLETO — sin depender de symlinks ni includes problemáticos
cat > /etc/nginx/nginx.conf <<EOF
user www-data;
worker_processes auto;
pid /run/nginx.pid;
error_log /dev/stderr warn;

events {
    use epoll;
    worker_connections 10240;
}

http {
    include /etc/nginx/mime.types;
    types {
        model/gltf-binary glb;
        image/x-exr exr;
        image/vnd.radiance hdr;
    }
    default_type application/octet-stream;
    sendfile on;
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript image/svg+xml;
    keepalive_timeout 65;
    server_tokens off;
    access_log /dev/stdout;

    server {
        listen 80 default_server;
        listen [::]:80 default_server ipv6only=on;
        listen ${PORT};
        listen [::]:${PORT} ipv6only=on;
        root /var/www/html;
        index index.html index.php;

        add_header X-Frame-Options "SAMEORIGIN" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-XSS-Protection "1; mode=block" always;
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header Referrer-Policy "strict-origin-when-cross-origin" always;

        # Bloquear archivos sensibles
        location ~ /\. { deny all; }
        location ^~ /_dev/ { deny all; }

        # Health check para Railway
        location = /health {
            add_header Content-Type text/plain always;
            return 200 "OK\n";
        }

        # Catch-all: intenta archivo estático, si no existe → api.php
        location / {
            try_files \$uri \$uri/ /api.php?\$query_string;
        }

        # PHP handler — api.php maneja CORS internamente
        location ~ \.php\$ {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php-fpm.sock;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_hide_header X-Powered-By;
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
clear_env = no
pm = dynamic
pm.max_children = 100
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
FPMCONF

echo "[nexo] Configurando OPcache..."
mkdir -p /usr/local/etc/php/conf.d
cat > /usr/local/etc/php/conf.d/opcache.ini <<OPCACHE
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.revalidate_freq=0
opcache.validate_timestamps=0
opcache.fast_shutdown=1
OPCACHE

echo "[nexo] VERIFICANDO ARCHIVOS EN /var/www/html:"
ls -lah /var/www/html/ | head -20

echo "[nexo] Probando config nginx..."
nginx -t

echo "[nexo] Iniciando php-fpm..."
php-fpm -D

echo "[nexo] Esperando socket php-fpm..."
for i in $(seq 1 30); do
    [ -S /run/php/php-fpm.sock ] && break
    echo "[nexo] Intento $i: socket no listo, esperando..."
    sleep 1
done
[ -S /run/php/php-fpm.sock ] && echo "[nexo] Socket listo." || echo "[nexo] WARN: socket no encontrado, continuando igual"

echo "[nexo] Arrancando workers en segundo plano..."
(while true; do php /var/www/html/worker_twilio.php; sleep 2; done) > /dev/stdout 2>&1 &
(while true; do php /var/www/html/worker_audit.php; sleep 2; done) > /dev/stdout 2>&1 &

echo "[nexo] Arrancando nginx en puerto ${PORT}..."
exec nginx -g "daemon off;"
