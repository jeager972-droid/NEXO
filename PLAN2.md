# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 2 de 10)

## PATRÓN 2 — "Error de carga" en módulos de Consulta (todos los roles excepto Rector)

### Síntoma reportado
- Docente: entra a cualquier submodulo → "ERROR DE CARGA / Hubo un problema al cargar este módulo"
- Coordinador: idem
- Secretaria: no puede abrir ningún módulo
- Psicorientador: idem
- Rector: SÍ puede acceder a sus módulos (Auditoría)
- El patrón se repite en TODOS los roles que usan `/consulta`

### Diagnóstico raíz

El error "ERROR DE CARGA / Hubo un problema al cargar este módulo" **no existe literalmente** en los archivos de consulta que se leyeron (`Consultation.jsx`, `ConsultationDrawer.jsx`). Esto indica que el error proviene de una de dos fuentes:

**Causa A — React ErrorBoundary (crash silencioso)**
Si algún prop que llega a `ConsultationDrawer` tiene un tipo inesperado (ej: `dynamicColumns` es `null` en vez de `{}`), React lanzaría una excepción al renderizar, y si hay un ErrorBoundary en `App.jsx` o en `routes/`, mostraría ese mensaje genérico.

**Causa B — El backend devuelve HTTP 403 o 500 para ciertos módulos**, y el `catch` en `Consultation.jsx` setea `queryError`, que en la ruta no-teacher de `ConsultationDrawer` (líneas 457–575) **no está siendo renderizado** — el drawer muestra "Sin datos disponibles" en vez del error. PERO: si el error ocurre antes de que el drawer abra (durante el auto-fetch), `queryError` se setea y el drawer renderiza en modo error… salvo que el mensaje específico "ERROR DE CARGA" venga de otro lugar.

**Causa C (más probable) — `consultationsApi.queryModule` lanza un error de red o 401/403** porque el rol del usuario no está siendo reconocido correctamente en el backend `consultations.php`.

Revisando `consultations.php` línea 27:
```php
$isTeacher = in_array($userRoleUpper, ['DOCENTE', 'PSICORIENTADOR']);
if ($isTeacher && $groupName) {
    // verifica acceso al grupo via schedules
    if (!$hasSchedule) {
        http_response_code(403);
        exit(json_encode(['status' => 'error', 'message' => 'No tienes acceso a este grupo.']));
    }
}
```

Para el auto-fetch inicial (sin `group_name`), esta validación no dispara. Sin embargo, hay otro problema: cuando DOCENTE auto-fetchea un módulo como 'Llegadas Tarde', `isTeacherModule = true` (porque está en `TEACHER_MODULES`), así que **no se auto-fetcha**. Esto es correcto.

Pero COORDINADOR auto-fetcha 'Spam Biométrico', 'Vulneraciones', 'Alertas', 'Seguimiento Estudiantil', 'Permisos Emitidos', 'Salidas del colegio permitidas', 'Salidas Pedagógicas'. Para estos módulos no está en `TEACHER_MODULES`, entonces `isTeacherModule = false`, y se hace auto-fetch.

El problema real: **la mayoría de estos módulos no tienen un `case` implementado en `consultations.php`**. Veamos:

- 'Spam Biométrico' → NO está en ningún `case` → cae a `default` → retorna `{status:'ok', data:[], columns:{info:'Información'}}` → esto NO debería crashear
- 'Permisos Emitidos' → NO está implementado → mismo comportamiento
- 'Salidas del colegio permitidas' → mismo
- 'Seguimiento Estudiantil' → SÍ está implementado (línea 198–210)
- 'Alertas' → SÍ está implementado (línea 184–196)
- 'Vulneraciones' → alias de 'Alertas', SÍ implementado

Entonces el "Error de carga" NO viene de datos vacíos. Viene de un **crash de React** al intentar renderizar `ConsultationDrawer` cuando `dynamicColumns` contiene la estructura `{info: 'Información'}` (un solo key) pero el drawer intenta hacer `Object.keys(dynamicColumns)` y luego mapear esa key a las filas de datos, que están vacías. Esto no crashea pero puede producir una tabla rara.

**La causa real más probable:** En `ConsultationDrawer.jsx` línea 410:
```jsx
const keys = Object.keys(dynamicColumns);
```
Si `dynamicData` tiene filas pero `dynamicColumns` es `{}` (objeto vacío) — que pasa cuando el backend retorna `columns: {}` — entonces `keys = []` y la tabla no muestra columnas. Pero esto tampoco crashea.

**Causa definitiva encontrada:** En `Consultation.jsx` línea 4:
```js
import { behaviorApi } from '../api/behavior';
```
Y `src/api/behavior.js` solo tiene 200 bytes. Si `behaviorApi` está mal definido o `behavior.js` importa algo que no existe, **todo el módulo `Consultation.jsx` fallaría al importar**, produciendo un crash que el ErrorBoundary de la app capturaría y mostraría como "Error de carga".

Verificar `behavior.js`:
```js
// src/api/behavior.js — solo 200 bytes
```
Si este archivo hace un import incorrecto o tiene un export mal formado, React no puede cargar `Consultation.jsx` en absoluto, y esto explicaría por qué **todos los roles** (excepto Rector, que usa `/auditoria` con `Audit.jsx`, no `Consultation.jsx`) fallan.

### Plan de corrección

**Paso 1:** Verificar `src/api/behavior.js` — asegurarse de que `behaviorApi.getRiskAnalysis` está correctamente exportado y no tiene imports rotos.

**Paso 2:** Verificar que `src/api/consultations.js` exporta correctamente `consultationsApi` sin imports circulares.

**Paso 3:** Añadir display de error en la ruta no-teacher de `ConsultationDrawer.jsx`. Actualmente, si `error` está seteado y `isTeacherModule = false`, no se muestra ningún feedback:

```jsx
// En ConsultationDrawer.jsx, dentro del bloque else (no-teacher), añadir:
{error && !loadingData && (
  <div className="flex flex-col items-center justify-center h-full py-20 gap-4">
    <AlertTriangle size={32} strokeWidth={1} className="text-red-300" />
    <div className="text-center space-y-1">
      <p style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.15em', color: '#EF4444', textTransform: 'uppercase' }}>
        Error de consulta
      </p>
      <p style={{ fontSize: '11px', color: '#94A3B8' }} className="max-w-xs">
        {error}
      </p>
    </div>
  </div>
)}
```

**Paso 4:** Implementar los módulos de backend que faltan (ver PLAN7.md para detalle completo de queries).

**Paso 5:** Para secretaria específicamente — revisar si el backend en `consultations.php` tiene un bug de autorización para el rol SECRETARIA. El endpoint `/consultations/query` llama a `requireAuth()` sin restricción de roles, entonces cualquier rol autenticado puede acceder. Si secretaria recibe 403, es otro middleware.

### Módulos sin implementar en consultations.php (retornan vacíos)

| Módulo (frontend) | Rol | Estado backend | Fix requerido |
|---|---|---|---|
| Spam Biométrico | COORDINADOR | ❌ No implementado | Añadir case con query |
| Permisos Emitidos | COORDINADOR | ❌ No implementado | Añadir case con query |
| Salidas del colegio permitidas | COORDINADOR | ❌ No implementado | Añadir case con query |
| Salidas Pedagógicas | COORDINADOR | ❌ No implementado | Añadir case con query |
| Matrículas | SECRETARIA | ❌ Vacío (fallback) | Añadir case con query |
| Cambios Registro | SECRETARIA | ❌ Vacío (fallback) | Añadir case con query |
| Auxiliares | SECRETARIA | ❌ Vacío (fallback) | Añadir case con query |
| Portería | SECRETARIA | ❌ Vacío (fallback) | Añadir case con query |
| Personal Institucional | SECRETARIA | ❌ Vacío (fallback) | Añadir case con query |
| Reportes | SECRETARIA | ❌ Vacío (fallback) | Añadir case con query |
| Auditoría Local | SECRETARIA | ❌ Vacío (fallback) | Añadir case con query |
| Métricas Globales | RECTOR | ❌ Vacío (fallback) | En Audit.jsx, no aquí |
| Historial Asistencia | RECTOR | ✅ Implementado | OK |
| Seguimiento Estudiantil | COORDINADOR | ✅ Implementado | OK |
| Alertas | COORDINADOR | ✅ Implementado | OK |
| Llegadas Tarde | DOCENTE | ✅ Implementado | OK |
| Inasistencias | DOCENTE | ✅ Implementado | OK |
| Citaciones | DOCENTE | ✅ Implementado | OK |
| Estudiantes | SECRETARIA | ✅ Implementado | OK |
| Grupos | SECRETARIA | ✅ Implementado | OK |
| Acudientes | SECRETARIA | ✅ Implementado | OK |
| Profesores | SECRETARIA | ✅ Implementado | OK |

### Queries de backend a implementar

```php
case 'Spam Biométrico':
    $stmt = $conn->prepare("
        SELECT s.first_name, s.last_name, be.event_timestamp, be.event_type, be.event_result,
               COUNT(*) as intentos
        FROM biometric_events be
        JOIN students s ON be.student_id = s.student_id
        WHERE be.school_id = ?
          AND be.event_result IN ('NO_MATCH', 'SPOOF_DETECTED', 'LIVENESS_FAIL', 'TIMEOUT')
          AND (be.event_timestamp AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
        GROUP BY s.first_name, s.last_name, be.event_timestamp, be.event_type, be.event_result
        ORDER BY be.event_timestamp DESC
        LIMIT 100
    ");
    $params = [$schoolId, $fromDate, $toDate];
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                'event_timestamp' => 'Fecha/Hora', 'event_type' => 'Tipo', 
                'event_result' => 'Resultado', 'intentos' => 'Intentos'];
    break;

case 'Permisos Emitidos':
    $stmt = $conn->prepare("
        SELECT s.first_name, s.last_name, ag.group_name,
               cea.authorization_reason as reason,
               cea.exit_time, cea.return_time,
               u.first_name as issuer_first, u.last_name as issuer_last
        FROM class_exit_authorizations cea
        JOIN students s ON cea.student_id = s.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        LEFT JOIN users u ON u.user_id = cea.authorized_by_user_id
        WHERE cea.school_id = ?
          AND (cea.exit_time AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
        ORDER BY cea.exit_time DESC
        LIMIT 100
    ");
    $stmt->execute([$schoolId, $fromDate, $toDate]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                'group_name' => 'Grupo', 'reason' => 'Motivo',
                'exit_time' => 'Salida', 'return_time' => 'Retorno',
                'issuer_first' => 'Autorizado por'];
    break;

case 'Salidas del colegio permitidas':
    $stmt = $conn->prepare("
        SELECT s.first_name, s.last_name, ag.group_name,
               sea.authorization_reason as reason,
               sea.exit_time, sea.status,
               u.first_name as issuer_first, u.last_name as issuer_last
        FROM school_exit_authorizations sea
        JOIN students s ON sea.student_id = s.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        LEFT JOIN users u ON u.user_id = sea.authorized_by_user_id
        WHERE sea.school_id = ?
          AND (sea.exit_time AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
        ORDER BY sea.exit_time DESC
        LIMIT 100
    ");
    $stmt->execute([$schoolId, $fromDate, $toDate]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                'group_name' => 'Grupo', 'reason' => 'Motivo',
                'exit_time' => 'Fecha Salida', 'status' => 'Estado'];
    break;

case 'Salidas Pedagógicas':
    $stmt = $conn->prepare("
        SELECT ag.group_name, pta.destination, pta.purpose,
               pta.departure_time, pta.return_time,
               u.first_name as issuer_first, u.last_name as issuer_last,
               COUNT(DISTINCT sga2.student_id) as total_estudiantes
        FROM pedagogical_trip_authorizations pta
        JOIN academic_groups ag ON ag.group_id = pta.group_id
        LEFT JOIN student_group_assignments sga2 ON sga2.group_id = ag.group_id AND sga2.active = TRUE
        LEFT JOIN users u ON u.user_id = pta.authorized_by_user_id
        WHERE pta.school_id = ?
          AND (pta.departure_time AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
        GROUP BY ag.group_name, pta.destination, pta.purpose,
                 pta.departure_time, pta.return_time, u.first_name, u.last_name
        ORDER BY pta.departure_time DESC
        LIMIT 50
    ");
    $stmt->execute([$schoolId, $fromDate, $toDate]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = ['group_name' => 'Grupo', 'destination' => 'Destino',
                'purpose' => 'Propósito', 'departure_time' => 'Salida',
                'return_time' => 'Retorno', 'total_estudiantes' => 'Estudiantes'];
    break;
```

NOTA: La tabla `pedagogical_trip_authorizations` actualmente tiene columna `student_id` (no `group_id`), así que el backend actual inserta por estudiante. Si la tabla no tiene `group_id`, la query de salidas pedagógicas debe ajustarse. Se debe revisar el schema en `create_tables.php` y adaptar el query.

→ **Continúa en PLAN3.md**
