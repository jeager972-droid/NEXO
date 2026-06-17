# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 5 de 10)

## PATRÓN 9 — Módulos sin humanización del frontend (SQL crudo, timestamps, enums)

### Síntoma reportado
- "Permisos emitidos sale en detalles como código SQL"
- "La hora sale tipo timestamp"
- "Spam biométrico sale con hora en timestamp y no está en formato 12 horas"
- "Otros módulos salen también con faltas del frontend"

### Diagnóstico raíz

**Archivos afectados:** `WebApp/src/pages/ConsultationDrawer.jsx` y `WebApp/src/pages/Audit.jsx`

**Sub-problema A — "Como código SQL"**

Esto ocurre cuando el backend retorna columnas con nombres tipo `authorization_id`, `authorized_by_user_id`, `metadata_json`, etc. que el frontend no sabe excluir ni humanizar. En `ConsultationDrawer.jsx`, existe el array `EXCLUDE_COLS` (líneas 85–90) que filtra columnas internas:

```js
const EXCLUDE_COLS = [
  'school_id','student_id','guardian_id','incident_id','alert_id','metadata_json',
  'sync_hash','event_signature','biometric_hash','device_id','event_id','log_id',
  'audit_id','assignment_id','schedule_id','classroom_id','report_export_id',
  'command_id','twilio_message_id','relationship_id','staff_record_id',
  'previous_data','new_data',
];
```

Y la función `humanizeColumn` (líneas 81–83) traduce snake_case a label legible. Sin embargo, si el backend retorna nombres de columna que no están en `COLUMN_LABELS` ni en `EXCLUDE_COLS`, aparecen con formato raw.

**Columnas problemáticas que deben añadirse a `EXCLUDE_COLS`:**
```js
const EXCLUDE_COLS = [
  // ... existentes ...
  'authorization_id', 'authorized_by_user_id', 'exit_authorization_id',
  'trip_authorization_id', 'tracking_id', 'incident_type', // mostrar como "Tipo" via COLUMN_LABELS
  'twilio_message_id', 'provider_message_sid', 'metadata',
  'guardian_user_phone', 'guardian_id', 'sender_user_id',
  'deactivated_at', 'created_at_raw', 'updated_at',
];
```

**Columnas problemáticas que deben añadirse a `COLUMN_LABELS`:**
```js
const COLUMN_LABELS = {
  // ... existentes ...
  exit_time:          'Hora de salida',
  return_time:        'Hora de retorno',
  departure_time:     'Hora de partida',
  authorization_reason: 'Motivo',
  issuer_first:       'Autorizado por',
  issuer_last:        'Apellido',
  delivery_status:    'Estado de entrega',
  phone_number:       'Teléfono',
  message_content:    'Mensaje',
  sent_at:            'Enviado',
  direction:          'Dirección',
  type_code:          'Tipo',
  intentos:           'Intentos',
  total_estudiantes:  'Estudiantes',
  risk_score:         'Puntuación',
  risk_level:         'Nivel de riesgo',
  absent_since:       'Ausente desde',
  permiso_type:       'Tipo de permiso',
  permiso_at:         'Fecha del permiso',
  alert_type:         'Tipo de alerta',
  alert_at:           'Fecha de alerta',
  group_name:         'Grupo',
  document_number:    'Documento',
  incident_type:      'Tipo de incidente',
  detected_at:        'Detectado',
  destination:        'Destino',
  purpose:            'Propósito',
};
```

**Sub-problema B — Timestamps sin formato 12h**

La función `formatCellValue` en `ConsultationDrawer.jsx` (líneas 37–68) ya detecta columnas con `_at`, `_time`, `timestamp` en su nombre y las formatea con `fmt12h()`. Sin embargo, si el backend retorna el campo con un nombre diferente (como `exit_time`, `departure_time`, `return_time`), el detector `sk.includes('time')` ya los captura. Pero hay un edge case: si el valor es una fecha sin hora (solo `YYYY-MM-DD`), `new Date('2024-01-15')` en JavaScript puede interpretarse como UTC medianoche, lo que en Colombia (UTC-5) daría un día anterior al esperado.

**Fix en `fmt12h()`:**
```js
function fmt12h(iso) {
  if (!iso) return '—';
  // Si es solo fecha (sin hora), mostrar solo la fecha sin conversión TZ
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(iso))) {
    return fmtShortDateOnly(iso);
  }
  // Para datetime completo, usar la zona de Bogotá
  const d = new Date(iso);
  if (isNaN(d)) return String(iso);
  // Formatear en hora de Bogotá
  return d.toLocaleString('es-CO', {
    timeZone: 'America/Bogota',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    hour12: true
  });
}
```

Esta implementación usa la API nativa de internacionalización del navegador con la zona horaria de Bogotá, evitando el problema de desfase UTC sin necesidad de librerías externas.

**Sub-problema C — Spam biométrico con timestamp**

En `Audit.jsx` (para el rol Rector), el módulo "Spam biométrico" obtiene datos de `audit_full.php`. Si el backend retorna `event_timestamp` sin formatear, el frontend de Audit.jsx debe también pasar por la misma función `formatCellValue`. Verificar que `Audit.jsx` tenga una función equivalente a `fmt12h` para todos sus campos de tiempo. Si Audit.jsx renderiza las celdas directamente sin pasar por esta función, se deben unificar las funciones en un archivo utilitario compartido.

**Propuesta: crear `src/utils/formatters.js`** con las funciones compartidas:

```js
// src/utils/formatters.js
const MONTHS_ES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

export function fmt12h(iso) {
  if (!iso) return '—';
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(iso))) {
    const [y, m, d] = String(iso).split('-');
    return `${parseInt(d)} ${MONTHS_ES[parseInt(m)-1]} ${y}`;
  }
  const d = new Date(iso);
  if (isNaN(d)) return String(iso);
  return d.toLocaleString('es-CO', {
    timeZone: 'America/Bogota',
    day: 'numeric', month: 'short', year: 'numeric',
    hour: 'numeric', minute: '2-digit', hour12: true
  });
}

export const ENUM_LABELS = {
  CHECK_IN: 'Entrada', CHECK_OUT: 'Salida', LATE_ARRIVAL: 'Llegada tarde',
  INGRESO_NORMAL: 'Ingreso normal', INGRESO_TARDE: 'Ingreso tarde',
  WRONG_CLASSROOM: 'Salón incorrecto', EVASION_INTERNA: 'Evasión interna',
  MATCH: 'Coincidencia', NO_MATCH: 'Sin coincidencia',
  PARTIAL_MATCH: 'Coincidencia parcial', SPOOF_DETECTED: 'Intento de fraude',
  LIVENESS_FAIL: 'Prueba de vida fallida', TIMEOUT: 'Tiempo agotado',
  SUCCESS: 'Exitoso', FAILED: 'Fallido', PENDING: 'Pendiente',
  APPROVED: 'Aprobado', REJECTED: 'Rechazado', SENT: 'Enviado',
  DELIVERED: 'Entregado', UNDELIVERED: 'No entregado', READ: 'Leído',
  SOS_WEBAPP: 'Alerta SOS', SOS_DEVICE: 'Alerta SOS (dispositivo)',
  INASISTENCIA: 'Inasistencia', UNAUTHORIZED_ABSENCE: 'Inasistencia',
  CITACION: 'Citación a acudiente', CITACION_CONFIRMADA: 'Citación confirmada',
  CITACION_REAGENDADA: 'Citación reagendada',
  AUTORIZAR_SALIDA: 'Salida autorizada', PERMISO: 'Permiso de salida',
  PEDAGOGICA: 'Salida pedagógica', INCIDENTE: 'Incidente',
  HORARIO: 'Cambio de horario', DAÑO: 'Daño físico',
  SOLICITUD: 'Solicitud interna', NOTIFY_ROLE: 'Notificación interna',
  INBOUND: 'Entrante', OUTBOUND: 'Saliente',
  RECTOR: 'Rector', COORDINADOR: 'Coordinador', DOCENTE: 'Docente',
  SECRETARIA: 'Secretaria', PORTERO: 'Portero', AUXILIAR: 'Auxiliar',
  PSICORIENTADOR: 'Psicorientador', SUPER_RECTOR: 'Super Rector',
  CRITICAL: 'Crítico', HIGH: 'Alto', MEDIUM: 'Medio', LOW: 'Bajo',
};

export function formatCellValue(key, value) {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'Sí' : 'No';
  const sk = String(key).toLowerCase();
  if (sk.includes('timestamp') || sk.includes('_at') || sk.includes('time') ||
      sk.includes('detected') || sk.includes('emitted') || sk.includes('created') ||
      sk.includes('departure') || sk.includes('return')) {
    const d = new Date(value);
    if (!isNaN(d)) return fmt12h(value);
  }
  if (sk.includes('date')) {
    const d = new Date(value);
    if (!isNaN(d)) return fmt12h(value);
  }
  const sv = String(value).trim();
  if (sv === 'en proceso') return <span className="...">En Proceso</span>;
  if (sv === 'resuelto') return <span className="...">Resuelto</span>;
  if (ENUM_LABELS[sv]) return ENUM_LABELS[sv];
  if (ENUM_LABELS[sv.toUpperCase()]) return ENUM_LABELS[sv.toUpperCase()];
  if (sv.length > 120) return sv.slice(0, 120) + '…';
  return sv;
}
```

Luego importar en `ConsultationDrawer.jsx` y en `Audit.jsx`:
```js
import { fmt12h, formatCellValue, ENUM_LABELS } from '../utils/formatters';
```

---

## PATRÓN 10 — HTTP 500 en "Historial permisos" y otros módulos de Auditoría

### Síntoma reportado
"Ciertas secciones de los módulos dicen, request failed with status code 500, por ejemplo, historial permisos."

### Diagnóstico raíz

**Archivo afectado:** `backend/alojamiento/routes/audit_full.php` (66KB, no leído completamente)

El archivo es el más grande del backend (66KB, estimado ~1600 líneas). Los errores 500 en módulos de auditoría indican que alguna query SQL está fallando. Las causas más comunes en PHP+PDO con PostgreSQL:

**Causa A — Columna no existe en la tabla**
Si el código usa `metadata_json->>>'key'` (JSON extraction) en una tabla donde la columna `metadata_json` no existe o no es de tipo `jsonb`, PostgreSQL lanza un error. En versiones anteriores del schema, `metadata_json` puede haberse añadido en un migration posterior.

**Causa B — JOIN a tabla que no existe o tiene nombre diferente**
Por ejemplo, si la query une `class_exit_authorizations` pero la tabla real se llama `class_exit_permits`, o si hace referencia a una columna `authorization_id` que en realidad se llama `id`.

**Causa C — Subquery con LIMIT en PostgreSQL mal posicionado**
PostgreSQL no permite `LIMIT` dentro de ciertas subqueries en versiones antiguas sin paréntesis adicionales.

**Diagnóstico específico para "Historial permisos":**

La subsección "Historial permisos" en `audit_full.php` probablemente hace:
```sql
SELECT cea.exit_time, cea.return_time, cea.authorization_reason,
       u.first_name, u.last_name,
       s.first_name as student_first, s.last_name as student_last,
       ag.group_name,
       -- posiblemente:
       cea.metadata_json->>'type' as permiso_type  -- ← puede fallar si la col no es jsonb
FROM class_exit_authorizations cea
JOIN users u ON u.user_id = cea.authorized_by_user_id
JOIN students s ON s.student_id = cea.student_id
LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id -- sin AND active=TRUE
LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
WHERE cea.school_id = ?
```

Si `class_exit_authorizations` no tiene columna `metadata_json`, la query falla con 500. Si tampoco tiene `return_time`, misma situación.

**Plan de corrección para audit_full.php:**

1. **Leer el archivo completo** con `grep` para encontrar todas las queries que fallan:
```bash
grep -n "metadata_json\|->>" backend/alojamiento/routes/audit_full.php | head -50
```

2. **Para cada query que use `metadata_json->>` o `metadata_json::jsonb`:**
   - Verificar que la tabla realmente tiene esa columna (`\d table_name` en psql)
   - Si no la tiene, usar `COALESCE(metadata_json::text, '{}')` con try/catch
   - O eliminar esa selección y reemplazar con campos directos

3. **Para "Historial permisos" específicamente**, la query segura sería:
```php
$stmt = $conn->prepare("
    SELECT
        TO_CHAR(cea.exit_time AT TIME ZONE 'America/Bogota', 'DD/MM/YYYY HH12:MI AM') as exit_time,
        TO_CHAR(cea.return_time AT TIME ZONE 'America/Bogota', 'HH12:MI AM') as return_time,
        cea.authorization_reason as motivo,
        u.first_name || ' ' || u.last_name AS autorizado_por,
        s.first_name || ' ' || s.last_name AS estudiante,
        COALESCE(ag.group_name, '—') as grupo
    FROM class_exit_authorizations cea
    JOIN users u ON u.user_id = cea.authorized_by_user_id
    JOIN students s ON s.student_id = cea.student_id
    LEFT JOIN student_group_assignments sga
        ON sga.student_id = s.student_id AND sga.active = TRUE
    LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
    WHERE cea.school_id = ?
      AND (cea.exit_time AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
    ORDER BY cea.exit_time DESC
    LIMIT 200
");
```

4. **Para detectar todos los 500 en audit_full.php**, añadir logging detallado:
```php
} catch (Exception $e) {
    securityLog('AUDIT_QUERY_ERROR', $e->getMessage() . ' | Module: ' . $module . ' | Line: ' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Error al consultar el módulo: ' . $module,
        // Solo en dev:
        'detail'  => (getenv('APP_ENV') === 'development') ? $e->getMessage() : null
    ]);
}
```

**Lista de módulos probables con 500 que se deben verificar:**
- Historial permisos (class_exit_authorizations + metadata_json)
- Permisos emitidos (class_exit_authorizations)
- Salidas colegio (school_exit_authorizations)
- Salidas pedagógicas (pedagogical_trip_authorizations)
- Retornos pendientes (school_exit_authorizations con status pendiente)
- Spam biométrico (biometric_events con event_result IN (...))
- Evasión interna (biometric_events con EVASION_INTERNA)
- Reporte disciplinario (attendance_incidents)

---

## PATRÓN 11 — Módulo "Seguimiento Estudiantil" en Consulta de Coordinador

### Diagnóstico

El módulo 'Seguimiento Estudiantil' aparece en el menú de Coordinador en `Consultation.jsx` (dentro de 'Incidentes'):
```js
[ROLES.COORDINADOR]: [
  { title: 'Incidentes', icon: ShieldAlert,
    items: ['Spam Biométrico', 'Vulneraciones', 'Alertas', 'Seguimiento Estudiantil'] },
  ...
]
```

El backend en `consultations.php` SÍ tiene implementado 'Seguimiento Estudiantil' (líneas 198–210), con query a la tabla `student_tracking`. Esto debería funcionar. Sin embargo, si la tabla `student_tracking` no existe aún (es una tabla del módulo de seguimiento que puede no haber sido migrada), la query lanza 500.

**Verificar:** `SELECT 1 FROM student_tracking LIMIT 1;` — si falla, la tabla no existe.

**Fix:** Añadir try/catch específico en el case o verificar la tabla antes:
```php
case 'Seguimiento Estudiantil':
    try {
        $stmt = $conn->prepare("
            SELECT s.first_name, s.last_name, s.document_number, 
                   st.status, st.updated_at, st.tracking_id, st.student_id
            FROM student_tracking st
            JOIN students s ON st.student_id = s.student_id
            WHERE st.school_id = ?
            ORDER BY CASE WHEN st.status = 'en proceso' THEN 1 ELSE 2 END, st.updated_at DESC
            LIMIT 100
        ");
        $stmt->execute([$schoolId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                    'document_number' => 'Documento', 'status' => 'Estado',
                    'updated_at' => 'Última actualización'];
    } catch (Exception $e) {
        // Si la tabla no existe, retornar vacío sin 500
        $data = [];
        $columns = ['info' => 'Sin datos de seguimiento disponibles'];
        securityLog('TRACKING_TABLE_MISSING', $e->getMessage());
    }
    break;
```

---

→ **Continúa en PLAN6.md**
