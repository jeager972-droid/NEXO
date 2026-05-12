#!/bin/bash 

# 1. LIMPIEZA TOTAL DE MPM (HARD-FIX PARA RAILWAY) 
# Borramos físicamente cualquier módulo que no sea prefork justo antes de arrancar 
find /etc/apache2/mods-enabled -name "mpm_*.load" ! -name "mpm_prefork.load" -delete 
find /etc/apache2/mods-enabled -name "mpm_*.conf" ! -name "mpm_prefork.conf" -delete 

# 2. ASEGURAR PREFORK ACTIVO 
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/ 
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/ 

# 3. CONFIGURAR PUERTO DINÁMICO 
# Usamos el puerto dinámico de Railway para Listen y VirtualHost 
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf 
sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/000-default.conf 

# 4. ARRANCAR APACHE EN PRIMER PLANO 
exec apache2-foreground
