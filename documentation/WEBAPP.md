# WEBAPP.md — Aplicación web (PWA) de NEXO

Documentación técnica de la PWA de NEXO, basada en el código de `PWA/`.

## Responsabilidades

- Interfaz de usuario para todos los roles (RECTOR, COORDINATOR, TEACHER, SECRETARY, SECURITY, AUXILIARY, COUNSELOR, GUARDIAN).
- Operaciones de asistencia, evasión, riesgo, permisos, salidas, SOS, auditoría, configuración de institución.
- Onboarding guiado de la institución (horarios, grupos, riesgo, sensor master key).
- Comunicación con la API REST vía `axios` con interceptor de autenticación y refresh automático de JWT.
- PWA instalable con manifest y service worker.

## Stack

- React 18 (Vite 5, modo SPA con `react-router-dom`).
- `vite-plugin-pwa` (Workbox) para service worker, offline y background sync.
- TailwindCSS para estilos.
- Framer Motion para animaciones.
- Axios para HTTP.
- Vite como bundler/dev server.
- Vitest + Testing Library para tests.

## Estructura

```
PWA/
├── src/
│   ├── main.jsx                     # Bootstrap React + Router
│   ├── App.jsx                      # Definición de rutas + ProtectedRoute
│   ├── index.css                    # Tailwind + estilos globales
│   ├── config.js                    # Configuración (API base URL, etc.)
│   ├── api/                         # Cliente API (axios)
│   │   ├── client.js                # Instancia axios + interceptores
│   │   └── ...                      # Módulos por dominio (auth, students, dashboard, etc.)
│   ├── components/                  # Componentes reutilizables
│   │   ├── ui/                      # Botones, modales, inputs, tabs, etc.
│   │   ├── layout/                  # Sidebar, Topbar, AppShell
│   │   ├── forms/                   # Formularios
│   │   └── ...
│   ├── pages/                       # Páginas (una por ruta)
│   │   ├── Login.jsx
│   │   ├── Dashboard.jsx
│   │   ├── Asistencia.jsx
│   │   ├── Consulta.jsx             # /consulta (ex-/auditoria)
│   │   ├── Perfil.jsx               # /perfil (ex-/riesgo)
│   │   ├── Onboarding/              / Wizard de onboarding
│   │   ├── Configuracion.jsx
│   │   ├── ...
│   │   └── ...
│   ├── hooks/                       # Hooks custom (useAuth, useApi, etc.)
│   ├── context/                     # Contextos React (AuthContext, etc.)
│   ├── utils/                       # Utilidades
│   └── assets/                      # Imágenes, iconos
├── public/                          # Estáticos (manifest.json, icons, etc.)
├── index.html
├── vite.config.js
├── tailwind.config.js
├── postcss.config.js
├── .env                             # VITE_API_BASE_URL
├── .env.example
├── vercel.json                      # Deploy config
├── package.json
└── tests/                           # Vitest
```

## Rutas

Definidas en `src/App.jsx` con `react-router-dom`. La PWA se sirve bajo base `/app/` (configurada en `vite.config.js` y `vercel.json`), por lo que la URL pública real es `https://dominio/app/<ruta>`. Vercel reescribe las rutas legacy sin `/app/` a su equivalente con prefijo. Las rutas protegidas se envuelven en `<ProtectedRoute>` que valida sesión y, opcionalmente, `allowedRoles`.

| Ruta (sin base) | Página | Acceso |
|---|---|---|
| `/login` | Login | Público |
| `/unauthorized` | Unauthorized | Público |
| `/instalar/:platform` | InstallPage (PWA install) | Público |
| `/` | Dashboard | Autenticado |
| `/operacion` | Operation (asistencia/operaciones) | Autenticado |
| `/notificaciones` | Notifications | Autenticado |
| `/perfil` | Profile (riesgo del estudiante) | Autenticado |
| `/consulta` | Consultation (auditoría) | RECTOR, COORDINATOR, SECRETARY, TEACHER, COUNSELOR |
| `/casos` | Casos (seguimiento) | RECTOR, COORDINATOR, COUNSELOR |
| `/enrolamiento` | Enrollment | SECRETARY |
| `/dispositivos` | Devices | RECTOR, COORDINATOR |
| `/descargas` | Downloads | Autenticado |
| `/auditoria` | → redirect `/consulta` | — |
| `/riesgo` | → redirect `/perfil` | — |

Los roles se definen en `src/config/roles.js` (mapeo nombre-display → código backend: `RECTOR`, `COORDINATOR`→`COORDINATOR`, `DOCENTE`→`TEACHER`, `SECRETARIA`→`SECRETARY`, `PORTERO`→`SECURITY`, `AUXILIAR`→`AUXILIARY`, `PSICORIENTADOR`→`COUNSELOR`). La navegación del sidebar también se define ahí, por rol.

## Autenticación

### Flujo

1. `POST /auth/login` con email + password.
2. Si `status === '2fa_required'`, redirige a verificación OTP (WhatsApp).
3. `POST /auth/verify-2fa` con el código.
4. El backend setea cookies HttpOnly `token` (JWT access) y `refresh_token`.
5. Axios guarda el token en memoria (no en localStorage) para incluirlo en cabeceras si las cookies no llegan (cross-origin).
6. `GET /auth/me` carga el perfil.

### Interceptor de Axios (`src/api/client.js`)

- **Request**: añade `Authorization: Bearer <token>` si hay token en memoria, y `X-Requested-With: XMLHttpRequest` en métodos mutantes.
- **Response 401**: intenta `POST /auth/refresh` con el refresh token (cookie). Si tiene éxito, reenvía la petición original con el nuevo token. Si falla, redirige a `/login`.
- **Response 403 "Request forbidden"**: falta `X-Requested-With`; el interceptor lo añade y reintenta.
- **Credentials**: `withCredentials: true` para que las cookies HttpOnly se envíen cross-origin.

### Contexto de auth

`AuthContext` expone `{ user, login, logout, refreshUser, hasPermission }`. `useAuth()` es el hook de acceso. El contexto carga el usuario al montar si hay sesión válida (`GET /auth/me`).

## Configuración

`src/config.js`:

```js
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8080';
export const WHATSAPP_SUPPORT = import.meta.env.VITE_WHATSAPP_SUPPORT;
```

`.env`:

```
VITE_API_BASE_URL=https://nexo-80go.onrender.com
```

## PWA

- Configurada vía `vite-plugin-pwa` (Workbox) en `vite.config.js`.
- Manifest: nombre, iconos, theme color, display standalone, start_url `/app/`, scope `/app/`.
- Service worker generado por Workbox: precache de estáticos, runtime cache, fallback offline (`navigateFallback: /app/index.html`), background sync.
- `index.html` incluye `<link rel="manifest">` y meta tags PWA.
- Página de instalación dedicada en `/instalar/:platform`.

## Despliegue

### Vercel

`vercel.json`:

- Build command: `npm run build`.
- Output: `dist`.
- SPA fallback: rewrite todas las rutas a `/index.html`.
- Headers de seguridad: `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`.
- Cache de estáticos con hash inmutable.

### Build local

```bash
cd PWA
npm install
npm run dev      # dev server en http://localhost:5173
npm run build    # build de producción a dist/
npm run preview  # servir el build localmente
```

## Tests

Vitest + Testing Library en `tests/`:

```bash
cd PWA && npm test
```

Cobertura de componentes clave, hooks y flujos de autenticación. Más detalle en [TESTING.md](TESTING.md).

## Consideraciones de seguridad

- Tokens en cookies HttpOnly (no accesibles por JS).
- Refresh token rotation (cada refresh invalida el anterior).
- `withCredentials` + CORS estricto en el backend (`CORS_ALLOW_ORIGINS`).
- No se almacena nada sensible en localStorage.
- `X-Requested-With` como mitigación CSRF en métodos mutantes.
