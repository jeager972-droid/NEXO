#!/bin/bash
# Iniciar PHP-FPM en segundo plano
php-fpm -D

# Iniciar Nginx en primer plano
nginx -g "daemon off;"
