#!/bin/sh
set -e

if [ -z "$DB_USER" ] || [ -z "$DB_PASSWORD" ] || [ -z "$DB_HOST" ] || [ -z "$DB_NAME" ]; then
    echo "ERROR: Faltan variables de entorno (DB_USER, DB_PASSWORD, DB_HOST, DB_NAME)."
    exit 1
fi

# PgBouncer con auth_type=scram-sha-256 (Postgres 15): el userlist lleva el
# password en CLARO para que pgbouncer pueda responder SCRAM hacia el servidor.
echo "\"${DB_USER}\" \"${DB_PASSWORD}\"" > /etc/pgbouncer/userlist.txt
chmod 600 /etc/pgbouncer/userlist.txt
chown pgbouncer:pgbouncer /etc/pgbouncer/userlist.txt

# Expandir variables en pgbouncer.ini usando envsubst
export DB_PORT=${DB_PORT:-5432}
envsubst < /etc/pgbouncer/pgbouncer.ini > /tmp/pgbouncer.ini
mv /tmp/pgbouncer.ini /etc/pgbouncer/pgbouncer.ini
chown pgbouncer:pgbouncer /etc/pgbouncer/pgbouncer.ini

echo "PgBouncer configurado y listo. Iniciando..."
exec su-exec pgbouncer pgbouncer /etc/pgbouncer/pgbouncer.ini
