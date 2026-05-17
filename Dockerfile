# ── Stage 1: Build React WebApp ───────────────────────────────────────────────
FROM node:20-alpine AS builder
WORKDIR /build
COPY WebApp/package.json WebApp/package-lock.json* ./
RUN npm ci --ignore-scripts
COPY WebApp/ ./
RUN npm run build

# ── Stage 2: PHP built-in server (sin nginx, sin php-fpm) ─────────────────────
FROM php:8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions pdo_pgsql mysqli redis sockets

WORKDIR /var/www/html
COPY backend/alojamiento/composer.json backend/alojamiento/composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction
COPY backend/alojamiento/ ./
COPY --from=builder /build/dist ./app/

# Router: archivos estáticos → directo, /app/** → React SPA, resto → api.php
RUN printf '<?php\n\
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);\n\
$file = __DIR__ . $uri;\n\
if ($uri !== "/" && file_exists($file) && !is_dir($file)) return false;\n\
if (str_starts_with($uri, "/app")) { readfile(__DIR__ . "/app/index.html"); return; }\n\
require __DIR__ . "/api.php";\n' > /var/www/html/router.php

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} /var/www/html/router.php"]
