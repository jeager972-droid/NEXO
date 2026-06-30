# Guía de Migración a Arquitectura Profesional (3 Dominios)

Este documento contiene el paso a paso de lo que debes hacer en un futuro cuando adquieras tus dominios web profesionales y estés listo para separar definitivamente la Landing Page de la API.

## Objetivo
Pasar del esquema **MVP actual** (donde la API y la Landing comparten el mismo servidor Nginx en Railway) a un **esquema profesional Desacoplado**:
1. **Landing Page:** `nexo.com` (Vercel)
2. **WebApp (Aplicación):** `app.nexo.com` (Vercel)
3. **API (Servidor PHP):** `api.nexo.com` (Railway)

---

## Paso 1: Configurar la API en modo "Puro" (Railway)
El servidor en Railway dejará de entregar el `index.html` de la Landing y se dedicará 100% a procesar datos JSON.

**Archivo a modificar:** `backend/api/docker-entrypoint.sh`
**Cambio a realizar:**
Debes buscar la configuración del bloque `server { ... }` de Nginx y eliminar el `catch-all` que sirve archivos estáticos. 
La línea que dice:
```nginx
try_files $uri $uri/ /api.php?$query_string;
```
Debe cambiar a:
```nginx
try_files /api.php?$query_string =404;
```
*(Esto evita que Nginx intente buscar un `index.html` y pasa el tráfico directo a la base de datos).*

## Paso 2: Desplegar la Landing en Vercel
1. Entras a tu cuenta de Vercel.
2. Agregas un "Nuevo Proyecto" y seleccionas tu repositorio de GitHub `NEXO`.
3. En la configuración del proyecto, configuras el **"Root Directory"** para que apunte a `landing`.
4. Vercel detectará que es Vite/React y lo compilará automáticamente.
5. Asignas tu dominio principal (ej: `nexo.com`) a este proyecto en Vercel.

## Paso 3: Configurar el Dominio de la WebApp
La WebApp ya está en Vercel, pero ahora necesita su propio subdominio y apuntar a tu nueva API.

**Archivo a modificar:** `.env` o `.env.production` dentro de la carpeta `WebApp/`
**Cambio a realizar:**
Actualizar la variable que apunta al backend para que mire a tu nuevo subdominio:
```env
VITE_API_URL=https://api.nexo.com
```
Luego, en Vercel, asignas el subdominio (ej: `app.nexo.com`) a este proyecto de la WebApp.

## Paso 4: Ajustar la Seguridad (CORS) en la API
Para evitar ataques, tu API debe permitir explícitamente que solo tus nuevos dominios puedan enviarle peticiones.

**Archivo a modificar:** `backend/api/routes/_cors_middleware.php`
**Cambio a realizar:**
Agrega tus nuevos dominios a la lista de orígenes permitidos:
```php
$allowed_origins = [
    'https://nexo.com',
    'https://app.nexo.com',
    // ... otros orígenes si existen
];
```

## Resumen de la nueva arquitectura
Cuando completes estos 4 pasos:
* **Landing Page:** Vivirá feliz y rapidísima en la red global CDN de Vercel.
* **WebApp:** Vivirá en Vercel, cargando ultrarrápido y haciendo peticiones a `api.nexo.com`.
* **Backend:** Vivirá en Railway (`api.nexo.com`), enfocado exclusivamente en gestionar la base de datos y la comunicación con las Raspberry Pi del colegio de forma segura.
