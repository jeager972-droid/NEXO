# PWA — Aplicación institucional NEXO

Documentación técnica de la PWA de NEXO (`frontend/pwa/`, paquete
`webapp-institucional` v1.0.0-rc.1): la aplicación principal donde trabajan los
7 roles institucionales. Visión general y comparación con la landing en
[`../README.md`](../README.md); especificación de diseño (autoridad) en
[`../design-philosophy/`](../design-philosophy/). Este documento reemplaza y
actualiza a `docs/WEBAPP.md`.

> **NEXO no muestra datos. NEXO detecta situaciones y propone acciones.**

## Contenido

1. [Stack y scripts](#stack-y-scripts)
2. [Arranque rápido y variables de entorno](#arranque-rápido-y-variables-de-entorno)
3. [Arquitectura de `src/`](#arquitectura-de-src)
4. [Roles y rutas protegidas](#roles-y-rutas-protegidas)
5. [Mapa páginas × roles](#mapa-páginas--roles)
6. [Capa API](#capa-api)
7. [Chat «Pregúntale a Nexus»](#chat-pregúntale-a-nexus)
8. [Sistema de diseño](#sistema-de-diseño)
9. [PWA: manifest, service worker e instalación](#pwa-manifest-service-worker-e-instalación)
10. [Onboarding](#onboarding)
11. [Telemetría](#telemetría)
12. [Testing](#testing)
13. [Build, lint, Storybook y deploy](#build-lint-storybook-y-deploy)
14. [Convenciones y estado del código](#convenciones-y-estado-del-código)

## Stack y scripts

| Pieza | Versión / rol |
|---|---|
| React | 18 (JSX, sin TypeScript) |
| Vite | 5 (`vite.config.js`, `base: '/app/'`) |
| react-router-dom | 6.23, `BrowserRouter basename="/app"` |
| Tailwind CSS | 3.4 (`tailwind.config.js` + `postcss.config.js`) |
| Framer Motion | 11 (transiciones, drawers, chat, stepper) |
| Axios | 1.x (`src/api/client.js` + 17 módulos de dominio) |
| vite-plugin-pwa | 0.20, genera el service worker con Workbox |
| lucide-react | Iconografía |
| @fontsource-variable/inter | Tipografía Inter Variable |
| Vitest + Testing Library + jsdom | Suite de pruebas (`src/__tests__/`) |
| clsx + tailwind-merge | Helper `cn()` (`src/utils/cn.js`) |

Scripts (`package.json`):

```bash
npm run dev          # dev server Vite
npm run build        # build de producción → dist/
npm run preview      # servir el build localmente
npm test             # vitest run (563 pruebas en 50 archivos)
npm run test:watch   # vitest en modo watch
npm run lint         # eslint . --ext js,jsx
npm start            # npx serve dist -s -l 3000
```

## Arranque rápido y variables de entorno

```bash
cd frontend/pwa
npm install
npm run dev
```

Variables (`import.meta.env`, ver `.env.example`):

| Variable | Uso |
|---|---|
| `VITE_API_BASE_URL` | Base del backend PHP. Obligatoria en producción: `src/api/client.js` aborta el build si no empieza por `https://`. Desarrollo: `http://localhost:18080` (`.env.development.local`); producción apunta a Render (`.env.production.local`). |
| `VITE_APP_ENV` | Etiqueta de entorno (`development`/`production`). |
| `VITE_APP_VERSION` | Versión que reporta la telemetría (`src/api/telemetry.js`). |
| `VITE_SCHEDULE_TASK_TESTING` | Solo desarrollo: muestra `ScheduleTask` las 24 h (`src/components/patterns/ScheduleTask.jsx`). Nunca en producción. |

## Arquitectura de `src/`

La estructura sigue la arquitectura por capas de
`../design-philosophy/12_FRONTEND_IMPLEMENTATION_GUIDE.md`
(foundations → primitives → components → patterns → shells → features → pages):

```
src/
├── main.jsx        # Entry: monta React, basename /app, providers, registerSW, limpieza de caches viejas
├── App.jsx         # Router SPA: lazy loading, ProtectedRoute por rol, rutas públicas, PWA install prompt
├── index.css       # Tokens --nx-* (light + .dark), focus-visible, utilidades de animación
├── api/            # client.js (axios) + 17 clientes de dominio
├── components/
│   ├── ui/         # Primitivas CMP-001..041: Button, Input, Select, SearchableSelect, Card,
│   │               #   Badge, Overlay (Drawer/Dialog/Confirm), Skeleton, Stepper, Surface,
│   │               #   EmptyState, IconButton
│   ├── patterns/   # Patrones NEXO CMP-100+/CMP-NEXO: SituationLine, StatCard, StudentItem,
│   │               #   RiskBadge, StatusDot, GroupSelector, ScheduleTask, OperationResult,
│   │               #   NexoChat/NexoMessage (burbujas), NexusGuide, NexusInsights,
│   │               #   SystemInactiveScreen
│   ├── ErrorBoundary.jsx   # Límite de error por página
│   ├── LogoNexo.jsx        # Logotipo (imagen + fallback)
│   └── PwaInstallPrompt.jsx# Botón flotante de instalación (beforeinstallprompt)
├── config/
│   ├── roles.js    # Fuente única: ROLES, SIDEBAR_ITEMS, OPERATION_COMMANDS, PRIMARY_ACTIONS
│   └── grados.js   # GRADO_OPTIONS (6.º–11.º)
├── context/        # AuthContext, NotificationContext, ThemeContext
├── hooks/          # useAuth (consumidor de AuthContext), useOverlay (focus trap + Escape)
├── layout/         # Layout.jsx (shell: topbar + sidebar + barra inferior), Sidebar.jsx (CMP-030)
├── lib/            # chatContext.js — memoria de contexto del chatbot (sessionStorage, TTL 30 min)
├── pages/          # 17 archivos: pantallas SCR-*, TrackingModal, onboarding/OnboardingFlow
│                   #   y heredados sin ruta (Consultation*, RiskConfig — ver notas finales)
├── routes/         # ProtectedRoute.jsx (guardia auth + allowedRoles)
├── store/          # userStore.js — singleton en memoria del usuario actual
├── utils/          # cn, messages (humanizeError), exporters (CSV/Excel/Word),
│                   #   formatters, groupFormat, jwt, mobilePermissions
└── __tests__/      # Suite Vitest (api/, components/, config/, context/, hooks/,
                    #   pages/, routes/, utils/) + setup.js
```

**Convención de cabeceras:** cada archivo declara responsabilidad, autoridad
(documento de `design-philosophy/`), IDs (`CMP-*`, `SCR-*`, `DEC-*`, `FLOW-*`) y
dependencias. Mantenerla al crear archivos nuevos.

## Roles y rutas protegidas

`src/config/roles.js` es la fuente única de verdad. Los códigos deben coincidir
exactamente con el backend:

| Constante | Código backend | Nombre visible |
|---|---|---|
| `ROLES.RECTOR` | `RECTOR` | Rector |
| `ROLES.COORDINADOR` | `COORDINATOR` | Coordinador |
| `ROLES.DOCENTE` | `TEACHER` | Docente |
| `ROLES.SECRETARIA` | `SECRETARY` | Secretaria |
| `ROLES.PORTERO` | `SECURITY` | Portero |
| `ROLES.AUXILIAR` | `AUXILIARY` | Auxiliar |
| `ROLES.PSICORIENTADOR` | `COUNSELOR` | Psicorientador |

`routes/ProtectedRoute.jsx` exige sesión (`useAuth`) y, si se pasan
`allowedRoles`, redirige a `/unauthorized` cuando el rol no está incluido. Las
rutas sin sesión van a `/login`.

Rutas definidas en `src/App.jsx` (la URL pública real lleva el prefijo `/app/`):

| Ruta | Página | Acceso |
|---|---|---|
| `/login` | `pages/Login.jsx` (SCR-AUTH-01/02: login + 2FA) | Público (si hay sesión → `/`) |
| `/unauthorized` | `pages/Unauthorized.jsx` (SCR-AUTH-03) | Público |
| `/instalar/:platform` | `pages/InstallPage.jsx` | Público (se monta fuera del Layout para no perder `deferredPrompt`) |
| `/descargas` | `pages/Downloads.jsx` (SCR-DWN-01) | Público |
| `/` | `pages/Dashboard.jsx` (SCR-HOME-01) | Autenticado |
| `/operacion` | `pages/Operation.jsx` (SCR-OPS-01/02/03) | Autenticado (comandos filtrados por rol) |
| `/notificaciones` | `pages/Notifications.jsx` (SCR-NOT-01) | Autenticado |
| `/perfil` | `pages/Profile.jsx` (SCR-PRO-01) | Autenticado |
| `/chat` | `pages/Chat.jsx` (SCR-CHAT-01 «Pregúntale a Nexus») | Autenticado |
| `/casos` | `pages/Seguimiento.jsx` (SCR-CAS-01) | RECTOR, COORDINATOR, COUNSELOR |
| `/enrolamiento` | `pages/Enrollment.jsx` (SCR-ENR-01/02) | SECRETARY |
| `/dispositivos` | `pages/Devices.jsx` (SCR-DEV-01) | RECTOR |
| `/consulta` | redirect → `/chat` | «Consultas» fue absorbido por el chat |
| `/auditoria` | redirect → `/consulta` → `/chat` | Compatibilidad |
| `/riesgo` | redirect → `/perfil` | Compatibilidad |
| `*` | redirect → `/` | — |

### Navegación por rol

- `SIDEBAR_ITEMS` (config/roles.js) define las 8 entradas del sidebar con su
  lista de roles; `layout/Sidebar.jsx` (CMP-030) las filtra.
- `PRIMARY_ACTIONS` define las 4 acciones de la barra inferior por rol
  (p. ej. Docente: Inicio, Operaciones, Chat, Notificaciones).
- Roles operativos (DOCENTE, PORTERO, AUXILIAR) navegan solo por la barra
  inferior en móvil; el drawer lateral existe para todos (`Layout.jsx`).
- `OPERATION_COMMANDS` declara qué operaciones ve cada rol en el catálogo de
  `/operacion` (la página usa su propio `COMMANDS_CATALOG` con tonos
  accent/warning/danger y campos por comando — ver
  [Operaciones](#operaciones)).

## Mapa páginas × roles

| Página | RECTOR | COORDINATOR | TEACHER | SECRETARY | SECURITY | AUXILIARY | COUNSELOR |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Dashboard `/` | ● | ● | ● | ● | ● | ● | ● |
| Operación `/operacion` | ● | ● | ● | ● | ● | ● | ● |
| Notificaciones | ● | ● | ● | ● | ● | ● | ● |
| Perfil | ● | ● | ● | ● | ● | ● | ● |
| Chat «Pregúntale a Nexus» | ● | ● | ● | ● | ● | ● | ● |
| Seguimientos `/casos` | ● | ● | | | | | ● |
| Enrolamiento | | | | ● | | | |
| Sensores `/dispositivos` | ● | | | | | | |

Comandos de operación por rol (`COMMANDS_CATALOG` en `pages/Operation.jsx`,
cada uno con campos, tono semántico y descripción):

| Comando | Tono | Roles |
|---|---|---|
| Citar acudiente | accent | RECTOR, COORDINATOR, TEACHER, COUNSELOR |
| Mandar solicitud | accent | Todos |
| Solicitar seguimiento | accent | RECTOR, COORDINATOR, TEACHER, COUNSELOR |
| Fusionar bloque | accent | TEACHER |
| Autorizar salida | warning | RECTOR, COORDINATOR |
| Reportar daño | warning | AUXILIARY, SECURITY, RECTOR, COORDINATOR |
| Salida pedagógica | warning | RECTOR, COORDINATOR, TEACHER |
| Cambio de horario | warning | RECTOR, COORDINATOR, TEACHER |
| Generar permiso | warning | TEACHER, COORDINATOR, RECTOR |
| Extender bloque | warning | RECTOR, COORDINATOR |
| Registro manual | warning | TEACHER, COORDINATOR, SECRETARY, SECURITY, RECTOR |
| Situación Crítica | danger | Todos |
| Reportar incidente | danger | TEACHER, COUNSELOR, RECTOR, COORDINATOR |

## Capa API

`src/api/client.js` es la instancia global de Axios; todos los módulos la
importan. Comportamiento:

- `baseURL = VITE_API_BASE_URL`; el backend normaliza rutas, se usa la raíz
  (`/auth/*`, `/operations/*`, `/chat/*`, …).
- `withCredentials: true` — cookies HttpOnly cross-origin.
- Cabecera `X-Requested-With: XMLHttpRequest` en todas las peticiones
  (mitigación CSRF).
- **Timeout adaptativo**: 12 s por defecto; 30 s para rutas lentas
  (`/operations/`, `/reports/`).
- **Fallback iOS/ITP**: si existe `nexo:auth-token` en localStorage se envía
  como `Authorization: Bearer` (workaround para Safari/ITP que bloquea cookies
  cross-site; marcado TODO para eliminar cuando front y back sean same-site).
- **401 → refresh**: intenta `POST /auth/refresh` con el refresh token,
  reenvía la petición original y serializa refreshes concurrentes
  (`isRefreshing` + cola de suscriptores). Si falla, limpia tokens y emite
  `nexo:auth-logout` (salvo en rutas públicas).
- **Telemetría de latencia**: cada respuesta emite `nexo:telemetry`
  (`API_LATENCY`) con path, método, status y duración.
- Verificación proactiva: si el JWT expira en <5 min emite `nexo:token-check`.

Módulos de dominio (17 clientes sobre `client.js`):

| Módulo | Endpoints principales |
|---|---|
| `api/auth.js` | `POST /auth/login`, `/auth/verify-2fa`, `/auth/logout`, `/auth/refresh`, `GET /auth/me` |
| `api/dashboard.js` | `GET /dashboard/stats`, `/dashboard/events`, `/dashboard/insights`, `/dashboard/teacher-group-detail` |
| `api/operations.js` | `POST /operations/{cmd}` (envuelve `{action:'EXECUTE_COMMAND',command,params}`), `POST /operations/twilio-status` |
| `api/notifications.js` | `GET /notifications`, `POST /notifications`, `/notifications/read-all`, `/notifications/clear` |
| `api/chat.js` | `POST /chat/message`, `GET /chat/history`, `/chat/sessions`, `/chat/policies` (+ `POST /chat/policies`) |
| `api/students.js` | `GET /students` (paginado/búsqueda), `GET /groups`, `POST /students`, `/students/bulk-assign` |
| `api/tracking.js` | `POST /tracking/start`, `/tracking/notes`, `/tracking/derive`, `GET /tracking/active`, `/tracking/details` |
| `api/risk.js` | `GET/POST /risk/policy`, `/risk/alerts`, `/risk/event-types`, `/risk/justify`, `/risk/recalculate`, `/risk/policy/history` |
| `api/school.js` | `GET/PUT /school/config`, onboarding (`/school/onboarding`, `/groups-onboarding`, `/risk-config`, `/sensor-master-key`, `/time-blocks`, `/technical-modality`, `/assign-teacher`) |
| `api/devices.js` | `GET/POST /devices`, `/devices/by-role`, `/devices/revocations/pending`, `/devices/reassign`, `/devices/reprovision` + comandos M2M (ENROLL_REQUEST, AUTHORIZE_EXIT…) vía API → MQTT/Redis → nexo-edge |
| `api/audit.js` | ~35 consultas `/audit/*`: asistencia, disciplina, permisos, mensajería WhatsApp, actividad docente, seguridad, SOS, histórico, reportes |
| `api/consultations.js` | `POST /consultations/query`, `GET /consultation/search` (soporta AbortSignal) |
| `api/reports.js` | `GET /reports/preview` + exportación con rango de fechas |
| `api/behavior.js` | `GET /behavior/risk` (riesgo conductual) |
| `api/teacher.js` | `GET/POST /teacher/alert-rules` (criterios de aviso), `GET/POST /teacher/onboarding` |
| `api/users.js` | `GET /users/by-role`, `/users/me/extended`, `/users/me/photo`, `POST /users/update-profile`, `/upload-photo`, `/send-verification`, `/verify-code`, `/change-password`, `/reset-password`, `/delete-field` |
| `api/telemetry.js` | `POST /telemetry` (ver [Telemetría](#telemetría)) |

### Autenticación

`context/AuthContext.jsx` es la fuente de verdad (`authApi.getMe()`), con flujo
2FA (WhatsApp OTP), logout y el fallback de tokens en localStorage para iOS PWA
standalone. `hooks/useAuth.js` es el consumidor obligatorio (lanza error fuera
del provider). `store/userStore.js` expone el usuario fuera de React.
`context/NotificationContext.jsx` hace polling de notificaciones cada 60 s y
calcula el badge por `read_at` del servidor (sobrevive sesiones/dispositivos).
`context/ThemeContext.jsx` alterna `.dark` en `<html>` con persistencia.

## Chat «Pregúntale a Nexus»

`pages/Chat.jsx` (SCR-CHAT-01) es la interfaz del chatbot del backend. Reglas
de diseño que implementa:

- **El texto libre nunca ejecuta operaciones.** El backend responde
  `{reply, intent, confidence, cards?, actions?, denied?}`; las `actions` son
  botones que navegan (`kind:'nav'` → `navigate(a.to)`) o exportan la card del
  mensaje (`kind:'export'` → `utils/exporters.js`: CSV/Excel/Word).
- **Deep-links a operaciones**: las acciones `nav` apuntan a
  `/operacion?cmd=<título>&student=<id>&group=<id>`; `Operation.jsx` lee
  `cmd` para preseleccionar el comando (por título) y `student`/`group` para
  precargar el formulario. La confirmación final siempre vive en la página de
  operación.
- **Cards**: `DataCard` renderiza tablas paginadas (10 filas/página) con
  `aria-live`, `caption` accesible y columnas declaradas por el backend.
- **Contexto conversacional**: `lib/chatContext.js` guarda en sessionStorage
  (TTL 30 min) la última intención + entidades (`student`, `group`, `module`,
  `days`, `from`, `to`, …) para resolver referencias («su grupo», «cuéntame
  otro»); `injectCtx` las envía transparentes en `ctx` y el backend las marca
  `_inherited`.
- **Sesiones**: `chatApi.sessions()` lista conversaciones previas en un drawer;
  la más reciente reanuda el hilo.
- Componentes de la «voz de Nexus» reutilizados fuera del chat:
  `NexoChatBubble`/`NexoAvatar` (`patterns/NexoChat.jsx`),
  `NexoMessage` (CMP-025), `NexusGuide` (asistente del onboarding),
  `NexusInsights` (insights del dashboard como conversación).

## Sistema de diseño

Implementación literal de `../design-philosophy/03_DESIGN_SYSTEM.md`:

- **Tokens `--nx-*`** en `src/index.css`: color en OKLCH (`--nx-canvas`,
  `--nx-surface`, `--nx-text`, `--nx-accent`, `--nx-success/warning/danger`),
  superficies por tono con `color-mix`, radios (`--nx-radius-control/surface/
  panel` = 8/12/16 px), sombras (`--nx-shadow-low…dialog`), shell
  (`--nx-sidebar: 264px`, `--nx-topbar: 64px`), ring de foco 3 px,
  `--nx-font-scale` (tamaño de fuente accesible, persiste en localStorage).
- **Tema oscuro**: bloque `.dark` en `index.css` + `darkMode: 'class'` en
  `tailwind.config.js`; lo conmuta `ThemeContext` (toggle en Layout/Sidebar).
- **Tailwind**: paleta semántica (`accent/success/warning/danger`, `canvas`,
  `surface`, `ink`, `line`), escala tipográfica (`text-display…text-eyebrow`),
  duraciones (`duration-fast/standard/deliberate/max` = 150/200/250/300 ms),
  curva `ease-out` = `cubic-bezier(0.22,1,0.36,1)`, animaciones `animate-seal`,
  `animate-halo`, `animate-pulse-bio`, `animate-scan`, `animate-skeleton`
  (08_MICRO_INTERACTIONS.md).
- **Accesibilidad** (09_ACCESSIBILITY.md, objetivo WCAG 2.2 AA):
  `:focus-visible` global, skip link en `index.html`, `useOverlay`
  (focus trap + Escape + devolver foco), estado nunca solo por color,
  targets ≥44 px.
- **Mensajes**: `utils/messages.js` (`humanizeError`) traduce cualquier error a
  lenguaje institucional — «nunca Error 500»
  (01_PRODUCT_DESIGN_PHILOSOPHY.md).

## PWA: manifest, service worker e instalación

Configurada con `vite-plugin-pwa` (Workbox) en `vite.config.js`:

- **Manifest** (`/app/manifest.webmanifest`): nombre NEXO, `display:
  standalone`, `start_url`/`scope` `/app/`, `theme_color #1e40af`,
  `lang es-CO`, iconos 192/512/180 + maskable, shortcuts a Panel y Operación.
- **Registro**: `registerSW({immediate:true})` en `main.jsx` con chequeo de
  actualización cada hora; `registerType:'autoUpdate'`, `skipWaiting`,
  `clientsClaim` y `cleanupOutdatedCaches` para activar versiones sin cerrar
  pestañas. Al cargar se borran caches Workbox obsoletas.
- **Runtime caching**:
  | Recurso | Estrategia | Detalle |
  |---|---|---|
  | `POST /v1*` | NetworkOnly + BackgroundSync | cola `nexo-post-queue`, retención 24 h |
  | `GET /v1*` | NetworkFirst | `nexo-api-cache`, timeout 3 s, ≤30 entradas, 2 min |
  | scripts/styles | CacheFirst | `nexo-hashed-assets`, 90 días |
  | imágenes | StaleWhileRevalidate | `nexo-images`, 7 días |
- **Offline**: `navigateFallback: /app/index.html`.
- **Instalación**: `App.jsx` captura `beforeinstallprompt` en
  `window.__nexoPwaPrompt`; `components/PwaInstallPrompt.jsx` muestra el botón
  flotante; `/instalar/:platform` (`InstallPage`) y `/descargas`
  (`Downloads`, SCR-DWN-01) dan instrucciones por plataforma
  (Android/iOS/Windows/macOS/Linux). La landing enlaza estas rutas.

## Onboarding

`pages/onboarding/OnboardingFlow.jsx` reemplaza los antiguos modales de
configuración: **el onboarding ES la pantalla** — nada del sistema se muestra
hasta completarlo (o quedar pendiente de otro rol). Lo decide `Layout.jsx`
consultando `schoolApi.getConfig()`, `getGroupsOnboarding()`,
`getRiskConfig()` y, para docentes, `teacherApi.getOnboarding()`; al terminar
re-verifica contra el backend (`refetchOnboarding`).

| Rol | Flujo |
|---|---|
| RECTOR / COORDINATOR | bienvenida → jornadas (mañana/tarde/noche/completa, con bloques y descanso) → grupos (editable solo RECTOR) → motor de riesgo (niveles LEVE→MUY_ALTA, tipos de evento) → listo |
| TEACHER | bienvenida → criterios de aviso (opcional) → listo |
| Otros roles | `SystemInactiveScreen` — pantalla de espera hasta que dirección complete la configuración |

`NexusGuide` (CMP-NEXO) conduce el flujo: una burbuja a la vez, avance por
clic, se retira al terminar y puede descartarse arrastrándolo.

## Telemetría

`api/telemetry.js` (`initTelemetry()` desde `App.jsx`): recolecta eventos no
sensibles — latencia API (vía evento `nexo:telemetry` del cliente Axios),
errores JS (`window.onerror`, `unhandledrejection`), latencia biométrica
(`trackBiometric`), renders lentos (`trackRenderSlow`) y un ping de vida
(plataforma, pantalla, zona horaria, memoria, tipo de conexión). Se envía a
`POST /telemetry` cada 5 min, a los 10 s del arranque y al ocultar la pestaña;
**nunca en rutas públicas** (`/login`, `/instalar`, `/descargas`) y siempre de
forma silenciosa (un fallo de telemetría no interrumpe la app). Cola máx. 100
eventos.

## Testing

Vitest 4 + Testing Library + jsdom (`vitest.config.js`, `src/__tests__/setup.js`,
alias `@` → `src/`): **563 pruebas en 50 archivos**.

| Directorio | Cobertura |
|---|---|
| `__tests__/api/` | Los 17 clientes: endpoints, payloads, manejo de error |
| `__tests__/components/` | Primitivas UI y `ChatDataCard` |
| `__tests__/config/` | `roles.js` (filtros por rol) |
| `__tests__/context/` | Auth, Notification, Theme |
| `__tests__/hooks/` | `useAuth` |
| `__tests__/pages/` | Login, Dashboard, Operation, Enrollment, RiskConfig |
| `__tests__/routes/` | `ProtectedRoute` |
| `__tests__/utils/` | `cn`, `exporters`, `formatters`, `groupFormat`, `jwt`, `messages`, `mobilePermissions` |

```bash
cd frontend/pwa && npm test
```

## Build, lint, Storybook y deploy

- **Build** (`vite.config.js`): `base '/app/'`, `target es2020`,
  `sourcemap: 'hidden'`, `cssCodeSplit`, chunks manuales `react`
  (react/react-dom/router) y `ui` (lucide-react). Salida `dist/`.
- **Lint**: `.eslintrc.cjs` — eslint:recommended + react + hooks +
  react-refresh; `prop-types` off, `no-unused-vars` warn.
- **Storybook**: el proyecto tiene un punto de partida mínimo —
  `.storybook/preview.jsx` (vacío) y una sola historia
  `src/components/patterns/SituationLine.stories.jsx`. **Storybook no está en
  `package.json`** ni hay script para correrlo: es trabajo inconcluso, no una
  herramienta disponible.
- **Deploy Vercel** (`vercel.json`): `npm run build` → `dist`;
  redirects de rutas legacy sin `/app/` a su equivalente (`/login` →
  `/app/login`, …); rewrites de `/app/assets/*` y `/app/logo/*` a los estáticos
  reales y fallback SPA a `/index.html`; headers de seguridad (HSTS, nosniff,
  `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy con solo
  `camera=(self)`); cache inmutable para `/assets/*`, `no-cache` para
  `/sw.js` y el manifest.
- `index.html` fija CSP (`script-src 'self'`, `connect-src 'self' https:`),
  meta PWA/SEO/OpenGraph, preconnect al backend en Render y skip link.

## Convenciones y estado del código

- **IDs de spec**: componentes y páginas citan `CMP-*` (componentes),
  `SCR-*` (pantallas), `DEC-*` (decisiones), `FLOW-*` (flujos) — búscalos en
  `../design-philosophy/` para saber por qué existen.
- **Archivos huérfanos** (presentes pero sin ruta ni importador):
  `pages/Consultation.jsx`, `pages/ConsultationDrawer.jsx` (la consulta por
  módulos fue absorbida por el chat; la ruta `/consulta` hoy redirige) y
  `pages/RiskConfig.jsx` (el wizard de riesgo vive dentro de
  `OnboardingFlow`). `pages/TrackingModal.jsx` sí se usa (Seguimiento y
  drawer de notificaciones).
- **Doble catálogo de comandos**: `config/roles.js#OPERATION_COMMANDS`
  (referencia de spec por rol) y `COMMANDS_CATALOG` dentro de
  `pages/Operation.jsx` (el que la UI renderiza, con `tone`, `desc`,
  `fields`). Al añadir una operación hay que tocar ambos y el backend.
- **Duplicación conocida de tokens**: el backend conoce los roles por su
  código (`COORDINATOR`, `TEACHER`…); el frontend los muestra en español vía
  `ROLE_DISPLAY`.
- UI, comentarios y mensajes en español (`es-CO`).
