#!/bin/sh
set -e

if [ -z "$DB_USER" ] || [ -z "$DB_PASSWORD" ] || [ -z "$DB_HOST" ] || [ -z "$DB_NAME" ]; then
    echo "ERROR: Faltan variables de entorno (DB_USER, DB_PASSWORD, DB_HOST, DB_NAME)."
    exit 1
fi

# Generar hash MD5 esperado por PgBouncer: md5 + md5(password + username)
HASH=$(echo -n "${DB_PASSWORD}${DB_USER}" | md5sum | awk '{print $1}')

# Crear archivo de usuarios con el formato exacto "username" "md5hash"
echo "\"${DB_USER}\" \"md5${HASH}\"" > /etc/pgbouncer/userlist.txt
chmod 600 /etc/pgbouncer/userlist.txt
chown pgbouncer:pgbouncer /etc/pgbouncer/userlist.txt

# Expandir variables en pgbouncer.ini usando envsubst
export DB_PORT=${DB_PORT:-6543}
envsubst < /etc/pgbouncer/pgbouncer.ini > /tmp/pgbouncer.ini
mv /tmp/pgbouncer.ini /etc/pgbouncer/pgbouncer.ini
chown pgbouncer:pgbouncer /etc/pgbouncer/pgbouncer.ini

echo "PgBouncer configurado y listo. Iniciando..."
exec su-exec pgbouncer pgbouncer /etc/pgbouncer/pgbouncer.ini
