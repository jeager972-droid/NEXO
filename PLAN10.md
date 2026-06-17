# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 10 de 10)

## ÍNDICE MAESTRO — Mapa de Bugs, Archivos y Referencias

Esta es la tabla de referencia cruzada entre los bugs identificados, sus archivos afectados, y el documento de plan donde se describe la corrección detallada.

---

### Tabla Maestra de Bugs

| # | Síntoma | Archivo(s) afectado(s) | Causa raíz | Plan | Prioridad |
|---|---|---|---|---|---|
| 1 | "Generar permiso" → error aunque funciona en backend | `routes/operations.php` líneas 511–568 | Sin `echo json_encode(['status'=>'ok'])` antes del break | PLAN1 | 🔴 Crítico |
| 2 | "Autorizar salida" → error aunque WhatsApp llega | `routes/operations.php` líneas 570–667 | Sin `echo json_encode(['status'=>'ok'])` antes del break | PLAN1 | 🔴 Crítico |
| 3 | "Salida pedagógica" → error, no envía WhatsApp ni guarda | `routes/operations.php` líneas 669–681 | Sin echo + campos erróneos (espera `student_id`/`destination`, recibe `group`/`reason`) | PLAN1 | 🔴 Crítico |
| 4 | Error de carga en /consulta para todos los roles | `pages/Consultation.jsx`, `api/consultations.js` | Probable import roto en `behavior.js` o nombre de archivo incorrecto (`consultation.js` vs `consultations.js`) | PLAN2 | 🔴 Crítico |
| 5 | PORTERO y AUXILIAR ven "Consulta" en el sidebar | `config/roles.js` línea 57 | Roles incorrectos en SIDEBAR_ITEMS | PLAN4 | 🔴 Crítico |
| 6 | Reagendamiento no llega como notificación al profesor | `routes/misc.php` líneas 447–488 | `$teacherRef` es null porque el worker no persiste `sender_user_id` en `twilio_messages` | PLAN3 | 🟠 Importante |
| 7 | Campana no muestra punto verde en notificaciones nuevas | `layout/Layout.jsx` líneas 120–136 | No hay polling; el conteo se carga solo al montar el componente | PLAN3 | 🟠 Importante |
| 8 | Dashboard docente: 0 estudiantes pero 1 alerta; al entrar, vacío | `routes/dashboard.php` líneas 86–96, 290–311 | Alertas incluyen SOS globales; el detail no une con `sos_alerts` | PLAN3 | 🟠 Importante |
| 9 | Módulos de consulta no implementados → vacíos sin mensaje | `routes/consultations.php` (cases faltantes) | No hay `case` para Spam Biométrico, Permisos Emitidos, Salidas del colegio, etc. | PLAN2 | 🟠 Importante |
| 10 | Timestamps en formato ISO 8601 en tablas de consulta | `pages/ConsultationDrawer.jsx` | `fmt12h` no maneja fechas solo-fecha ni usa timezone América/Bogotá | PLAN5 | 🟠 Importante |
| 11 | Enums sin traducir (ej: UNAUTHORIZED_ABSENCE en tabla) | `pages/ConsultationDrawer.jsx` | `ENUM_LABELS` incompleto | PLAN5 | 🟠 Importante |
| 12 | Columnas en snake_case (ej: `authorization_reason`) | `pages/ConsultationDrawer.jsx` | Columna no está en `COLUMN_LABELS` ni en `EXCLUDE_COLS` | PLAN5 | 🟠 Importante |
| 13 | HTTP 500 en "Historial permisos" (Auditoría Rector) | `routes/audit_full.php` | Query usa `metadata_json->>` en tabla que puede no tener esa columna | PLAN5 | 🟠 Importante |
| 14 | Logs duplicados en "Salidas clase" (Rector) | `routes/audit_full.php` | JOIN con `student_group_assignments` sin `AND sga.active = TRUE` | PLAN4 | 🟠 Importante |
| 15 | Dashboard psicorientador usa vista de docente | `pages/Dashboard.jsx` | Condición `DOCENTE || PSICORIENTADOR` para dashboard de grupos | PLAN4 | 🟡 Mejora |
| 16 | Psicorientador no recibe botón "Empezar seguimiento" | `pages/Notifications.jsx` | No hay lógica condicional para `action === 'iniciar_seguimiento'` | PLAN4 | 🟡 Mejora |
| 17 | Enrolamiento: sin feedback de éxito/error en paso 3 | `pages/Enrollment.jsx` | `handleSave` no muestra mensajes al usuario | PLAN4 | 🟡 Mejora |
| 18 | Enrolamiento: faltan pasos 4 (biometría + lector) | `pages/Enrollment.jsx` | Solo 3 pasos implementados | PLAN4 | 🟡 Mejora |
| 19 | "Reportar daño" sin campo de ubicación | `pages/Operation.jsx` comando `daño` | `fields: ['description']` sin `'location'` | PLAN1 | 🟡 Mejora |
| 20 | Docente ve todos los grupos en Operation.jsx al citar | `pages/Operation.jsx` | Llama `getGroups()` sin `teacherOnly=true` | PLAN6 | 🟡 Mejora |
| 21 | Sin guard de ruta en /consulta, /enrolamiento | `App.jsx` o routes | No hay `<ProtectedRoute>` por rol para esas rutas | PLAN7 | 🟡 Mejora |
| 22 | Módulos de secretaria (Matrículas, Auxiliares, Personal) vacíos | `routes/consultations.php` | Cases no implementados, caen al fallback vacío | PLAN8 | 🟡 Mejora |
| 23 | Notificaciones: sin separación visual entre leídas/no leídas | `pages/Notifications.jsx`, `routes/misc.php` | No hay columna `read` en tabla `notifications` | — | 🔵 Backlog |
| 24 | Sin timeout en llamadas axios | `api/client.js` | No hay `timeout` configurado en el cliente HTTP | PLAN7 | 🔵 Backlog |
| 25 | Sin `AbortController` en Consultation.jsx | `pages/Consultation.jsx` | Las queries en vuelo no se cancelan al desmontar | PLAN7 | 🔵 Backlog |

---

## ÁRBOL DE DEPENDENCIAS DE LOS FIXES

Algunos fixes dependen de que otros se apliquen primero:

```
Fix 1.4 (import Consultation.jsx)
  └── Debe hacerse ANTES que Fix 2.1 (formatters.js)
      └── Fix 2.1 debe hacerse ANTES que Fix 2.2 (error display)

Fix 1.1 (echo permiso backend)
  └── Independiente, puede hacerse en cualquier momento

Fix 1.2 (echo autorizar_salida)
  └── Independiente

Fix 1.3 (echo pedagogica + fix lógica)
  └── Independiente

Fix 1.3 (roles sidebar)
  └── Independiente

Fix 2.3 (polling notificaciones)
  └── Puede hacerse en cualquier momento, no tiene dependencias

Fix 2.4 (cases en consultations.php)
  └── Después de Fix 1.4 para que los módulos carguen sin crash

Fix 2.5 (reagendamiento misc.php)
  └── Independiente del frontend
  └── Verificar primero que el worker_twilio.php tenga sender_user_id

Fix 2.6 (duplicados audit_full.php)
  └── Independiente
```

---

## NOTAS SOBRE DATOS DE PRUEBA

El usuario indicó: "los que están en datos simulados déjalos, esos sirven para pruebas."

### Qué NO modificar:
- Estudiantes existentes en la BD de prueba
- Grupos académicos ya creados
- Schedules de docentes existentes
- Registros históricos en `biometric_events`
- Registros en `attendance_incidents`
- Usuarios de prueba (docente@, coordinador@, etc.)

### Qué SÍ modificar:
- Código PHP del backend (solo lógica, no afecta los datos)
- Código React del frontend (no afecta la BD)
- Estructura del query SQL (solo cómo SE LEE la BD, no la modifica)
- El script de normalización de teléfonos de guardians es seguro (solo actualiza el formato del número, no borra datos)

---

## GUÍA RÁPIDA DE INICIO (Orden de implementación sugerido)

Para iniciar inmediatamente sin necesidad de leer todos los PLANs:

### Día 1 (2-3 horas) — Eliminar los errores más visibles

```
1. operations.php: añadir echo en 'permiso' y 'autorizar_salida'
2. roles.js: remover PORTERO y AUXILIAR de Consulta
3. Consultation.jsx: verificar imports (behavior.js, consultations.js)
4. operations.php: fix lógica de 'pedagogica' (usar group en vez de student_id)
```

### Día 2 (3-4 horas) — Información visible y correcta

```
5. Crear src/utils/formatters.js
6. ConsultationDrawer.jsx: integrar formatters, expandir COLUMN_LABELS y EXCLUDE_COLS
7. ConsultationDrawer.jsx: añadir display de error en ruta no-teacher
8. consultationsApi: lanzar excepción cuando status==='error'
```

### Día 3 (4-5 horas) — Módulos faltantes y notificaciones

```
9. consultations.php: implementar cases de Spam Biométrico, Permisos Emitidos, Salidas
10. consultations.php: implementar cases de secretaria (Matrículas, Personal, etc.)
11. Layout.jsx: añadir polling de notificaciones (30s)
12. misc.php: fix reagendamiento usando teacher_user_id de Redis
```

### Día 4 (3-4 horas) — Dashboard y auditoría

```
13. dashboard.php: fix alertas de docente (solo del grupo, no SOS globales)
14. dashboard.php: añadir SOS en detail de coordinador
15. audit_full.php: añadir AND sga.active=TRUE en todos los JOINs
16. audit_full.php: fix query "Historial permisos" (sin metadata_json si no existe)
```

### Día 5 (2-3 horas) — Enrolamiento y roles

```
17. Enrollment.jsx: añadir paso 4 con verificación de lector + feedback de error
18. Dashboard.jsx: separar psicorientador del dashboard de docente
19. Notifications.jsx: añadir botón "Empezar seguimiento" para psicorientador
20. Operation.jsx: getGroups con teacherOnly para docentes, añadir location a daño
```

---

## REFERENCIAS DE ARCHIVOS POR SECCIÓN

### Backend PHP
- `backend/alojamiento/routes/operations.php` — Comandos institucionales (SOS, citacion, permiso, etc.)
- `backend/alojamiento/routes/consultations.php` — Motor de consultas dinámicas
- `backend/alojamiento/routes/misc.php` — Webhook Twilio, notificaciones, búsqueda
- `backend/alojamiento/routes/dashboard.php` — Estadísticas del panel
- `backend/alojamiento/routes/audit_full.php` — Auditoría completa para rector
- `backend/alojamiento/routes/students.php` — Gestión de estudiantes
- `backend/alojamiento/routes/groups.php` — Grupos académicos
- `backend/alojamiento/worker_twilio.php` — Worker de envío de mensajes

### Frontend React
- `WebApp/src/pages/Operation.jsx` — Comandos institucionales (frontend)
- `WebApp/src/pages/Consultation.jsx` — Módulos de consulta (frontend)
- `WebApp/src/pages/ConsultationDrawer.jsx` — Tabla de datos de consulta
- `WebApp/src/pages/Enrollment.jsx` — Enrolamiento biométrico
- `WebApp/src/pages/Notifications.jsx` — Centro de notificaciones
- `WebApp/src/pages/Dashboard.jsx` — Panel principal
- `WebApp/src/layout/Layout.jsx` — Layout, campana, polling
- `WebApp/src/config/roles.js` — Roles, sidebar, display names
- `WebApp/src/api/operations.js` — Cliente HTTP operaciones
- `WebApp/src/api/consultations.js` — Cliente HTTP consultas
- `WebApp/src/api/consultation.js` — Cliente HTTP búsqueda individual
- `WebApp/src/api/students.js` — Cliente HTTP estudiantes
- `WebApp/src/api/notifications.js` — Cliente HTTP notificaciones

### Nuevos archivos a crear
- `WebApp/src/utils/formatters.js` — Funciones utilitarias compartidas de formateo

---

## FIN DEL PLAN DE DIAGNÓSTICO Y CORRECCIÓN

**Total de bugs identificados:** 25 (10 críticos/importantes, 12 mejoras, 3 backlog)  
**Total de archivos afectados:** 16 archivos existentes + 1 nuevo  
**Tiempo estimado de implementación:** 15–20 horas de desarrollo  
**Resultado esperado:** Sistema NEXO completamente funcional para todos los roles documentados

Este plan fue generado el 17/06/2026 con base en la lectura completa de los archivos fuente del proyecto NEXO. Cualquier cambio al schema de BD o a la estructura de endpoints entre esta fecha y la implementación puede requerir ajustes menores a las queries documentadas.

---

*PLAN1.md → PLAN2.md → PLAN3.md → PLAN4.md → PLAN5.md → PLAN6.md → PLAN7.md → PLAN8.md → PLAN9.md → PLAN10.md*
