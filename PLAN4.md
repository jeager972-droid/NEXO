# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 4 de 10)

## PATRÓN 6 — Roles incorrectos en sidebar y perfiles de usuario

### Síntoma 6A — PORTERO y AUXILIAR tienen acceso a la sección "Consulta"

**Archivo afectado:** `WebApp/src/config/roles.js`

Línea 57 del SIDEBAR_ITEMS:
```js
{
  title: 'Consulta',
  path: '/consulta',
  icon: Search,
  roles: [ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PORTERO, ROLES.AUXILIAR, ROLES.PSICORIENTADOR]
}
```

**El fix:** Remover `ROLES.PORTERO` y `ROLES.AUXILIAR` de la lista de roles del ítem Consulta:

```js
{
  title: 'Consulta',
  path: '/consulta',
  icon: Search,
  roles: [ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR]
}
```

También se debe asegurar que la ruta `/consulta` en `App.jsx` o en el sistema de rutas tenga un guard que denegue acceso a PORTERO y AUXILIAR, no solo el sidebar. Si un usuario accede directamente por URL a `/consulta`, debe ver la pantalla de `Unauthorized.jsx`.

**Verificación del patrón en todo el código:** Buscar en `App.jsx` o en el router si hay algún componente `ProtectedRoute` que filtre por roles. Si no existe, se debe implementar un guard de ruta.

### Síntoma 6B — PSICORIENTADOR tiene el mismo perfil que DOCENTE (control de asistencia por grupo)

El psicorientador NO debería tener "Control de asistencia por grupo" (TeacherDetailDrawer). Su perfil debe ser similar al de secretaria, con la adición del módulo de "Seguimiento Estudiantil".

**Archivo afectado:** `WebApp/src/pages/Dashboard.jsx` (no leído completamente) y `WebApp/src/pages/Consultation.jsx`

**En Consultation.jsx**, el psicorientador actualmente tiene:
```js
[ROLES.PSICORIENTADOR]: [
  { title: 'Análisis de Riesgo', icon: ShieldAlert, items: ['Análisis de Riesgo'] },
  { title: 'Mis Clases', icon: BookOpen, items: ['Llegadas Tarde', 'Inasistencias', ...] }
]
```

La sección "Mis Clases" con módulos tipo docente **debería estar**, porque el psicorientador sí puede tener grupos asignados. Sin embargo, el "Control de asistencia por grupo" en el **Dashboard** (que muestra el teacher-style panel con grupos y presentes/ausentes/alertas) no debería aparecer.

**Fix en Dashboard.jsx:** El código del dashboard filtra por `DOCENTE || PSICORIENTADOR` para mostrar la vista de docente. Cambiar para que PSICORIENTADOR tenga una vista diferente. El psicorientador debe ver un resumen institucional (sin el GroupDetailDrawer de docente), mas las métricas globales básicas. Solo cuando hace click desde "Notificaciones → Empezar seguimiento" accede al flujo de seguimiento.

**Fix específico en Dashboard.jsx** (no tengo el archivo completo pero se puede inferir):
```jsx
// En la lógica que determina qué dashboard mostrar:
const isTeacherDashboard = user?.role === 'DOCENTE'; // SOLO docente, no psicorientador
const isPsico = user?.role === 'PSICORIENTADOR';
```

El psicorientador debe ver el dashboard con métricas globales (como secretaria o coordinador), más acceso a "Análisis de Riesgo" en Consulta.

### Síntoma 6C — Psicorientador debe recibir autorización de seguimiento desde Coordinación

**Flujo correcto:**
1. Coordinador → Operation.jsx → "Solicitar seguimiento" (o vía notificación interna) → llega al psicorientador
2. En Notifications.jsx, la notificación del psicorientador debe mostrar un botón "Empezar seguimiento"
3. Al hacer click, abre `TrackingModal`

**En Notifications.jsx:** El botón "Empezar seguimiento" debe aparecer cuando el tipo es `'SEGUIMIENTO'` o cuando `meta.action === 'iniciar_seguimiento'`. Actualmente el drawer de detalle solo muestra campos genéricos. Se debe añadir lógica condicional:

```jsx
// En el drawer de detalle de la notificación:
{meta?.action === 'iniciar_seguimiento' && meta?.student_id && (
  <button
    onClick={() => {
      setDetailNotif(null);
      // Navegar a Seguimiento con el student_id
      navigate(`/seguimiento?student_id=${meta.student_id}&student_name=${encodeURIComponent(meta.student_name || '')}`);
    }}
    className="w-full py-3 text-xs font-bold uppercase text-white"
    style={{ backgroundColor: '#003366', letterSpacing: '0.15em' }}
  >
    Empezar Seguimiento
  </button>
)}
```

**En operations.php**, añadir caso para que coordinador pueda enviar notificación de seguimiento al psicorientador:

El comando 'solicitud' ya existe y podría usarse, pero es genérico. Mejor: añadir un nuevo tipo de metadata en la notificación que distinga "iniciar_seguimiento" de una solicitud normal. El coordinador al enviar una solicitud con targetRole=PSICORIENTADOR y un student_id seleccionado, incluir en metadata `action: 'iniciar_seguimiento'`.

---

## PATRÓN 7 — Enrolamiento de estudiante: solo 3 pasos, falta registro biométrico

### Síntoma reportado
Secretaria tiene solo 3 etapas en el frontend, y en la última al dar "Guardar registro" no sucede nada. Debe haber un 4to paso para registro biométrico. Si no hay lector de huellas, mostrar error.

### Diagnóstico raíz

**Archivo afectado:** `WebApp/src/pages/Enrollment.jsx`

`STEPS` actualmente:
```js
const STEPS = [
  { n: 1, label: 'Datos Básicos'   },
  { n: 2, label: 'Identificación'  },
  { n: 3, label: 'Grado y Guardar' },
];
```

El paso 3 tiene `handleSave` que llama a `studentsApi.create()`. El problema del "no sucede nada" en la última etapa es que `handleSave` no tiene feedback visual de éxito (solo cierra el drawer si funciona, o imprime en console si falla). No hay toast ni mensaje de error visible al usuario.

**El flujo correcto según el backend:** El estudiante se registra en la BD, y luego su huella se registra desde el **dispositivo físico** (lector biométrico) de forma independiente. El frontend debe reflejar esto con claridad.

### Corrección en Enrollment.jsx

**1. Añadir paso 4 para vinculación biométrica:**

```js
const STEPS = [
  { n: 1, label: 'Datos Básicos'   },
  { n: 2, label: 'Identificación'  },
  { n: 3, label: 'Grado y Grupo'   },
  { n: 4, label: 'Registro Biométrico' },
];
```

**2. Separar "guardar estudiante" de "vincular biometría":**

El paso 3 ahora solo completa datos de grado. Al avanzar al paso 4:
- Se llama `handleSave()` para guardar el estudiante en la BD
- Si el guardado es exitoso, paso 4 intenta conectar con el lector biométrico
- Si no hay lector, muestra el mensaje de error

**3. Lógica de conexión con lector:**

Según el código backend `edge/`, el lector biométrico se comunica via un endpoint local o websocket. El frontend debe verificar la conexión con la API de edge antes de mostrar el UI de captura:

```jsx
// En el paso 4:
const [biometricStatus, setBiometricStatus] = useState('checking'); // 'checking' | 'connected' | 'error'
const [studentSaved, setStudentSaved] = useState(false);
const [savedStudentId, setSavedStudentId] = useState(null);

const handleSaveAndProceed = async () => {
  setLoading(true);
  try {
    const result = await studentsApi.create({
      first_name: form.nombres,
      last_name:  form.apellidos,
      document:   form.documento,
      grade:      form.grado,
    });
    setSavedStudentId(result.student_id);
    setStudentSaved(true);
    setStep(4); // Avanzar al paso de biometría
  } catch (err) {
    setSaveError('No se pudo registrar el estudiante. Intenta de nuevo.');
  } finally {
    setLoading(false);
  }
};

// Al llegar al paso 4, verificar conexión con lector:
useEffect(() => {
  if (step !== 4) return;
  setBiometricStatus('checking');
  // Verificar si hay lector biométrico disponible
  fetch('http://localhost:8765/status', { signal: AbortSignal.timeout(3000) })
    .then(res => res.json())
    .then(data => {
      if (data.connected) setBiometricStatus('connected');
      else setBiometricStatus('error');
    })
    .catch(() => setBiometricStatus('error'));
}, [step]);
```

**4. UI del paso 4:**

```jsx
{step === 4 && (
  <div className="space-y-5">
    {biometricStatus === 'checking' && (
      <div className="flex flex-col items-center gap-3 py-8">
        <Loader2 size={32} className="animate-spin text-[#003366]" />
        <p className="text-xs font-semibold text-slate-500">Buscando lector biométrico...</p>
      </div>
    )}
    {biometricStatus === 'error' && (
      <div className="p-5 border-2 border-red-200 bg-red-50 space-y-3">
        <div className="flex items-center gap-2">
          <AlertTriangle size={18} className="text-red-500" />
          <p className="text-sm font-bold text-red-700">Lector no encontrado</p>
        </div>
        <p className="text-xs text-red-600 leading-relaxed">
          No se encontró conexión con el lector de huellas. El estudiante fue registrado en el sistema pero <strong>no puede completar el enrolamiento biométrico</strong> en este momento.
        </p>
        <p className="text-xs text-red-600">
          Para vincular la huella, conecta el lector e ingresa nuevamente al registro del estudiante.
        </p>
        <button
          onClick={onClose}
          className="w-full py-2.5 text-xs font-bold uppercase text-white"
          style={{ backgroundColor: '#003366', letterSpacing: '0.12em' }}
        >
          Cerrar — Completar biometría después
        </button>
      </div>
    )}
    {biometricStatus === 'connected' && (
      <div className="space-y-4">
        <div className="flex items-center gap-2 text-green-600">
          <Fingerprint size={20} />
          <p className="text-sm font-bold">Lector conectado. Solicita al estudiante que coloque su dedo.</p>
        </div>
        {/* UI de captura de huella */}
      </div>
    )}
  </div>
)}
```

**5. Fix del botón de paso 3 que "no hace nada":**

Actualmente en paso 3, el botón llama a `handleSave` que no tiene feedback de error visible. Fix:

```jsx
const [saveError, setSaveError] = useState('');

const handleSaveAndProceed = async () => {
  setLoading(true);
  setSaveError('');
  try {
    const result = await studentsApi.create({...});
    setSavedStudentId(result.student_id);
    setStep(4);
  } catch (err) {
    const msg = err?.response?.data?.message || 'Error al guardar el estudiante';
    setSaveError(msg);
  } finally {
    setLoading(false);
  }
};

// En el JSX del paso 3, mostrar el error:
{saveError && (
  <p className="text-xs font-semibold text-red-500 mt-2">{saveError}</p>
)}
```

### Nota sobre datos simulados

El usuario indicó "los que están en datos simulados déjalos, esos sirven para pruebas". Esto significa que los estudiantes ya existentes con datos de prueba NO se deben modificar ni eliminar. El flujo de enrolamiento aplica solo a estudiantes nuevos. La regla "no pueden haber estudiantes registrados si no hay lector" aplica al nuevo flujo, pero no retrocede sobre los existentes.

---

## PATRÓN 8 — Logs de salida de clase duplicados

### Síntoma reportado
"EN reportes de salida de clase, sale el mismo log muchísimas veces repetido con la misma hora, mensaje y docente que autorizó."

### Diagnóstico raíz

**Sección afectada:** Módulo de auditoría del Rector (`/auditoria`) — específicamente la subsección "Salidas clase" que llama a `audit_full.php`.

El archivo `audit_full.php` (66KB) no fue leído en su totalidad, pero el patrón de duplicados en SQL es clásico: ocurre cuando hay un JOIN sin GROUP BY o sin DISTINCT que multiplica filas. Por ejemplo:

Si la query que obtiene "salidas de clase" hace JOIN entre `class_exit_authorizations` y `students` y también hace JOIN con `academic_groups`, y un estudiante tiene múltiples asignaciones históricas de grupo (incluso si `active = FALSE`), cada fila de `student_group_assignments` genera una fila duplicada.

**Query típica problemática:**
```sql
SELECT cea.exit_time, cea.authorization_reason, u.first_name, u.last_name,
       s.first_name, s.last_name, ag.group_name
FROM class_exit_authorizations cea
JOIN students s ON s.student_id = cea.student_id
JOIN users u ON u.user_id = cea.authorized_by_user_id
JOIN student_group_assignments sga ON sga.student_id = s.student_id  -- SIN filtro active
JOIN academic_groups ag ON ag.group_id = sga.group_id
WHERE cea.school_id = ?
```

Si `student_group_assignments` tiene 3 registros para el mismo estudiante (uno activo, dos inactivos), la salida aparece 3 veces.

**Fix:** Añadir `AND sga.active = TRUE` o usar `LEFT JOIN` con subquery:

```sql
SELECT cea.exit_time, cea.authorization_reason,
       u.first_name AS issuer_first, u.last_name AS issuer_last,
       s.first_name, s.last_name,
       COALESCE(ag.group_name, '—') AS group_name
FROM class_exit_authorizations cea
JOIN students s ON s.student_id = cea.student_id
JOIN users u ON u.user_id = cea.authorized_by_user_id
LEFT JOIN student_group_assignments sga
    ON sga.student_id = s.student_id AND sga.active = TRUE  -- filtro activo
LEFT JOIN academic_groups ag ON ag.group_id = sga.group_id
WHERE cea.school_id = ?
  AND (cea.exit_time AT TIME ZONE 'America/Bogota')::date BETWEEN ? AND ?
ORDER BY cea.exit_time DESC
```

**Se debe verificar el mismo patrón en TODAS las queries de `audit_full.php`** que hacen JOIN con `student_group_assignments`. Buscar con grep:

```bash
grep -n "student_group_assignments" backend/alojamiento/routes/audit_full.php
```

Todas las ocurrencias deben tener `AND sga.active = TRUE` o usar `DISTINCT`.

---

→ **Continúa en PLAN5.md**
