FROM alpine:3.18

# Instalar pgbouncer, su-exec y gettext (para envsubst)
RUN apk add --no-cache pgbouncer su-exec gettext

# El paquete pgbouncer en alpine 3.18 no crea el usuario — crearlo nosotros
RUN addgroup -S pgbouncer 2>/dev/null; adduser -S -D -H -G pgbouncer pgbouncer 2>/dev/null; true

COPY pgbouncer.ini /etc/pgbouncer/pgbouncer.ini
COPY entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh && \
    chown -R pgbouncer:pgbouncer /etc/pgbouncer

EXPOSE 6432

ENTRYPOINT ["/entrypoint.sh"]
