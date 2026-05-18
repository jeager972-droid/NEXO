#!/bin/sh
set -e

PORT="${PORT:-8080}"

# Reescribe ports.conf completamente — sin pattern matching, garantizado
printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf

# Actualiza el VirtualHost con regex que atrapa cualquier puerto
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

# MPM fix: garantiza que solo mpm_prefork está activo justo antes de arrancar
rm -f /etc/apache2/mods-enabled/mpm_*
ln -sf /etc/apache2/mods-available/mpm_prefork.load \
       /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf \
       /etc/apache2/mods-enabled/mpm_prefork.conf

exec apache2-foreground
