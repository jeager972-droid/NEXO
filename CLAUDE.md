# NEXO — Contexto General del Proyecto (Briefing para IA)

> **NEXO** es una startup colombiana de EdTech + IoT que busca resolver la gestión institucional educativa (asistencia biométrica, comunicación familia-escuela, operaciones diarias, auditoría) en colegios de Colombia, con objetivo de escalar a todo el país. El sistema debe ser 100% seguro, robusto y escalable.

---

## 1. Arquitectura del Sistema

### Stack Tecnológico
| Capa | Tecnología | Ubicación |
|---|---|---|
| **Frontend WebApp** | React 18 + Vite + TailwindCSS + PWA (VitePWA) | `WebApp/` |
| **Backend API** | PHP 8.2 + PDO + PostgreSQL + Redis | `backend/alojamiento/` |
| **Infraestructura** | Docker + Nginx + PHP-FPM | Railway (backend), Vercel (frontend) |
| **Mensajería** | Twilio WhatsApp API (con queue en Redis) | `backend/alojamiento/worker_twilio.php` |
| **Workers** | PHP CLI workers (biométrico, Twilio, auditoría) | `backend/alojamiento/worker_*.php` |
| **Edge** | C++ CMake para ESP32 / Raspberry Pi | `backend/edge/` |
| **Base de datos** | PostgreSQL ( Railway ) + Redis (Railway) | `backend/alojamiento/sql/` |

### Arquitectura de Despliegue
- **Frontend**: Vercel (`vercel.json` en `WebApp/`), dominio custom / preview
- **Backend**: Railway (`railway.json` → `backend/alojamiento/Dockerfile`), expone `/health`, `/v1/*`
- **Variable crítica de entorno**: `VITE_API_BASE_URL` apunta al backend Railway
- **CORS**: Controlado dinámicamente por `api.php` (acepta `CORS_ALLOW_ORIGINS` o fallback al origin de la petición)

---

## 2. Estructura de Directorios Clave

```
NEXO/
├── WebApp/                          # Frontend React (PWA)
│   ├── src/
│   │   ├── api/                     # Axios clients: client.js, auth.js, students.js, operations.js, users.js, notifications.js
│   │   ├── components/              # Componentes reutilizables (ErrorBoundary, PwaInstallPrompt)
│   │   ├── context/                 # AuthContext.jsx, ThemeContext.jsx
│   │   ├── config/                  # roles.js (ROLES, SIDEBAR_ITEMS, ROLE_DISPLAY), sidebarConfig.js
│   │   ├── hooks/                   # useAuth.js
│   │   ├── layout/                  # Layout.jsx (sidebar + header + búsqueda global), Sidebar.jsx
│   │   ├── pages/                   # Dashboard, Operation, Notifications, Audit, Consultation, Login, etc.
│   │   ├── routes/                  # ProtectedRoute.jsx
│   │   ├── App.jsx                  # Router con basename="/app"
│   │   └── main.jsx                 # Entry point, registerSW
│   ├── vite.config.js               # Base "/app/", PWA config
│   ├── vercel.json                  # Rewrites "/app/*" → index.html, redirect "/" → "/app/"
│   └── index.html                   # CSP meta tag, manifest
│
├── backend/alojamiento/             # Backend PHP
│   ├── api.php                      # Entry point API. CORS, routing, auth, operaciones
│   ├── db.php                       # Conexión PDO a PostgreSQL
│   ├── boot_check.php               # Validaciones de entorno al boot
│   ├── nginx-default.conf           # Config nginx + CORS preflight
│   ├── Dockerfile                   # PHP-FPM + nginx
│   ├── docker-entrypoint.sh         # Inicialización del contenedor
│   ├── routes/                      # Endpoints modulares
│   │   ├── operations.php           # Comandos institucionales (SOS, citación, salida, etc.)
│   │   ├── students.php             # Listado de estudiantes + paginación
│   │   ├── groups.php               # Grupos académicos
│   │   ├── users.php                # Usuarios by-role, OTP Twilio
│   │   ├── auth.php                 # Login/logout JWT cookie HttpOnly
│   │   ├── misc.php                 # Notificaciones (merge SOS + attendance_incidents + internal_messages)
│   │   └── ...
│   ├── worker_twilio.php            # Worker de envío de WhatsApp via Twilio
│   ├── worker_biometric.php         # Worker de procesamiento biométrico
│   ├── worker_audit.php             # Worker de logs de auditoría asíncronos
│   └── sql/                         # Migraciones y seed data
│
├── Gestion de Proyecto/             # Documentación de arquitectura, requisitos
├── logo/                            # Brand assets
└── .github/workflows/               # CI/CD
```

---

## 3. Archivos y Funciones Críticas

### Frontend
| Archivo | Propósito |
|---|---|
| `WebApp/src/main.jsx` | Monta React con `BrowserRouter basename="/app"`, registra PWA SW, limpia SW stale |
| `WebApp/src/App.jsx` | Rutas, lazy loading, ErrorBoundary, deep-link Tauri, protección por rol |
| `WebApp/src/api/client.js` | Axios con baseURL, interceptores (401 → logout, telemetría), CSRF header |
| `WebApp/src/api/operations.js` | `execute()`, `sos()`, `citacion()`, `salida()`, `permiso()` |
| `WebApp/src/context/AuthContext.jsx` | Estado de auth via `authApi.getMe()` (cookie HttpOnly), NO confía en localStorage |
| `WebApp/src/layout/Layout.jsx` | Header, sidebar toggle, búsqueda global (excluye RECTOR/SUPER_RECTOR), catálogo de búsqueda |
| `WebApp/src/pages/Operation.jsx` | Panel de comandos institucionales. Fetch de grupos/estudiantes. Formularios dinámicos por comando. **Refactorizado con `Combobox` unificado** (autocomplete + select) reemplazando barras duplicadas de búsqueda y select. |
| `WebApp/src/pages/Consultation.jsx` | **Consultas dinámicas institucionales**. Conectado a `/consultations/query` vía `consultationsApi`. Renderiza tablas con columnas dinámicas desde PostgreSQL. Incluye Análisis de Riesgo (`/behavior/risk`). |
| `WebApp/src/api/consultations.js` | Cliente Axios para el motor de consultas dinámicas (`/consultations/query`). |
| `WebApp/src/api/behavior.js` | Cliente Axios para análisis de riesgo (`/behavior/risk`). |
| `WebApp/src/pages/Notifications.jsx` | Lista de notificaciones (SOS + incidentes + mensajes internos) |
| `WebApp/src/pages/Audit.jsx` | Auditoría con filtros, export, humanización de enums (`VALUE_LABELS`) |
| `WebApp/src/config/roles.js` | `ROLES`, `SIDEBAR_ITEMS`, `ROLE_DISPLAY` (SUPER_RECTOR → "Admin") |

### Backend
| Archivo | Propósito |
|---|---|
| `backend/alojamiento/api.php` | Entry point. CORS dinámico. Routing por `$cleanPath`. `securityLog()` async via Redis. |
| `backend/alojamiento/db.php` | PDO PostgreSQL, manejo de SSL, modo `PDO::FETCH_ASSOC` |
| `backend/alojamiento/routes/operations.php` | `$rolePermissions[]`, `logUserCommand()`, `enqueueTwilioJob()`. Casos: sos, citacion, salida, permiso, solicitud, daño, pedagogica, horario, incidente. **FIX:** `citacion` usa `LEFT JOIN users` para incluir acudientes sin cuenta de usuario. Validación de teléfono vacío antes de encolar. |
| `backend/alojamiento/routes/consultations.php` | **Motor unificado de consultas dinámicas**. Recibe `module` por POST y traduce a SQL nativo con JOINs cruzando `students`, `biometric_events`, `attendance_incidents`, `internal_messages`, etc. |
| `backend/alojamiento/routes/misc.php` | `/notifications`: mergea `sos_alerts` + `attendance_incidents` + `internal_messages`. Filtra por rol (rector no ve llegadas tarde). Humaniza `incident_type`. |
| `backend/alojamiento/routes/students.php` | Cursor-based pagination con `last_id` (UUID). JOIN con `student_group_assignments` y `academic_groups`. |
| `backend/alojamiento/routes/users.php` | `/users/by-role` con filtro `same_shift=true`. `sendTwilioWhatsAppOtp()`. **FIX:** Normalización rigurosa de `From` y `To`, fallback entre `TWILIO_WHATSAPP_FROM` y `TWILIO_FROM_NUMBER`. |
| `backend/alojamiento/worker_twilio.php` | Consume cola Redis `queue:twilio`, envía WhatsApp, maneja retries, deduplicación, rate limiting (Leaky Bucket). **FIX:** Normalización de números `From`/`To`, logs a `stderr`, try-catch en conexión Redis. |
| `backend/alojamiento/worker_biometric.php` | Consume cola Redis `queue:biometric`, inserta/actualiza estudiantes, guardianes, incidentes. |

---

## 4. Roles del Sistema (`ROLES`)

```js
SUPER_RECTOR  → display "Admin"
RECTOR
COORDINADOR
DOCENTE
SECRETARIA
PORTERO
AUXILIAR
PSICORIENTADOR
```

- **Búsqueda global**: Oculta para `SUPER_RECTOR` y `RECTOR` (`canSearch = false`)
- **Consulta**: Visible para todos excepto `SUPER_RECTOR` y `RECTOR`
- **Auditoría / Informes**: Solo `SUPER_RECTOR` y `RECTOR`
- **Enrolamiento**: Solo `SECRETARIA`

---

## 5. Comandos del Panel de Operación

| Comando | Roles permitidos | Campos | Notas |
|---|---|---|---|
| `citar` | Coordinador, Docente, Psicorientador | group, student, date, message | — |
| `autorizar` | Coordinador, Rector | group, student, reason | — |
| `sos` | Todos | location, message | Notifica WhatsApp solo a `RECTOR` y `COORDINADOR`. Sin estilo rojo especial. |
| `daño` | Auxiliar, Portero | description | — |
| `solicitud` | Todos | targetRole, targetUser, message | Filtra usuarios por misma jornada (`same_shift=true`). Inserta en `internal_messages`. |
| `pedagogica` | Coordinador, Rector | group, date, time, location, message | — |
| `horario` | Coordinador, Rector | group, date, time, message | — |
| `incidente` | Docente, Psicorientador | group, student, message, targets | Puede notificar a padre y/o coordinador. |

> **Eliminado**: `inasistencia` (ya no existe ni en frontend ni backend).

---

## 6. Lógica de Notificaciones (`misc.php`)

- **SOS alerts**: Todos los usuarios autenticados las ven.
- **Attendance incidents**: 
  - Rectores/Admin (`RECTOR`, `SUPER_RECTOR`) **NO** ven `LATE_ARRIVAL`, `EARLY_EXIT`, `UNAUTHORIZED_ABSENCE`.
  - Docentes ven llegadas tarde pero el mensaje se suaviza a `"Se guardó en el sistema el evento"`.
  - Solo se muestran incidentes donde `metadata_json->>'target_user_id'` es NULL o coincide con el usuario logueado.
- **Internal messages** (solicitudes): Se muestran al `receiver_user_id`.

---

## 7. Base de Datos — Tablas Clave

| Tabla | Propósito |
|---|---|
| `users` | Usuarios del sistema. `role_id`, `school_id`, `phone`, `shift` |
| `students` | Estudiantes. `student_id` (UUID), `first_name`, `last_name` |
| `guardians` | Acudientes. `whatsapp_phone`, `whatsapp_phone_normalized` |
| `guardian_student_relationships` | Relación N:N estudiante-acudiente |
| `academic_groups` | Grupos académicos por colegio |
| `student_group_assignments` | Relación estudiante-grupo |
| `sos_alerts` | Alertas SOS emitidas desde la app |
| `attendance_incidents` | Incidentes biométricos y operacionales. `incident_type`, `metadata_json` |
| `internal_messages` | Mensajes entre usuarios (solicitudes internas) |
| `command_logs` | Log de comandos ejecutados por usuarios |
| `twilio_messages` | Log de mensajes WhatsApp enviados |
| `audit_logs` | Logs de auditoría asíncronos (alimentado por `worker_audit.php`) |

---

## 8. Resumen de Cambios Hechos (Checkpoint)

### Operaciones
- ✅ Eliminado comando `inasistencia` de frontend (`Operation.jsx`, `operations.js`) y backend (`operations.php`)
- ✅ SOS: notificaciones WhatsApp solo a `RECTOR` y `COORDINADOR` (no a todos). `SUPER_RECTOR` y `RECTOR` notifican a `COORDINADOR`, y viceversa.
- ✅ SOS: sin estilo `isUrgent` (mismos colores que otros comandos)
- ✅ `solicitud`: filtra usuarios por `same_shift=true`, inserta en `internal_messages` además de WhatsApp
- ✅ `solicitud`: selección de `targetRole` → `targetUser` en frontend

### Perfil y Usuarios
- ✅ Arreglada subida de fotos de perfil (Profile.jsx): Se eliminó el `Content-Type` hardcodeado en `usersApi.uploadPhoto` y el interceptor de Axios para permitir que `FormData` genere el boundary de `multipart/form-data` correctamente.

### Consultas (Motor Dinámico Unificado)
- ✅ **Backend:** Creado `backend/alojamiento/routes/consultations.php`. Motor unificado que recibe `module` por POST y traduce dinámicamente a queries SQL reales (JOINs entre `students`, `biometric_events`, `attendance_incidents`, `internal_messages`, `twilio_messages`, `users`, `academic_groups`). Soporta 20+ módulos.
- ✅ **Frontend:** `Consultation.jsx` conectado a `/consultations/query` vía `consultationsApi.js`. Renderiza tablas dinámicas con columnas definidas por el backend. Ya no hay placeholders estáticos.
- ✅ **Análisis de Riesgo:** Conectado a `/behavior/risk` vía `behaviorApi.js`. Renderiza tabla real con score, nivel y badge visual.

### Notificaciones
- ✅ Rectores/Admin no reciben notificaciones de llegadas tarde ni salidas anticipadas
- ✅ Docentes reciben mensaje suave `"Se guardó en el sistema el evento"` para llegadas tarde
- ✅ Humanización de eventos: `EARLY:DEPARTURE` → "Salida anticipada", `LATE:ARRIVAL` → "Llegada tarde", etc.
- ✅ Agregadas `internal_messages` al feed de notificaciones

### UI / Sidebar
- ✅ `SUPER_RECTOR` renombrado a "Admin" en `ROLE_DISPLAY`
- ✅ Búsqueda global oculta para `SUPER_RECTOR` y `RECTOR`
- ✅ Sección "Consulta" eliminada del sidebar para Admin y Rector

### Seguridad / Deploy
- ✅ CORS dinámico en `api.php` (fallback al origin de la petición si `CORS_ALLOW_ORIGINS` vacío)
- ✅ nginx CORS preflight usa `$http_origin` en vez de URL hardcodeada
- ✅ CSP de API relajada; el frontend maneja su CSP via meta tag
- ✅ Service Worker stale cleanup en `main.jsx`
- ✅ `vercel.json`: redirect `/` → `/app/`
- ✅ `vite.config.js`: `navigateFallback: '/app/index.html'`

### Twilio / Mensajería WhatsApp (Fixes Críticos)
- ✅ **Normalización de teléfonos:** Todos los endpoints (`users.php`, `operations.php`, `worker_twilio.php`) ahora normalizan rigurosamente tanto el número destinatario (`$to`) como el remitente (`$From`) mediante `normalizePhone()` / `normalizeWhatsAppPhone()`. Se remueve prefijo `whatsapp:` duplicado, se inyecta `+` forzosamente, y se sanitiza todo carácter no numérico.
- ✅ **Fallback de variables de entorno:** Si `TWILIO_WHATSAPP_FROM` no está definida, se usa `TWILIO_FROM_NUMBER`. Esto evita el envío con `From` vacío que rechaza Twilio.
- ✅ **Citación (`citacion`):** Cambiado `INNER JOIN users` a `LEFT JOIN users` en la query del acudiente principal. Ahora se notifica a acudientes que NO tienen una cuenta de usuario activa en la plataforma (muchos casos reales).
- ✅ **Validación anti-vacío:** `enqueueTwilioJob()` ahora valida que el teléfono destino no esté vacío ni sea solo `+`. Si es inválido, loguea `TWILIO_ENQUEUE_SKIPPED` y retorna sin encolar.
- ✅ **Workers indestructibles:** `docker-entrypoint.sh` ahora lanza los workers dentro de un lazo infinito: `(while true; do php worker_twilio.php; sleep 2; done)`. Si el worker falla (ej. Redis no disponible 1ms al inicio), se reinicia automáticamente.
- ✅ **Logs a stdout:** Redirigidos los logs de `worker_twilio.php` y `worker_audit.php` a `/dev/stdout` en lugar de `/dev/null`. Railway ahora captura errores del worker en tiempo real.
- ✅ **Try-catch en conexión Redis:** Tanto `worker_twilio.php` como `worker_audit.php` ahora envuelven `connectRedis()` en `try-catch`. Si falla al inicio, loguean el error y hacen `exit(1)` (el lazo del entrypoint los reinicia).
- ✅ **Rate limiter seguro:** Se agregó `max(1, ...)` al leer `TWILIO_RATE_LIMIT` para evitar división por cero.

### Operaciones / UX
- ✅ **Combobox unificado en `Operation.jsx`:** Se reemplazaron las 4 barras separadas (buscar grupo, select grupo, buscar estudiante, select estudiante) por un componente nativo `Combobox` que combina búsqueda en tiempo real + selección en un solo input. Filtra localmente, soporta `required`, y mantiene la validación anti-spam del formulario.

### Backend
- ✅ `consultations.php`: Endpoint unificado `/consultations/query` servido desde `api.php`.
- ✅ `operations.php`: `internal_messages` insert para `solicitud`
- ✅ `misc.php`: filtrado de incidentes por rol, humanización de tipos, query de `internal_messages`

---

## 9. Modus Operandi (Cómo Trabajamos)

1. **Siempre confirmar el problema antes de codear**. Leer logs, reproducir el error, identificar archivo exacto.
2. **Mínimo cambio posible**. Preferir un edit de 1 línea sobre una refactorización masiva.
3. **Frontend y backend se tratan por igual**. Si un bug es de datos, arreglar la API. Si es de UI, arreglar el componente.
4. **Nunca confiar en simulaciones/mocks**. Todo debe funcionar contra la BD real y Twilio real.
5. **Build antes de push**. Siempre ejecutar `npm run build` en `WebApp/` para asegurar que no hay errores de compilación.
6. **Commit descriptivo en español**. Formato: `fix(area): descripción` o `feat(area): descripción`.
7. **Documentar cambios en este archivo** (`CLAUDE.md`) si afectan arquitectura o comportamiento crítico.

---

## 10. Prohibiciones Estrictas

| # | Prohibición | Razón |
|---|---|---|
| 1 | **NO tocar archivos fuera del repo NEXO** | Este es un proyecto aislado; nada del sistema operativo, home, ni otros repos |
| 2 | **NO hardcodear URLs de staging/producción** | Todo pasa por variables de entorno (`import.meta.env.VITE_API_BASE_URL`, `getenv()`) |
| 3 | **NO dejar credenciales, tokens ni API keys en el código** | Todo va en variables de entorno de Railway/Vercel |
| 4 | **NO crear archivos de documentación innecesarios** | Solo actualizar este `CLAUDE.md`. No READMEs duplicados, no `progress.txt` |
| 5 | **NO eliminar ni debilitar tests existentes** | Si hay tests, mantenerlos. Si no hay, no crear nuevos a menos que se pida explícitamente |
| 6 | **NO asumir que el usuario quiere una refactorización** | Arreglar el bug exacto reportado; no reescribir módulos enteros |
| 7 | **NO usar mocks/simulaciones en producción** | Todo endpoint, notificación, y operación debe conectar con la BD real y Twilio real |
| 8 | **NO modificar dos archivos en paralelo sin confirmar** | Un archivo a la vez, confirmar, luego el siguiente |
| 9 | **NO tocar el hardware IoT/edge sin permiso explícito** | `backend/edge/` y `Gestion de Proyecto/` son documentación/hardware; no tocar |
| 10 | **NO omitir CORS ni seguridad para "hacerlo rápido"** | La seguridad es no negociable; cualquier workaround de CORS/auth debe ser temporal y documentado |

---

## 11. Comandos Útiles Rápidos

```bash
# Build frontend
cd WebApp && npm run build

# Commit + push
git add -A
git commit -m "fix(area): descripción"
git push origin main

# PSQL — actualizar teléfono acudientes
UPDATE guardians SET whatsapp_phone = '+573243607948';

# Ver logs de errores del backend (en Railway)
# Railway Dashboard → Logs → filtrar por "ERROR" o el event_type

# Limpiar Service Worker en navegador del usuario
# DevTools → Application → Service Workers → Unregister → Ctrl+Shift+R
```

---

## 12. Contacto / Contexto de Negocio

- **Sector**: Educación primaria/secundaria en Colombia
- **Modelo**: B2B (colegios), SaaS institucional + hardware IoT (nodos biométricos)
- **Diferenciador**: Soberanía tecnológica, audit chain inmutable, corresponsabilidad familiar en tiempo real, certificación IP66
- **Escalabilidad**: Diseñado para soportar múltiples colegios (`school_id` en todas las tablas), multi-tenant por diseño
- **Prioridad**: Funcionalidad sobre diseño experimental. La app debe funcionar en colegios reales antes que cualquier efecto visual.

---

*Última actualización: 2026-06-04*
*Mantener este archivo actualizado tras cada cambio arquitectónico significativo.*
