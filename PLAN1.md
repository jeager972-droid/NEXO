# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 1 de 10)

## Resumen Ejecutivo

Este documento es el resultado de un análisis exhaustivo de todos los archivos del proyecto NEXO: frontend (React/Vite), backend (PHP/PostgreSQL/Redis) y la capa de comunicación (Twilio WhatsApp). Se identificaron **15 bugs raíz** que cubren los síntomas reportados. La mayoría no son bugs aislados: responden a **4 patrones sistémicos** que se repiten en múltiples secciones del código.

El principio rector de este plan es: **no parches, sino soluciones estructurales**. Cada fix se aplica en la capa donde nació el problema, y luego se verifica que el mismo patrón no exista en ningún otro sitio del proyecto.

---

## PATRÓN 1 — Backend no emite respuesta de éxito en operaciones institucionales

### Síntoma reportado
- "Generar permiso" → muestra "error en la operación institucional"
- "Autorizar salida" → muestra error pero el WhatsApp llega correctamente
- "Salida pedagógica" → muestra error pero se registra en BD
- La mayoría de botones de operaciones fallan de esta forma

### Diagnóstico raíz

**Archivo afectado:** `backend/alojamiento/routes/operations.php`

El frontend usa `operationsApi.execute()` en `src/api/operations.js`:

```js
const payload = response.data;
if (payload?.status !== 'ok') {
  const err = new Error(payload?.message || 'Error en la operación institucional');
  throw err;
}
```

Esto significa que si el backend no retorna `{"status":"ok"}`, el frontend lanza error aunque la operación haya sido exitosa.

Revisando `operations.php`, los casos del `switch` que **sí** emiten respuesta JSON:
- `case 'sos'` → línea 363: `echo json_encode(['status' => 'ok', ...])`  ✅
- `case 'inasistencia'` → línea 422: `echo json_encode(['status' => 'ok', ...])` ✅
- `case 'citacion'` → línea 506: `echo json_encode(['status' => 'ok', ...])` ✅
- `case 'incidente'/'solicitud'/'daño'/'horario'` → línea 872 (compartida) ✅

Los casos que **NO** emiten respuesta JSON de éxito:
- `case 'permiso'` → líneas 511–568: inserta en BD, envía notificación interna, luego `break;` **sin echo** ❌
- `case 'autorizar_salida'` → líneas 570–667: inserta en BD, envía WhatsApp, notificación interna, luego `break;` **sin echo** ❌
- `case 'pedagogica'` → líneas 669–681: inserta condicionalmente, luego `break;` **sin echo** ❌

Adicionalmente, `case 'pedagogica'` tiene una condición muy restrictiva:
```php
if ($studentId && $destination) {
    // solo inserta si ambos están presentes
}
```
Pero el frontend envía `group` (no `student_id`) y `reason` (mapeado desde `details`, no `destination`). El campo `destination` nunca llega. Así que para `pedagogica`, incluso si se añade el echo, la BD **nunca** registra nada porque la condición de inserción falla siempre.

### Corrección para `permiso` (backend)

Después de la inserción y notificaciones, antes del `break`:

```php
logUserCommand($conn, $schoolId, $userId, $action, $params);
echo json_encode([
    'status'  => 'ok',
    'message' => 'Permiso generado correctamente',
    'data'    => ['action' => $action, 'student_id' => $studentId]
]);
break;
```

### Corrección para `autorizar_salida` (backend)

Después de las notificaciones y WhatsApp, antes del `break`:

```php
logUserCommand($conn, $schoolId, $userId, $action, $params);
echo json_encode([
    'status'  => 'ok',
    'message' => 'Salida autorizada correctamente',
    'data'    => ['action' => $action, 'student_id' => $studentId]
]);
break;
```

### Corrección para `pedagogica` (backend)

El campo que envía el frontend es `reason` (mapeado desde `details`) y `group` (no `student_id`). La lógica debe cambiar: `pedagogica` opera a nivel de grupo, no de estudiante individual. Corregir la condición y los campos esperados:

```php
case 'pedagogica':
    $groupName   = trim((string)($params['group'] ?? ''));
    $purpose     = trim((string)($params['reason'] ?? $params['message'] ?? $params['description'] ?? ''));
    $destination = trim((string)($params['destination'] ?? $purpose));

    if ($groupName) {
        // Notificar a todos los acudientes del grupo
        $guardStmt = $conn->prepare("
            SELECT DISTINCT g.whatsapp_phone, g.guardian_id
            FROM guardians g
            JOIN guardian_student_relationships gsr ON g.guardian_id = gsr.guardian_id
            JOIN student_group_assignments sga ON gsr.student_id = sga.student_id AND sga.active = TRUE
            JOIN academic_groups ag ON sga.group_id = ag.group_id
            WHERE ag.group_name = ? AND ag.school_id = ?
        ");
        $guardStmt->execute([$groupName, $schoolId]);
        $msg = "🚌 *NEXO — Salida pedagógica*\n\nGrupo: *{$groupName}*\nMotivo: {$purpose}\n\nMantente informado sobre el regreso de tu estudiante.";
        while ($gRow = $guardStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($gRow['whatsapp_phone'])) {
                enqueueTwilioJob($gRow['whatsapp_phone'], $msg, $schoolId, null, $gRow['guardian_id'], $userId, 'PEDAGOGICA');
            }
        }
    }

    logUserCommand($conn, $schoolId, $userId, $action, $params);
    echo json_encode([
        'status'  => 'ok',
        'message' => 'Salida pedagógica registrada y acudientes notificados',
    ]);
    break;
```

### Corrección en frontend — `daño` necesita campo `location`

En `Operation.jsx`, la definición del comando `daño` solo tiene `fields: ['description']`. El usuario reportó que debe incluir "ubicación". Cambio:

```js
{ 
  id: 'daño', 
  title: 'Reportar daño', 
  icon: Wrench, 
  roles: [ROLES.AUXILIAR, ROLES.PORTERO],
  fields: ['location', 'description']   // añadir 'location'
}
```

El backend en `case 'daño'` ya incluye el campo en el mensaje de WhatsApp si se pasa correctamente, porque usa `$reason` que viene de `params['description']`. El campo `location` se debe añadir al mensaje:

```php
if ($action === 'daño') {
    $locationDaño = trim((string)($params['location'] ?? 'No especificada'));
    $msg = "⚠️ *NEXO — Alerta institucional*\n\nTipo: *DAÑO*\nUbicación: {$locationDaño}\nReportado por: {$role}\nDetalle: {$reason}";
    // ... resto del código de notificación
}
```

### Verificación del patrón en todo el código

Se revisaron todos los `case` del `switch` en `operations.php`. Solo los tres casos mencionados (`permiso`, `autorizar_salida`, `pedagogica`) carecen de echo. Los demás (`sos`, `inasistencia`, `citacion`, `incidente`, `solicitud`, `daño`, `horario`) ya tienen respuesta correcta. **El parche es quirúrgico y completo.**

---

## Alcance de este patrón

| Comando | Backend OK | Echo OK | Frontend falla | Fix |
|---|---|---|---|---|
| SOS | ✅ | ✅ | No | — |
| Citar acudiente | ✅ | ✅ | No | — |
| Inasistencia | ✅ | ✅ | No | — |
| **Generar permiso** | ✅ | ❌ | **Sí** | Añadir echo |
| **Autorizar salida** | ✅ | ❌ | **Sí** | Añadir echo |
| **Salida pedagógica** | ❌ | ❌ | **Sí** | Fix lógica + echo |
| Reportar daño | ✅ | ✅ | No | + campo location |
| Solicitud | ✅ | ✅ | No | — |
| Cambio horario | ✅ | ✅ | No | — |
| Incidente | ✅ | ✅ | No | — |

→ **Continúa en PLAN2.md**
