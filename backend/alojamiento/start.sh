#!/bin/sh
set -e

PORT=${PORT:-80}

sed -i "s/listen 80;/listen $PORT;/g" /etc/nginx/conf.d/default.conf

php-fpm -D
sleep 2
nginx -g 'daemon off;'
