# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 3 de 10)

## PATRÓN 3 — Reagendamiento de citación no llega como notificación al profesor

### Síntoma reportado
El acudiente responde "2" → el sistema pide motivo → el acudiente escribe el motivo → se le dice que "se enviará al profesor" → pero nunca llega en notificaciones de la webapp. Debería llegar: "Reagendamiento para el estudiante X, ver detalles, y adentro, el motivo."

### Diagnóstico raíz

**Archivo afectado:** `backend/alojamiento/routes/misc.php` — sección del webhook `/webhooks/twilio/inbound`

El flujo de reagendamiento en `misc.php` tiene **dos etapas**:

**Etapa 1 — Respuesta "2" del acudiente (líneas 579–653):**
```php
} elseif ($trimBody === '2') {
    // Guarda estado 'reagendar:PHONE' en Redis
    $redisConv->setex('reagendar:' . $normalizedFrom, 172800, json_encode([
        'guardian_id'    => $guardianId,
        'school_id'      => $schoolId,
        'student_id'     => $resolvedStudentId,
        'student_name'   => $studentName,
        'teacher_user_id'=> $teacherRef['sender_user_id'] ?? null,
        'ts'             => time()
    ]));
    // Pide motivo al acudiente
    $replyMsg = "Por favor, escriba brevemente qué fecha y hora le quedan más fáciles...";
    sendTwilioDirect($from, $replyMsg);
```

**Etapa 2 — Acudiente envía motivo (líneas 447–488):**
```php
if ($reagendarState && $trimBody !== '1' && $trimBody !== '2') {
    $motivo = $body;
    // Notificar al profesor
    if ($teacherRef && !empty($teacherRef['sender_user_id'])) {
        $meta = json_encode([...]);
        $notifStmt->execute([...]);
    }
}
```

**El bug:** En la Etapa 2, `$teacherRef` se resuelve en las **líneas 410–422**, ANTES de verificar `$reagendarState`. La consulta para obtener `sender_user_id` es:

```sql
SELECT sender_user_id
FROM twilio_messages
WHERE school_id = ?
  AND guardian_id = ?
  AND type_code = 'CITACION'
  AND direction = 'OUTBOUND'
  AND sender_user_id IS NOT NULL
ORDER BY sent_at DESC
LIMIT 1
```

**El problema es doble:**

1. Cuando se usa `enqueueTwilioJob()` (a través de Redis), el worker `worker_twilio.php` hace el envío real. Si el worker NO guarda `sender_user_id` al insertar en `twilio_messages`, el campo quedará NULL y la query no encontrará ningún registro → `$teacherRef = null` → la notificación al profesor NUNCA se crea.

2. Cuando Redis no está disponible y se usa `sendTwilioDirect()` de fallback, el log de Twilio en `logTwilioMessageSafe()` SÍ recibe `$senderUserId` → en ese caso funciona. Pero con Redis activo (modo normal), el worker no tiene el `sender_user_id` original.

3. Adicionalmente, en la Etapa 2, `$teacherRef` se intenta obtener de `twilio_messages` con `guardian_id = $guardianId`. Pero `$guardianId` se obtiene de la tabla `guardians` filtrando por `whatsapp_phone_normalized`. Si el teléfono del acudiente en BD no está correctamente normalizado (formato `+57XXXXXXXXXX` vs `57XXXXXXXXXX`), no encontrará al guardian y todo falla silenciosamente.

**Causa raíz confirmada:** `sender_user_id` no se propaga al worker. En `enqueueTwilioJob()` se encola `$senderUserId` pero el `worker_twilio.php` debe usarlo al hacer el log de la BD. Si el worker llama a `logTwilioMessageSafe` sin pasar el `sender_user_id`, ese campo queda NULL.

### Corrección en misc.php

En la Etapa 2, en lugar de depender exclusivamente de `twilio_messages.sender_user_id` (que puede ser NULL), usar el estado de Redis como fuente primaria:

```php
// Obtener teacher_user_id del estado Redis de reagendamiento (más confiable)
$teacherUserId = $reagendarState['teacher_user_id'] ?? null;

// Fallback: buscar en twilio_messages
if (!$teacherUserId && $teacherRef) {
    $teacherUserId = $teacherRef['sender_user_id'] ?? null;
}

if ($teacherUserId) {
    $meta = json_encode([
        'student_name'  => $studentName ?: ($reagendarState['student_name'] ?? 'Estudiante'),
        'action'        => 'reagendar_motivo',
        'guardian_phone'=> $from,
        'motivo'        => $motivo,
    ], JSON_UNESCAPED_UNICODE);
    $notifStmt = $conn->prepare("
        INSERT INTO notifications (school_id, user_id, title, message, type, metadata_json, created_at)
        VALUES (?, ?, 'Reagendamiento', ?, 'INFO', ?::jsonb, NOW())
    ");
    $notifStmt->execute([
        $schoolId,
        $teacherUserId,
        "El acudiente de: " . ($studentName ?: 'Estudiante') . " envió el motivo de reagendamiento. Ver detalles.",
        $meta
    ]);
}
```

En la Etapa 1 (respuesta "2"), también guardar el `sender_user_id` en el estado Redis. El `$teacherRef['sender_user_id']` se conoce en ese momento; ya se guarda correctamente en `teacher_user_id`. Si es null en ese punto, es porque el mensaje original de citación no tiene el campo. Corrección en el query de citación (`operations.php`, `case 'citacion'`): al llamar `enqueueTwilioJob`, asegurarse que `$userId` (el que emite la citación) se pase como `$senderUserId`:

```php
// Ya existe correctamente en línea 459:
$deliveryResults[] = enqueueTwilioJob($target['whatsapp_phone'], $citMsg, $schoolId, $studentId, $target['guardian_id'], $userId, 'CITACION');
```

Esto es correcto. El problema está en el **worker**. En `worker_twilio.php`, cuando el job se procesa, el `sender_user_id` del job encolado debe persistirse en `twilio_messages`. Verificar que el worker incluya ese campo en el INSERT.

### Corrección en worker_twilio.php

El worker debe extraer `sender_user_id` del payload y pasarlo al log:

```php
// En worker_twilio.php, al procesar cada job:
$senderUserId = $job['sender_user_id'] ?? null;
// Pasar al log de twilio_messages
logTwilioMessageSafe($conn, $schoolId, $typeCode, 'OUTBOUND', $toNorm, $body,
    ['action' => 'worker_sent', 'source' => 'worker_twilio'],
    $studentId, $guardianId, $senderUserId, $result['sid'], 'SENT');
```

---

## PATRÓN 4 — Campana de notificaciones no muestra el punto verde

### Síntoma reportado
"Cuando se emana una notificación no sale el puntito verde en la campana"

### Diagnóstico raíz

**Archivo afectado:** `WebApp/src/layout/Layout.jsx`

El punto verde se muestra cuando `notifCount > 0` (línea 301–307). El conteo se carga **una sola vez al montar** (líneas 120–129):

```jsx
useEffect(() => {
  const alreadySeen = sessionStorage.getItem('nexo:notif-seen') === 'true';
  if (alreadySeen) { setNotifCount(0); return; }
  notificationsApi.getAll()
    .then(data => setNotifCount(Array.isArray(data) ? data.length : 0))
    .catch(() => {});
}, []);
```

El problema: después del mount, **no hay polling**. Cuando una operación crea una notificación nueva en el backend (por ejemplo, un coordinador emite un permiso → se crea notificación interna para el propio coordinator), el frontend no sabe que existe esa nueva notificación porque no vuelve a consultar.

Además, en `Notifications.jsx` línea 38–39:
```jsx
emitCount(0);
sessionStorage.setItem(SEEN_KEY, 'true');
```
Una vez que el usuario visita Notificaciones, el punto queda permanentemente en 0 para esa sesión, aunque lleguen nuevas notificaciones.

### Corrección en Layout.jsx

Añadir polling cada 30 segundos para actualizar el conteo, que se cancela cuando la sesión ya fue vista:

```jsx
useEffect(() => {
  // Función de polling que actualiza el conteo
  const pollNotifs = () => {
    const alreadySeen = sessionStorage.getItem('nexo:notif-seen') === 'true';
    if (alreadySeen) return; // No pollear si ya se visitó la página
    notificationsApi.getAll()
      .then(data => setNotifCount(Array.isArray(data) ? data.length : 0))
      .catch(() => {});
  };

  pollNotifs(); // Carga inicial
  const interval = setInterval(pollNotifs, 30000); // Cada 30 segundos
  return () => clearInterval(interval);
}, []);
```

Adicionalmente, limpiar la sesión de "seen" cuando el usuario recibe una nueva notificación via evento. Agregar un listener de custom event que permita reactivar el punto:

```jsx
useEffect(() => {
  const handler = (e) => {
    const count = e.detail?.count ?? 0;
    setNotifCount(count);
    // Si count > 0, resetear el "seen" para que el punto vuelva a aparecer
    if (count > 0) sessionStorage.removeItem('nexo:notif-seen');
  };
  window.addEventListener('nexo:notif-count', handler);
  return () => window.removeEventListener('nexo:notif-count', handler);
}, []);
```

Y en `Notifications.jsx`, cuando se marcan como vistas, en vez de emitir `count: 0` permanentemente, solo marcar si el usuario tiene el listado visible:

```jsx
// Al cargar Notifications.jsx:
emitCount(0);
sessionStorage.setItem(SEEN_KEY, 'true');
// Cuando el usuario navegue lejos y vuelvan notificaciones nuevas,
// el polling en Layout.jsx las detectará y el removeItem del SEEN_KEY permitirá mostrar el punto.
```

### Corrección en Operation.jsx

Cuando una operación exitosa podría generar una notificación, notificar el layout. Por ejemplo, después de `onClose()`:

```jsx
// En handleSubmit, después del switch exitoso:
onClose();
// Señalizar que puede haber notificaciones nuevas (solo si hay recipient)
if (['citar', 'permiso', 'autorizar', 'solicitud'].includes(command.id)) {
  window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count: 1 } }));
}
```

Esto es una señal optimista — el polling real en 30s corregirá el valor exacto.

---

## PATRÓN 5 — Dashboard docente: 0 estudiantes en grupos pero sí alertas, y al entrar a alerta sale vacío

### Síntoma reportado
- Los grupos que le salen a `docente@nexo.edu` muestran 0 estudiantes pero 1 alerta
- Al entrar en la alerta no sale nada adentro: "0 estudiantes, sin registro hoy"
- Lo mismo para coordinador en métricas globales

### Diagnóstico raíz

**Archivo afectado:** `backend/alojamiento/routes/dashboard.php`

**Sub-problema A: 0 estudiantes en el grupo**

La query de grupos/estudiantes para docentes (líneas 110–138) une `schedules` con `academic_groups` con `students`. Si el docente **tiene entradas en `schedules`** pero los grupos no tienen estudiantes en `student_group_assignments`, el resultado es cero. Esto ocurre en datos de prueba donde los schedules pueden haberse creado sin asignar estudiantes al grupo.

Más importante: `teacherGroups` se obtiene de `schedules` (líneas 142–151). Si hay grupos pero sin estudiantes asignados, `studentsByGroup[groupName]` estaría vacío, lo que da `[]` (arreglo vacío). El dashboard frontend entonces muestra "0 estudiantes".

**Sub-problema B: Sí hay 1 alerta**

El conteo de alertas (líneas 86–96) incluye `sos_alerts` globales (sin filtro de grupo) más `attendance_incidents`. Si hay un SOS alert de hoy, suma 1 al total aunque no tenga student_id ni esté relacionado al grupo del docente.

**Sub-problema C: Al entrar a la alerta sale vacío**

El endpoint `/dashboard/teacher-group-detail?category=alert` (líneas 290–311) hace JOIN entre `attendance_incidents` y `students`. Los SOS alerts **NO están en `attendance_incidents`** — están en `sos_alerts`. Entonces el detail siempre muestra 0 aunque el card marque 1.

### Corrección en dashboard.php

**Para el conteo de alertas de docente:** filtrar correctamente y excluir SOS globales si el rol es DOCENTE:

```php
// Para docentes, no contar SOS (son globales, no del grupo)
$alertsSqlTeacher = "
    SELECT COUNT(*) FROM attendance_incidents ai
    WHERE ai.school_id = ?
      AND (ai.detected_at AT TIME ZONE 'America/Bogota')::date = (CURRENT_TIMESTAMP AT TIME ZONE 'America/Bogota')::date
      AND (ai.incident_type IN ('LATE_ARRIVAL', 'EARLY_EXIT', 'EVASION_INTERNA', 'BIOMETRIC_FAILURE', 'SPAM_BIOMETRIC')
           OR ai.incident_type LIKE 'RISK_ALERT%')
      AND ai.student_id IN (
          SELECT sga.student_id FROM student_group_assignments sga
          JOIN academic_groups ag ON ag.group_id = sga.group_id
          JOIN schedules sch ON sch.group_id = ag.group_id
          WHERE sch.teacher_user_id = ? AND sga.active = TRUE
      )
";
if ($isTeacher) {
    $alertsStmt = $conn->prepare($alertsSqlTeacher);
    $alertsStmt->execute([$schoolId, $authUser['id']]);
    $alertsCount = $alertsStmt->fetchColumn();
} // else usar query global existente
```

**Para el detail de alerta, también incluir datos de la propia query:**
En `teacher-group-detail?category=alert`, si el resultado es vacío pero el dashboard dice que hay alertas, añadir un message indicativo en la response del backend (no crashear silenciosamente).

---

→ **Continúa en PLAN4.md**
