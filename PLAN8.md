# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 8 de 10)

## PLAN DE EJECUCIÓN — Orden de Correcciones por Criticidad

Esta sección define el orden exacto en que se deben aplicar las correcciones. Se clasifican en tres fases:

- **FASE 1 — Crítico** (bloquea el uso normal del sistema): 4 fixes
- **FASE 2 — Importante** (degrada experiencia significativamente): 6 fixes
- **FASE 3 — Mejora** (pulido, deuda técnica): múltiples fixes

---

## FASE 1 — Correcciones Críticas (hacer primero, sin excepción)

### Fix 1.1 — Añadir echo de éxito en permiso y autorizar_salida

**Archivo:** `backend/alojamiento/routes/operations.php`

**Buscar en el archivo:**
- El `case 'permiso':` (alrededor de línea 511)
- El `case 'autorizar_salida':` (alrededor de línea 570)

En cada uno, antes del `break;` final, añadir:

```php
// Al final del case 'permiso', antes del break:
logUserCommand($conn, $schoolId, $userId, 'permiso', $params);
securityLog('OPERATION_EXECUTED', "User:$userId Role:$role Action:permiso");
echo json_encode([
    'status'  => 'ok',
    'message' => 'Permiso generado y acudiente notificado',
    'data'    => ['action' => 'permiso', 'student_id' => $studentId]
]);
break;

// Al final del case 'autorizar_salida', antes del break:
logUserCommand($conn, $schoolId, $userId, 'autorizar_salida', $params);
securityLog('OPERATION_EXECUTED', "User:$userId Role:$role Action:autorizar_salida");
echo json_encode([
    'status'  => 'ok',
    'message' => 'Salida autorizada correctamente',
    'data'    => ['action' => 'autorizar_salida', 'student_id' => $studentId]
]);
break;
```

**Verificación:** Después del fix, el botón "Generar permiso" debe mostrar el drawer de confirmación ("Permiso generado y acudiente notificado") en lugar del error rojo.

### Fix 1.2 — Fix lógica de salida pedagógica

**Archivo:** `backend/alojamiento/routes/operations.php`

**Buscar:** `case 'pedagogica':` (alrededor de línea 669)

Reemplazar el bloque completo por la versión corregida documentada en PLAN1.md (que usa `group` y `reason` en lugar de `student_id` y `destination`).

**Verificación:** El botón "Salida pedagógica" debe mostrar éxito y los acudientes del grupo deben recibir WhatsApp.

### Fix 1.3 — Remover PORTERO y AUXILIAR de Consulta

**Archivo:** `WebApp/src/config/roles.js`

Línea 57, cambiar:
```js
roles: [ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.PSICORIENTADOR]
```
a:
```js
roles: [ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR]
```

Añadir también guard en `Consultation.jsx` al inicio del componente funcional:
```jsx
const allowedRoles = ['COORDINADOR', 'SECRETARIA', 'DOCENTE', 'PSICORIENTADOR'];
if (!allowedRoles.includes(user?.role)) {
  return <Navigate to="/" replace />;
}
```

**Verificación:** Un usuario PORTERO no debe ver "Consulta" en el sidebar, y si escribe `/consulta` en el URL debe ser redirigido al Dashboard.

### Fix 1.4 — Fix importación en Consultation.jsx

**Archivo:** `WebApp/src/pages/Consultation.jsx`

Verificar que las siguientes líneas de importación sean correctas y no rompan el bundle:
- `import { behaviorApi } from '../api/behavior';` — si `behavior.js` tiene un export mal formado, toda la página crashea
- `import { consultationsApi } from '../api/consultations';` — verificar que el archivo se llame `consultations.js` y no `consultation.js` (hay AMBOS archivos: `consultation.js` con `consultationApi` y `consultations.js` con `consultationsApi`)

Si Consultation.jsx importa `from '../api/consultations'` pero el archivo real es `consultation.js` (sin la 's'), el import falla silenciosamente en algunos bundlers o lanza error en otros.

**Verificación:** Abrir la DevTools → Console al entrar a `/consulta`. Si hay un import error, se verá ahí. Corregir la importación.

---

## FASE 2 — Correcciones Importantes

### Fix 2.1 — Crear `src/utils/formatters.js` e integrar en ConsultationDrawer

**Pasos:**
1. Crear `WebApp/src/utils/formatters.js` con las funciones `fmt12h`, `formatCellValue`, `ENUM_LABELS`, `COLUMN_LABELS` (contenido completo en PLAN5.md)
2. En `ConsultationDrawer.jsx`, reemplazar las definiciones locales por los imports del nuevo archivo
3. En `Audit.jsx`, añadir los mismos imports para que el formateo de celdas sea consistente

**Verificación:** Las celdas de fecha/hora deben mostrarse como "15 Jun 2024, 2:30 p. m." en todos los módulos.

### Fix 2.2 — Añadir display de error en ConsultationDrawer (ruta no-teacher)

En `ConsultationDrawer.jsx`, en la sección que renderiza módulos de no-docentes (dentro del bloque `else` para `isTeacherModule === false`), añadir el bloque de error antes del contenido:

```jsx
{/* Error state */}
{error && !loadingData && (
  <div className="flex flex-col items-center justify-center h-full py-20 gap-4">
    <div className="w-14 h-14 flex items-center justify-center bg-red-50 border border-red-100">
      <AlertTriangle size={24} strokeWidth={1.5} className="text-red-400" />
    </div>
    <div className="text-center space-y-2 max-w-xs px-4">
      <p style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.15em',
                  color: '#EF4444', textTransform: 'uppercase' }}>
        Error de consulta
      </p>
      <p style={{ fontSize: '11px', color: '#94A3B8', lineHeight: 1.6 }}>
        {error}
      </p>
    </div>
    {onQuery && (
      <button onClick={onQuery}
        className="px-4 py-2 text-xs font-bold uppercase"
        style={{ border: '1.5px solid #E2E8F0', color: '#64748B', letterSpacing: '0.1em' }}>
        Reintentar
      </button>
    )}
  </div>
)}
```

### Fix 2.3 — Polling de notificaciones en Layout.jsx

Reemplazar el `useEffect` de carga inicial por el que incluye polling (30s) documentado en PLAN3.md.

### Fix 2.4 — Implementar casos faltantes en consultations.php

Añadir en `consultations.php` los cases para:
- 'Spam Biométrico' (query en PLAN2.md)
- 'Permisos Emitidos' (query en PLAN2.md)
- 'Salidas del colegio permitidas' (query en PLAN2.md)
- 'Salidas Pedagógicas' (query en PLAN2.md)

También añadir los módulos de secretaria:
```php
case 'Matrículas':
    // Estudiantes activos con fecha de matrícula
    $stmt = $conn->prepare("
        SELECT s.first_name, s.last_name, s.document_number,
               COALESCE(ag.group_name, 'Sin grupo') as group_name,
               TO_CHAR(s.created_at AT TIME ZONE 'America/Bogota', 'DD/MM/YYYY') as enrolled_at,
               CASE WHEN s.active THEN 'Activo' ELSE 'Inactivo' END as estado
        FROM students s
        LEFT JOIN student_group_assignments sga ON sga.student_id = s.student_id AND sga.active = TRUE
        LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
        WHERE s.school_id = ?
        ORDER BY s.created_at DESC
        LIMIT 200
    ");
    $stmt->execute([$schoolId]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                'document_number' => 'Documento', 'group_name' => 'Grupo',
                'enrolled_at' => 'Matrícula', 'estado' => 'Estado'];
    break;

case 'Personal Institucional':
case 'Auxiliares':
case 'Portería':
    $roleFilter = [
        'Personal Institucional' => ['DOCENTE', 'SECRETARIA', 'PORTERO', 'AUXILIAR', 'PSICORIENTADOR', 'COORDINADOR'],
        'Auxiliares'             => ['AUXILIAR'],
        'Portería'               => ['PORTERO'],
    ][$module];
    $placeholders = implode(',', array_fill(0, count($roleFilter), '?'));
    $stmt = $conn->prepare("
        SELECT u.first_name, u.last_name, u.email, u.phone, r.role_name as rol,
               CASE WHEN u.active THEN 'Activo' ELSE 'Inactivo' END as estado
        FROM users u
        JOIN roles r ON r.role_id = u.role_id
        WHERE u.school_id = ? AND UPPER(r.role_name) IN ($placeholders)
        ORDER BY u.last_name, u.first_name
    ");
    $stmt->execute(array_merge([$schoolId], $roleFilter));
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = ['first_name' => 'Nombre', 'last_name' => 'Apellido',
                'email' => 'Email', 'phone' => 'Teléfono',
                'rol' => 'Rol', 'estado' => 'Estado'];
    break;

case 'Reportes':
case 'Auditoría Local':
    $stmt = $conn->prepare("
        SELECT report_type as tipo, 
               TO_CHAR(generated_at AT TIME ZONE 'America/Bogota', 'DD/MM/YYYY HH12:MI AM') as generado_en,
               format as formato,
               COALESCE(status, 'completado') as estado
        FROM report_exports
        WHERE school_id = ?
        ORDER BY generated_at DESC
        LIMIT 100
    ");
    $stmt->execute([$schoolId]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $columns = ['tipo' => 'Tipo', 'generado_en' => 'Generado en', 'formato' => 'Formato', 'estado' => 'Estado'];
    break;
```

### Fix 2.5 — Fix reagendamiento en misc.php

Usar `teacher_user_id` del estado Redis en la Etapa 2 del webhook de Twilio (documentado en detalle en PLAN3.md).

### Fix 2.6 — Fix duplicados en audit_full.php

Leer el archivo completo y añadir `AND sga.active = TRUE` en todos los JOINs con `student_group_assignments`. Usar el comando grep documentado en PLAN4.md para encontrar todas las ocurrencias.

---

## FASE 3 — Mejoras y Pulido

### Fix 3.1 — Enrolamiento: añadir paso 4 con verificación de lector

Implementar en `Enrollment.jsx` el paso 4 completo documentado en PLAN4.md.

### Fix 3.2 — Dashboard psicorientador

Cambiar Dashboard.jsx para que psicorientador no use la vista de docente con GroupDetailDrawer.

### Fix 3.3 — Botón "Empezar seguimiento" en Notifications.jsx

Añadir lógica condicional en el drawer de detalle de notificación.

### Fix 3.4 — Guards de ruta para /enrolamiento y /auditoria

Verificar que usuarios sin rol correcto sean redirigidos al Dashboard si acceden directamente por URL.

### Fix 3.5 — Timeout en axios client

Añadir `timeout: 25000` en `src/api/client.js`.

### Fix 3.6 — Fix "Reportar daño" sin campo ubicación

En `Operation.jsx`, añadir `location` al array de fields del comando `daño`.

### Fix 3.7 — Normalización de teléfonos en guardians

Ejecutar el script SQL de normalización documentado en PLAN7.md.

### Fix 3.8 — Filtro de grupos para docente en Operation.jsx

Cambiar `studentsApi.getGroups()` por `studentsApi.getGroups(isTeacherRole)`.

---

→ **Continúa en PLAN9.md**
