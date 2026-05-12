FROM php:8.2.28-apache 

# 1. Instalar extensiones necesarias 
RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install mysqli pdo pdo_pgsql

# 2. Habilitar módulos de seguridad de Apache 
RUN a2enmod rewrite headers reqtimeout 

# 3. Deshabilitar listado de directorios y firma del servidor 
RUN echo "ServerTokens Prod" >> /etc/apache2/apache2.conf && \ 
    echo "ServerSignature Off" >> /etc/apache2/apache2.conf && \ 
    echo "<Directory /var/www/html>\n    Options -Indexes +FollowSymLinks\n    AllowOverride All\n</Directory>" >> /etc/apache2/apache2.conf && \ 
    echo "<IfModule mod_reqtimeout.c>\n    RequestReadTimeout header=20-40,MinRate=500 body=20,MinRate=500\n</IfModule>" >> /etc/apache2/apache2.conf 

# 4. Configurar PHP seguro (deshabilitar funciones peligrosas, ocultar versión) 
RUN echo "expose_php = Off" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "display_errors = Off" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "log_errors = On" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "error_log = /dev/stderr" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "disable_functions = exec,passthru,shell_exec,system,proc_open,popen" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "max_input_time = 30" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "max_execution_time = 30" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "post_max_size = 16K" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "upload_max_filesize = 0" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "file_uploads = Off" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "session.cookie_httponly = 1" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "session.cookie_secure = 1" >> /usr/local/etc/php/conf.d/security.ini && \ 
    echo "session.use_strict_mode = 1" >> /usr/local/etc/php/conf.d/security.ini 

# 5. Copiar archivos de la aplicación (respeta .dockerignore) 
COPY . /var/www/html/ 

# 6. Ajustar permisos (principio de mínimo privilegio) 
RUN chown -R www-data:www-data /var/www/html && \ 
    chmod -R 444 /var/www/html/*.php && \ 
    chmod -R 444 /var/www/html/*.html && \ 
    chmod -R 444 /var/www/html/*.css && \ 
    chmod -R 444 /var/www/html/.htaccess && \ 
    chmod +x /var/www/html/start.sh 

# 7. Iniciar mediante el script 
ENTRYPOINT ["/var/www/html/start.sh"]
