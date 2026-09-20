#!/bin/sh
set -e

echo "[nexo] ===== VARIABLES DE ENTORNO COMPLETAS ====="
env | grep -E '(PORT|HOST|BIND)' | sort
echo "[nexo] =========================================="

PORT="${PORT:-8080}"
echo "[nexo] USANDO PORT=$PORT"

# Reemplazar nginx.conf COMPLETO — sin depender de symlinks ni includes problemáticos
cat > /etc/nginx/nginx.conf <<EOF
user www-data;
worker_processes 1;
pid /run/nginx.pid;
error_log /dev/stderr warn;

events {
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
    sendfile off;
    gzip on;
    gzip_min_length 512;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml application/xml+rss text/javascript image/svg+xml;
    keepalive_timeout 65;
    server_tokens off;
    access_log /dev/stdout;

    server {
        listen ${PORT} default_server;
        listen [::]:${PORT};
        root /var/www/html;
        index index.php;

        # Bloquear archivos sensibles
        location ~ /\. { deny all; }

        # Health check — rewrite (no try_files): try_files servía health.php
        # como archivo estático exponiendo el código fuente PHP.
        location = /health {
            rewrite ^ /health.php last;
        }

        # Health check workers
        location = /health/workers {
            rewrite ^ /health.php last;
        }

        # Root should hit the PHP API, not a static landing page
        location = / {
            try_files /api.php =404;
        }

        # Catch-all
        location / {
            try_files \$uri \$uri/ /api.php?\$query_string;
        }

        # PWA static files (service worker, manifest, workbox)
        location = /sw.js { root /var/www/html/public; }
        location = /sw.js.map { root /var/www/html/public; }
        location = /manifest.webmanifest { root /var/www/html/public; }
        location ~ ^/workbox-.*\.js$ { root /var/www/html/public; }
        location ~ ^/workbox-.*\.js\.map$ { root /var/www/html/public; }

        # PHP handler EXCLUSIVO para api.php y health.php
        # fastcgi_param HTTP_AUTHORIZATION: nginx NO pasa el header Authorization
        # a PHP-FPM por defecto. Sin esto, extractBearerToken() no encuentra el JWT.
        # CORS: PHP maneja TODOS los headers CORS via _cors_middleware.php.
        # No añadir CORS en nginx para evitar duplicados y problemas con "if is evil".
        location = /api.php {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php-fpm.sock;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
            fastcgi_hide_header X-Powered-By;
        }

        location = /health.php {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php-fpm.sock;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_param HTTP_AUTHORIZATION \$http_authorization;
            fastcgi_hide_header X-Powered-By;
        }

        # Bloquear cualquier otro script de PHP interno
        location ~ \.php\$ {
            deny all;
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
pm.max_children = 10
pm.start_servers = 2
pm.max_requests = 1000
pm.min_spare_servers = 1
pm.max_spare_servers = 3
FPMCONF

echo "[nexo] Configurando OPcache..."
mkdir -p /usr/local/etc/php/conf.d
cat > /usr/local/etc/php/conf.d/opcache.ini <<OPCACHE
opcache.enable=1
opcache.memory_consumption=64
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

echo "[nexo] Arrancando workers en segundo plano con backoff exponencial..."

# Workers de cola (twilio, audit, biometric) son daemon-loop: corren indefinidamente
# y nunca exit(0) en condiciones normales. Si mueren, se reinician con backoff.
run_worker_with_backoff() {
    local worker_file=$1
    local backoff=2
    local max_backoff=60
    while true; do
        if php "/var/www/html/$worker_file"; then
            backoff=2
        else
            echo "[nexo] WARN: $worker_file falló. Reintentando en ${backoff}s..."
            sleep $backoff
            backoff=$((backoff * 2))
            if [ $backoff -gt $max_backoff ]; then backoff=$max_backoff; fi
        fi
        sleep 2
    done
}

# Workers periódicos (absence, evasion, permission) son cron-mode: ejecutan una vez
# y exit(0). Si se reinician inmediatamente, se ejecutarían cada 2s en lugar de cada
# 60-120s, causando flood de DB y Twilio. Usar daemon mode (loop interno con sleep)
# vía variable de entorno, y un sleep mínimo de seguridad entre reinicios.
run_periodic_worker() {
    local worker_file=$1
    local mode_env=$2
    local backoff=2
    local max_backoff=60
    while true; do
        if env "$mode_env=daemon" php "/var/www/html/$worker_file"; then
            backoff=2
        else
            echo "[nexo] WARN: $worker_file falló. Reintentando en ${backoff}s..."
            sleep $backoff
            backoff=$((backoff * 2))
            if [ $backoff -gt $max_backoff ]; then backoff=$max_backoff; fi
        fi
        sleep 5
    done
}

run_worker_with_backoff "workers/worker_twilio.php" > /dev/stdout 2>&1 &
run_worker_with_backoff "workers/worker_audit.php" > /dev/stdout 2>&1 &
run_worker_with_backoff "workers/worker_biometric.php" > /dev/stdout 2>&1 &
run_periodic_worker "workers/worker_absence_detector.php" "ABSENCE_DETECTOR_MODE" > /dev/stdout 2>&1 &
run_periodic_worker "workers/worker_evasion_detector.php" "EVASION_DETECTOR_MODE" > /dev/stdout 2>&1 &
run_periodic_worker "workers/worker_permission_status.php" "PERMISSION_STATUS_MODE" > /dev/stdout 2>&1 &
# F-04: monitor de salud de nodos — detecta dispositivos offline y marca
# SIN_DATOS_NODO para que los detectores no generen falsos positivos.
run_periodic_worker "workers/worker_device_health.php" "DEVICE_HEALTH_MODE" > /dev/stdout 2>&1 &
# F-18: evaluador de criterios de aviso configurables por docente
run_periodic_worker "workers/worker_teacher_alerts.php" "TEACHER_ALERTS_MODE" > /dev/stdout 2>&1 &
# Bloque C: seguimiento de inasistencias sin respuesta del acudiente
run_periodic_worker "workers/worker_absence_followup.php" "ABSENCE_FOLLOWUP_MODE" > /dev/stdout 2>&1 &

echo "[nexo] Iniciando Mosquitto MQTT broker..."
# Create mosquitto config for production (Auth si hay variables, fallback a open solo si faltan)
mkdir -p /mosquitto/config /mosquitto/data

if [ -n "$MQTT_USER" ] && [ -n "$MQTT_PASS" ]; then
    echo "[nexo] Habilitando autenticación MQTT..."
    touch /mosquitto/config/passwd
    mosquitto_passwd -b -c /mosquitto/config/passwd "$MQTT_USER" "$MQTT_PASS"
    cat > /mosquitto/config/mosquitto.conf <<MOSQUITTOCONF
listener 1883 127.0.0.1
allow_anonymous false
password_file /mosquitto/config/passwd
persistence true
persistence_location /mosquitto/data/
log_dest stdout
MOSQUITTOCONF
else
    echo "[nexo] WARN: Iniciando MQTT sin autenticación (Falta MQTT_USER o MQTT_PASS)"
    cat > /mosquitto/config/mosquitto.conf <<MOSQUITTOCONF
listener 1883 127.0.0.1
allow_anonymous true
persistence true
persistence_location /mosquitto/data/
log_dest stdout
MOSQUITTOCONF
fi
mosquitto -c /mosquitto/config/mosquitto.conf -d
echo "[nexo] Mosquitto MQTT broker iniciado en puerto 1883"

echo "[nexo] Arrancando nginx en puerto 8080 (en background)..."
nginx

# NLU local — clasificador jerárquico en localhost:8090 (mismo contenedor)
if [ -d /var/www/html/nlu_runtime ] && [ -x /opt/nlu-venv/bin/python ]; then
    echo "[nexo] Iniciando NLU local en :8090..."
    mkdir -p /var/www/html/infra/logs
    cd /var/www/html/nlu_runtime && /opt/nlu-venv/bin/python service.py >> /var/www/html/infra/logs/nlu.log 2>&1 &
    cd /var/www/html
fi

echo "[nexo] Configurando supercronic para tareas periódicas..."
mkdir -p /var/www/html/infra/logs
chmod +x /var/www/html/infra/scripts/recalc_risk.sh
chmod +x /var/www/html/infra/scripts/create_monthly_partition.sh
cp /var/www/html/infra/scripts/crontab /etc/supercronic/crontab
/usr/local/bin/supercronic /etc/supercronic/crontab > /dev/stdout 2>&1 &

echo "[nexo] Manteniendo contenedor vivo para debug..."
tail -f /dev/null
