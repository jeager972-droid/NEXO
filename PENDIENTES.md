# NEXO — Estado del Sistema y Pendientes

> **Última actualización:** 2026-08-06
> **Estado general:** Iteración 3 completada y desplegada en producción.

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

## Lo que ya está hecho (iteraciones 1-3)

| Módulo | Estado | Detalle |
|--------|--------|---------|
| Dashboard | Done | Métricas sin duplicación, cards compactas con perfil |
| Consultations | Done | Arreglada, tabla compacta con paginación |
| Operation | Done | 12 comandos incluyendo fusionar/extender bloque |
| Notifications | Done | Llegada tarde con Justificar/No Justificar |
| Workers | Done | Integración con daily_schedule_config |
| DB Migration | Done | Ejecutada en producción (metadata_json, permisos, funciones) |
| UI/UX | Done | Bordes ámbar, casos activos con fondo ámbar |
| Deduplicación biométrica | Done | 30s window configurable |

---

## Pendientes

### 1. Purge automático de notificaciones
**Problema:** Las notificaciones se acumulan indefinidamente en la DB.
**Solución:** Crear un worker o pg_cron job que elimine notificaciones de
más de 30 días automáticamente.
**Prioridad:** Media
**Archivos:** Nuevo worker `worker_notification_purge.php` o pg_cron job.

### 2. Incluir EVASION_INTERNA en risk_score
**Problema:** `fn_calculate_student_risk` cuenta tardanzas (x5) y
inasistencias (x15) pero NO evasiones. Un estudiante que se evade
frecuentemente no sube su score de riesgo.
**Solución:** Modificar la función para contar evasiones del último mes
y sumarlas al score (ej: x10 por evasión).
**Prioridad:** Alta
**Archivos:** `backend/api/sql/nexo_full_migration.sql`,
`backend/api/sql/migration_iteracion3.sql`.

### 3. Onboarding en todas las rutas
**Problema:** El onboarding solo se verifica en el Dashboard. Si un
RECTOR/COORDINADOR entra directamente a `/operacion` sin pasar por el
dashboard, no verá el modal.
**Solución:** Mover la verificación de onboarding al Layout (componente
padre de todas las rutas autenticadas).
**Prioridad:** Media
**Archivos:** `WebApp/src/components/layout/Layout.jsx` (o equivalente).

### 4. student_tracking con timezone Bogotá
**Problema:** `student_tracking` y `student_tracking_notes` usan
`CURRENT_TIMESTAMP` que depende del timezone del servidor. Si el servidor
no está en America/Bogota, las fechas de seguimiento quedan mal.
**Solución:** Cambiar `CURRENT_TIMESTAMP` por
`NOW() AT TIME ZONE 'America/Bogota'` en los defaults.
**Prioridad:** Baja (si el servidor ya está en hora Colombia)
**Archivos:** `backend/api/sql/nexo_full_migration.sql`.

### 5. Dashboard de evasión
**Problema:** No hay conteo de evasiones del día en las StatCards del
dashboard. Los rectores/coordinadores no ven cuántos estudiantes se
evadieron hoy de un vistazo.
**Solución:** Agregar una StatCard de evasiones con click → lista de
estudiantes evadidos (similar a inasistentes/tardanzas).
**Prioridad:** Media
**Archivos:** `backend/api/routes/dashboard.php`,
`WebApp/src/pages/Dashboard.jsx`.

### 6. Reportes históricos de evasión
**Problema:** No hay reportes históricos de evasión por
estudiante/grupo/fecha. Solo se ve en tiempo real.
**Solución:** Agregar módulo de evasión a Consultations con filtros por
rango de fechas, grupo, estudiante. Exportable a Excel/PDF.
**Prioridad:** Media
**Archivos:** `backend/api/routes/consultations.php`,
`WebApp/src/pages/ConsultationDrawer.jsx`.

### 7. Configuración de tipos de eventos en novedades
**Problema:** El event feed del dashboard hardcodea qué `command_type`
aparecen. No es configurable por institución.
**Solución:** Hacer configurable qué tipos de eventos aparecen en el
event feed, almacenado en `school_settings` o similar.
**Prioridad:** Baja
**Archivos:** `backend/api/routes/dashboard.php`,
`WebApp/src/pages/Dashboard.jsx`.

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
