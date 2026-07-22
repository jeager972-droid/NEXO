# Política CORS de NEXO y excepción para previews de Vercel

> **Advertencia:** Esta excepción fue diseñada específicamente para el proyecto NEXO. No debe copiarse a otros proyectos sin entender las implicaciones de seguridad.

---

## 1. Cómo funciona CORS en NEXO

El punto de entrada de la API es `backend/api/api.php`. Lo primero que hace es incluir el middleware CORS:

```php
require_once __DIR__ . '/routes/_cors_middleware.php';
```

Ese middleware se encarga de:

1. Leer `HTTP_ORIGIN` de la petición entrante.
2. Leer la variable de entorno `CORS_ALLOW_ORIGINS` (lista separada por comas).
3. Comprobar si el origen está autorizado.
4. Si está autorizado, emitir los headers necesarios:
   - `Access-Control-Allow-Origin: <origen exacto>`
   - `Access-Control-Allow-Credentials: true`
   - `Access-Control-Max-Age: 86400`
5. Interceptar peticiones `OPTIONS` (preflight) respondiendo `204 No Content` con los métodos y headers permitidos.

**Regla de oro:** `Access-Control-Allow-Origin` siempre refleja el origen recibido. **Nunca** se emite `Access-Control-Allow-Origin: *` cuando se usan cookies o autenticación.

---

## 2. Por qué existía el problema con las previews de Vercel

Vercel genera un subdominio aleatorio para cada despliegue de preview. Por ejemplo:

```
https://nexo-gsug3f5v6-jeager972-droids-projects.vercel.app
```

El anterior middleware solo comparaba el origen de forma **exacta** contra `CORS_ALLOW_ORIGINS`. Como el subdominio de preview cambia en cada push, era imposible mantenerlo manualmente en la variable de entorno. Cada preview nueva fallaba con:

```
No 'Access-Control-Allow-Origin' header is present on the requested resource.
```

Esto bloqueaba el desarrollo y las pruebas en previews, pero el dominio de producción seguía funcionando si estaba en `CORS_ALLOW_ORIGINS`.

---

## 3. Solución implementada

Se añadió una excepción estricta en `_cors_middleware.php` mediante la función `_nexoIsOriginAllowed()`.

### 3.1 Prioridad de validación

1. **Lista blanca exacta** (`CORS_ALLOW_ORIGINS`).
   - Si el origen coincide exactamente con uno de los valores configurados, se autoriza.
   - Esta lista sigue siendo el mecanismo principal para producción, staging y dominios estables.

2. **Excepción para previews de Vercel del proyecto NEXO**.
   - Si el origen no está en la lista blanca, se evalúa contra el patrón:

     ```
     ^https://nexo-[a-zA-Z0-9-]+\.vercel\.app$
     ```

   - El patrón exige:
     - Esquema `https://`.
     - Host bajo exactamente `.vercel.app`.
     - Un único subdominio que comience con `nexo-`.
     - El resto del subdominio solo puede contener letras, números y guiones (sin puntos, sin sub-subdominios).

### 3.2 Ejemplos de orígenes aceptados

| Origen | ¿Aceptado? | Razón |
|---|---|---|
| `https://nexo-gsug3f5v6-jeager972-droids-projects.vercel.app` | Sí | Preview de Vercel para NEXO |
| `https://nexo-abc123.vercel.app` | Sí | Cumple el patrón de preview |
| `https://app.nexo.com` | Sí | Si está en `CORS_ALLOW_ORIGINS` |
| `https://nexo.com` | Sí | Si está en `CORS_ALLOW_ORIGINS` |

### 3.3 Ejemplos de orígenes rechazados

| Origen | ¿Aceptado? | Razón |
|---|---|---|
| `https://malicious.vercel.app` | No | No comienza con `nexo-` |
| `https://nexoevil.vercel.app` | No | No comienza con `nexo-` exacto |
| `https://nexo-foo.bar.vercel.app` | No | Múltiples subdominios (contiene un punto) |
| `https://nexo-anything.evil.com` | No | No es `.vercel.app` |
| `https://nexo-foo.attacker.vercel.app` | No | Múltiples niveles (contiene un punto) |
| `*` | No | Nunca se usa wildcard en la respuesta |
| `https://nexo-foo.vercel.app.evil.com` | No | El sufijo no es exactamente `.vercel.app` |

---

## 4. Por qué la solución es segura

### 4.1 No es un wildcard general

El patrón no es `*.vercel.app`. Requiere:

- Un subdominio **único** (sin puntos).
- Que ese subdominio empiece exactamente con `nexo-`.
- Que el TLD sea exactamente `.vercel.app`.

Esto descarta ataques como:

```
https://nexo-foo.attacker.vercel.app   (múltiples subdominios)
https://nexo-foo.evil.com              (dominio distinto)
https://malicious.vercel.app           (prefijo distinto)
```

### 4.2 Reflejo exacto del origen

Aunque se acepte el origen, el header enviado al navegador es:

```
Access-Control-Allow-Origin: https://nexo-<hash>.vercel.app
```

Nunca:

```
Access-Control-Allow-Origin: *
```

Esto es crítico porque el frontend usa `withCredentials: true` para enviar cookies HttpOnly. `*` combinado con credenciales está prohibido por la especificación CORS y sería un agujero de seguridad.

### 4.3 Vercel controla el namespace

Vercel asigna los subdominios de preview bajo su propio dominio `vercel.app`. Un atacante externo no puede registrar un subdominio `nexo-*` bajo `vercel.app` para suplanta al proyecto NEXO. Aún así, el patrón añade una capa extra de defensa restringiendo el prefijo al proyecto.

### 4.4 No se modifica autenticación, sesiones ni cookies

CORS no controla si el backend acepta o rechaza una petición; solo controla si el navegador permite al frontend leer la respuesta. La autenticación JWT, las cookies `HttpOnly`/`SameSite` y la lógica de sesiones permanecen intactas.

---

## 5. Cómo modificar la política en el futuro

Si en el futuro cambia el nombre del proyecto o se necesita permitir otra familia de previews, edita `backend/api/routes/_cors_middleware.php` y modifica el patrón en la función `_nexoIsOriginAllowed()`.

**Reglas para no romper la seguridad:**

- Nunca uses `*`.
- Nunca relajes el patrón a `*.vercel.app`.
- Nunca permitas dominios arbitrarios.
- Siempre ancla el patrón a un dominio específico (por ejemplo `\.vercel\.app$`).
- Siempre refleja el origen exacto en `Access-Control-Allow-Origin`.
- Siempre documenta por qué existe cualquier excepción nueva.

Para dominios estables (producción, staging, custom domains), sigue usando `CORS_ALLOW_ORIGINS` en lugar del patrón.

---

## 6. Resumen

- **Problema:** las previews de Vercel cambian de subdominio en cada despliegue y el middleware de CORS hacía comparación exacta.
- **Solución:** mantener la comparación exacta y añadir una excepción estricta para `https://nexo-*.vercel.app` (patrón regular anclado).
- **Seguridad:** no es un wildcard, no acepta dominios arbitrarios, no usa `*` y refleja el origen exacto.
- **Alcance:** diseñado únicamente para el proyecto NEXO y sus previews en Vercel.
