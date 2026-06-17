# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 6 de 10)

## PATRÓN 12 — Métricas Globales del Coordinador con alerta vacía

### Síntoma reportado
"Lo mismo de la alerta en coordinador en las métricas globales" — el coordinador también ve alertas con 0 estudiantes adentro.

### Diagnóstico raíz

**Archivo afectado:** `WebApp/src/pages/Dashboard.jsx` (sección de coordinador)

El coordinador probablemente usa el mismo endpoint `/dashboard/stats` que devuelve `alertsCount`. La query de alertas (dashboard.php línea 86–96) incluye:

1. `sos_alerts` sin filtro de grupo (son globales)
2. `attendance_incidents` con tipos específicos

Cuando el coordinador hace click en la card de "Alertas", llama al endpoint de detalles. Si el dashboard del coordinador llama al mismo `/dashboard/teacher-group-detail?category=alert`, que solo busca en `attendance_incidents` (no en `sos_alerts`), y no hay incidents con esos tipos para el día de hoy, el detalle muestra vacío aunque el contador diga N.

**Fix:** El endpoint `/dashboard/teacher-group-detail?category=alert` debe incluir también las `sos_alerts` cuando el rol del usuario es COORDINADOR o superior:

```php
case 'alert':
    $results = [];
    // 1. Attendance incidents
    $stmt = $conn->prepare("
        SELECT ai.incident_id, ai.incident_type as alert_type, 
               ai.detected_at as alert_at, ai.student_id,
               s.first_name, s.last_name, s.document_number,
               COALESCE(ag.group_name, '—') as group_name
        FROM attendance_incidents ai
        JOIN students s ON ai.student_id = s.student_id
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE ai.school_id = ?
          AND (ai.incident_type IN ('LATE_ARRIVAL','EARLY_EXIT','EVASION_INTERNA',
               'BIOMETRIC_FAILURE','SPAM_BIOMETRIC')
               OR ai.incident_type LIKE 'RISK_ALERT%')
          AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
          $groupWhere
        ORDER BY ai.detected_at DESC
    ");
    $stmt->execute(array_merge([$schoolId, $fromDate, $toDate], $groupName ? [$groupName] : []));
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Para coordinador/rector, incluir SOS alerts
    if (!$isTeacher) {
        $sosStmt = $conn->prepare("
            SELECT sa.alert_id as incident_id,
                   'SOS_WEBAPP' as alert_type,
                   sa.emitted_at as alert_at,
                   NULL as student_id,
                   u.first_name, u.last_name,
                   '—' as document_number, '—' as group_name
            FROM sos_alerts sa
            JOIN users u ON u.user_id = sa.emitted_by_user_id
            WHERE sa.school_id = ?
              AND (sa.emitted_at AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
              AND sa.resolved = FALSE
            ORDER BY sa.emitted_at DESC
        ");
        $sosStmt->execute([$schoolId, $fromDate, $toDate]);
        $sosRows = $sosStmt->fetchAll(PDO::FETCH_ASSOC);
        $results = array_merge($results, $sosRows);
        // Ordenar por fecha combinada
        usort($results, fn($a, $b) => strtotime($b['alert_at']) - strtotime($a['alert_at']));
    }

    $data = $results;
    break;
```

---

## PATRÓN 13 — Verificación completa de rutas del sidebar por rol

El usuario reportó inconsistencias en varios roles. Aquí la tabla completa de lo que debería tener cada rol y lo que tiene actualmente:

### PORTERO
| Sección | Debería tener | Actualmente | Fix |
|---|---|---|---|
| Dashboard | ✅ | ✅ | — |
| Operación | ✅ (solo SOS, Reportar daño, Mandar solicitud) | ✅ | Verificar que COMMANDS_CATALOG en Operation.jsx tenga roles correctos |
| Notificaciones | ✅ | ✅ | — |
| Consulta | ❌ NO | ✅ Sí (bug) | Remover del sidebar |
| Seguimiento | ❌ NO | ❌ No | — |
| Auditoría | ❌ NO | ❌ No | — |
| Enrolamiento | ❌ NO | ❌ No | — |

### AUXILIAR
| Sección | Debería tener | Actualmente | Fix |
|---|---|---|---|
| Dashboard | ✅ | ✅ | — |
| Operación | ✅ (SOS, Reportar daño, Mandar solicitud) | ✅ | — |
| Notificaciones | ✅ | ✅ | — |
| Consulta | ❌ NO | ✅ Sí (bug) | Remover del sidebar |
| Resto | ❌ NO | ❌ No | — |

### PSICORIENTADOR
| Sección | Debería tener | Actualmente | Fix |
|---|---|---|---|
| Dashboard | ✅ (como secretaria, no como docente) | ✅ (como docente, bug) | Cambiar lógica en Dashboard.jsx |
| Operación | ✅ (Citar, Permiso, SOS, Solicitud, Incidente) | ✅ | — |
| Notificaciones | ✅ + botón "Empezar seguimiento" | ✅ sin botón seguimiento | Añadir lógica en Notifications.jsx |
| Seguimiento | ✅ | ✅ | — |
| Consulta | ✅ (Análisis de Riesgo + Mis Clases) | ✅ | OK |

### SECRETARIA
| Sección | Debería tener | Actualmente | Fix |
|---|---|---|---|
| Dashboard | ✅ | ✅ | — |
| Operación | ✅ (SOS, Mandar solicitud) | ✅ | — |
| Notificaciones | ✅ | ✅ | — |
| Consulta | ✅ | ✅ (bug: todos los módulos dan error) | Fixes de Patrón 2 |
| Enrolamiento | ✅ | ✅ (bug: 3 pasos, sin biometría) | Fix de Patrón 7 |

### DOCENTE
| Sección | Debería tener | Actualmente | Fix |
|---|---|---|---|
| Dashboard | ✅ (vista por grupos) | ✅ | Fix alerta vacía |
| Operación | ✅ (Citar, Permiso, SOS, Solicitud, Incidente) | ✅ (bug: Permiso falla) | Fix echo backend |
| Notificaciones | ✅ | ✅ | — |
| Consulta | ✅ | ✅ (bug: error de carga) | Fix Patrón 2 |

### COORDINADOR
| Sección | Debería tener | Actualmente | Fix |
|---|---|---|---|
| Dashboard | ✅ (métricas globales) | ✅ | Fix alerta vacía |
| Operación | ✅ (Citar, Autorizar, Pedagógica, Horario, SOS, Solicitud) | ✅ (bugs en Autorizar, Pedagógica) | Fix echo backend |
| Notificaciones | ✅ | ✅ | — |
| Consulta | ✅ | ✅ (bug: error de carga, módulos vacíos) | Fix Patrones 2, 7 |
| Seguimiento | ✅ | ✅ | — |

### RECTOR
| Sección | Debería tener | Actualmente | Fix |
|---|---|---|---|
| Dashboard | ✅ | ✅ | — |
| Operación | ✅ (Autorizar, Pedagógica, Horario, SOS, Solicitud) | ✅ (bugs en Autorizar, Pedagógica) | Fix echo backend |
| Auditoría | ✅ | ✅ (bugs: 500s, duplicados, SQL crudo) | Fixes Patrones 8, 9, 10 |
| Notificaciones | ✅ | ✅ | — |

---

## PATRÓN 14 — Problemas en el campo `reason`/`details` del formulario de operaciones

### Síntoma relacionado
Algunos comandos en Operation.jsx usan el campo unificado `formData.details` para mapear tanto `reason`, `message` como `description`. El payload se construye así (líneas 454–457 de Operation.jsx):

```js
const payload = { ...formData };
if (command.fields.includes('reason'))      payload.reason      = formData.details;
if (command.fields.includes('message'))     payload.message     = formData.details;
if (command.fields.includes('description')) payload.description = formData.details;
```

**Problema:** `formData.details` empieza como `''` (cadena vacía). Si el usuario no llena el textarea, `payload.reason = ''`. El backend en `operations.php` tiene:

```php
$reason = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
if ($reason === '') {
    $reason = strtoupper($action) . ' - generado por sistema NEXO';
}
```

Esto maneja bien el caso vacío. Pero el campo `details` NO tiene el atributo `required` en el textarea (línea 744 de Operation.jsx):

```jsx
<textarea
  value={formData.details || ''}
  onChange={...}
  rows={4}
  className="..."
  style={INPUT_BASE}
/>
```

No tiene `required`. Para comandos donde el motivo es esencial (permiso, citar), debería ser requerido. Sin embargo, el usuario podría querer dejar el textarea opcional para ciertos comandos.

**Fix:** Añadir `required` condicionalmente según el comando:

```jsx
<textarea
  value={formData.details || ''}
  onChange={...}
  required={['citar', 'permiso', 'incidente'].includes(command.id)}
  rows={4}
  className="..."
  style={INPUT_BASE}
/>
```

---

## Problema adicional — Grupos en Operation.jsx no filtran correctamente a docentes

### Diagnóstico

En `Operation.jsx`, los grupos se cargan con:
```js
const groupsData = await studentsApi.getGroups();
```

Sin el parámetro `teacherOnly`. Para docentes, `getGroups()` sin `teacher_only=1` devuelve todos los grupos de la institución, no solo los del docente. Esto significa que un docente ve todos los grupos al abrir "Citar acudiente" o "Generar permiso", aunque solo debería ver los grupos a los que está asignado.

**Fix en Operation.jsx:**
```js
// Determinar si es rol docente/psicorientador
const isTeacherRole = userRole === 'DOCENTE' || userRole === 'PSICORIENTADOR';

// Cargar grupos según el rol
const groupsData = await studentsApi.getGroups(isTeacherRole);
```

El segundo parámetro `teacherOnly = true` ya está implementado en `studentsApi.getGroups()` (línea 61 de `students.js`) y en el backend `groups.php`. Este fix es de una línea en el frontend.

---

## Resumen de Archivos a Modificar (Frontend)

| Archivo | Cambios |
|---|---|
| `src/config/roles.js` | Remover PORTERO y AUXILIAR de Consulta en SIDEBAR_ITEMS |
| `src/pages/Operation.jsx` | Fix `getGroups(isTeacherRole)`, añadir campo `location` a daño, `required` en textarea |
| `src/pages/Consultation.jsx` | Verificar import de behaviorApi, fix psicorientador |
| `src/pages/ConsultationDrawer.jsx` | Expandir EXCLUDE_COLS, COLUMN_LABELS, fix fmt12h con TZ, mostrar error en modo no-teacher |
| `src/pages/Enrollment.jsx` | Añadir paso 4, feedback de error, conexión lector biométrico |
| `src/pages/Notifications.jsx` | Añadir botón "Empezar seguimiento" para psicorientador |
| `src/pages/Dashboard.jsx` | Fix vista psicorientador, fix alerta vacía en coordinator |
| `src/layout/Layout.jsx` | Polling de notificaciones cada 30s, fix punto verde |
| `src/utils/formatters.js` | Crear archivo utilitario compartido de formateo |

---

→ **Continúa en PLAN7.md**
