#!/bin/sh
set -e

PORT="${PORT:-8080}"
echo "[nexo] Configurando Apache en puerto: $PORT"

# Reescribe ports.conf COMPLETAMENTE — heredoc, sin pattern matching
cat > /etc/apache2/ports.conf <<CONF
Listen ${PORT}
CONF

# Reescribe VirtualHost COMPLETAMENTE — heredoc, sin pattern matching
# \${APACHE_LOG_DIR} se escapa para que Apache lo expanda, no el shell
cat > /etc/apache2/sites-available/000-default.conf <<CONF
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
CONF

# MPM fix: elimina todos, activa solo prefork
rm -f /etc/apache2/mods-enabled/mpm_*
ln -sf /etc/apache2/mods-available/mpm_prefork.load \
       /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf \
       /etc/apache2/mods-enabled/mpm_prefork.conf

echo "[nexo] Apache listo, arrancando en puerto $PORT..."
exec apache2-foreground
