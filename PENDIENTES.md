# NEXO — Estado del Sistema y Pendientes

> **Última actualización:** 2026-08-08
> **Estado general:** Iteración 3 completada. Commit `c7f5de0` resolvió 4 pendientes.

---

## Deduplicación biométrica — qué son los 30 segundos

El lector biométrico a veces registra múltiples huellas en muy corto tiempo
por razones físicas: el estudiante apoya el dedo dos veces sin querer, el
sensor rebota, o hay ruido en la lectura. Sin deduplicación, esto generaría
eventos falsos como INGRESO → SALIDA → INGRESO en cuestión de segundos, y el
worker de evasión interpretaría la "SALIDA" como una evasión real.

**Cómo funciona:**
- Cuando llega un evento biométrico, el worker consulta si existe un evento
  previo del **mismo estudiante** dentro de una ventana de **30 segundos**.
- Si el evento previo es del **mismo tipo** (ej: INGRESO + INGRESO) → se
  descarta como duplicado.
- Si el evento previo es de **tipo diferente** (ej: último fue INGRESO, nuevo
  es SALIDA) → es un cambio legítimo de estado, **no se deduplica**.
- Si no hay tipo definido → se deduplica (conservador).

**Por qué 30 segundos:**
- Es el tiempo razonable para que un estudiante no salga y vuelva a entrar
  legítimamente. Una salida real implica caminar hasta la puerta, salir, y
  volver a entrar — mínimo 1-2 minutos.
- 30s es suficiente para filtrar rebotes del sensor y dobles toques
  accidentales sin perder eventos reales.
- Es configurable vía la variable de entorno
  `BIOMETRIC_DEDUP_WINDOW_SECONDS` (default 30).

**Ubicación:** `backend/api/workers/worker_biometric.php` líneas 172-207.

---

## Lo que ya está hecho (iteraciones 1-3 + commit c7f5de0)

| Módulo | Estado | Detalle |
|--------|--------|---------|
| Dashboard | Done | Métricas sin duplicación, cards compactas con perfil, StatCard de evasiones |
| Consultations | Done | Arreglada, tabla compacta con paginación, evasión con más columnas |
| Operation | Done | 12 comandos incluyendo fusionar/extender bloque |
| Notifications | Done | Llegada tarde con Justificar/No Justificar |
| Workers | Done | Integración con daily_schedule_config |
| DB Migration | Done | Ejecutada en producción (metadata_json, permisos, funciones) |
| UI/UX | Done | Bordes ámbar, casos activos con fondo ámbar |
| Deduplicación biométrica | Done | 30s window configurable |
| Purge de notificaciones | Done (c7f5de0) | `worker_notification_purge.php` — cron o daemon, >30 días |
| Onboarding en todas las rutas | Done (c7f5de0) | Movido de Dashboard.jsx al Layout.jsx |
| student_tracking timezone Bogotá | Done (c7f5de0) | Migration `2026-20-fix-student-tracking-timezone.sql` |
| Dashboard de evasión | Done (c7f5de0) | StatCard + evasion_cte + categoría 'evasion' en TeacherDetailDrawer |
| Reportes de evasión (Consultations) | Parcial (c7f5de0) | Query mejorada con más columnas, LIMIT 500, traducciones |

---

## Pendientes

### 1. Incluir EVASION_INTERNA en risk_score
**Problema:** `fn_calculate_student_risk` cuenta tardanzas (x5) y
inasistencias (x15) pero NO evasiones. Un estudiante que se evade
frecuentemente no sube su score de riesgo.
**Solución:** Modificar la función para contar evasiones del último mes
y sumarlas al score (ej: x10 por evasión).
**Prioridad:** Alta
**Archivos:** `backend/api/sql/nexo_full_migration.sql`,
`backend/api/sql/migration_iteracion3.sql`.

### 2. Configuración de tipos de eventos en novedades
**Problema:** El event feed del dashboard hardcodea qué `command_type`
aparecen. No es configurable por institución.
**Solución:** Hacer configurable qué tipos de eventos aparecen en el
event feed, almacenado en `school_settings` o similar.
**Prioridad:** Baja
**Archivos:** `backend/api/routes/dashboard.php`,
`WebApp/src/pages/Dashboard.jsx`.

### 3. Ejecutar migration student_tracking timezone en producción
**Problema:** La migration `2026-20-fix-student-tracking-timezone.sql`
fue creada pero necesita ejecutarse en la DB de producción.
**Solución:** Ejecutar con psql:
```bash
psql -U <usuario> -d <database> -f backend/api/sql/2026-20-fix-student-tracking-timezone.sql
```
**Prioridad:** Baja (si el servidor ya está en hora Colombia)
**Archivos:** `backend/api/sql/2026-20-fix-student-tracking-timezone.sql`.

### 4. Configurar cron del worker_notification_purge
**Problema:** El worker `worker_notification_purge.php` fue creado pero
necesita configurarse en el cron/supervisor del servidor de producción.
**Solución:** Agregar al crontab:
```
0 3 * * * php /path/to/backend/api/workers/worker_notification_purge.php
```
O como daemon en supervisor:
```
[program:nexo-worker-notification-purge]
command=php /path/to/worker_notification_purge.php --daemon
```
**Prioridad:** Media
**Archivos:** `backend/api/workers/worker_notification_purge.php`.

---

## Documentación activa

| Archivo | Propósito |
|---------|-----------|
| `README.md` | Visión general del proyecto |
| `funcionamiento.md` | Documentación técnica completa del sistema |
| `PENDIENTES.md` | Este archivo — estado y pendientes |
| `UAREU5300_RUNBOOK_PRODUCCION.md` | Runbook operativo para producción |

## Documentación eliminada (obsoleta)

| Archivo | Razón |
|---------|-------|
| `NEXO_BIOMETRIC_INTEGRATION_ANALYSIS.md` | Análisis previo a la integración, ya implementada |
| `NEXO_BIOMETRIC_PREINTEGRATION_DESIGN.md` | Diseño previo a la integración, ya implementada |
| `NEXO_SISTEMA_INTEGRADO.md` | Reemplazado por `funcionamiento.md` |
| `PLAN5300_RESULTADOS.md` | Resultados de revisión del plan, ya ejecutado |
| `SISTEMA_NEXO_AUDITORIA_COMPLETA.md` | Auditoría puntual ya resuelta, info en `funcionamiento.md` |
| `UAREU5300_ANALISIS_Y_PLAN.md` | Análisis forense del SDK, ya resuelto |
