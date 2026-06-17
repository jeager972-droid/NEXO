# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 7 de 10)

## Resumen de Archivos a Modificar (Backend)

| Archivo | Cambios |
|---|---|
| `routes/operations.php` | Añadir echo en permiso, autorizar_salida; fix lógica en pedagogica; añadir location a daño |
| `routes/consultations.php` | Implementar cases para Spam Biométrico, Permisos Emitidos, Salidas del colegio, Salidas Pedagógicas; fix Seguimiento Estudiantil con try/catch |
| `routes/misc.php` | Fix reagendamiento: usar teacher_user_id de Redis primero; fix normalización de teléfono |
| `routes/dashboard.php` | Fix alertas para docente (solo las del grupo); incluir SOS en detail de coordinador |
| `routes/audit_full.php` | Fix duplicados con AND sga.active=TRUE; fix 500 en metadata_json; fix Historial permisos |
| `routes/groups.php` | Sin cambios (está correcto) |
| `routes/students.php` | Sin cambios (está correcto) |
| `worker_twilio.php` | Propagar sender_user_id en log de BD |

---

## PATRÓN 15 — Normalización de teléfonos para acudientes en webhook Twilio

### Síntoma relacionado (diagnóstico preventivo)

En `misc.php`, la resolución del guardian (línea 342–350) busca por `whatsapp_phone_normalized`:
```php
$guardianStmt = $conn->prepare("
    SELECT g.guardian_id, u.school_id
    FROM guardians g
    JOIN users u ON u.user_id = g.user_id
    WHERE g.whatsapp_phone_normalized = ?
    LIMIT 1
");
$guardianStmt->execute([$normalizedFrom]);
```

Y `$normalizedFrom = preg_replace('/[^0-9+]/', '', $from)`.

Si el número viene de Twilio como `whatsapp:+573001234567`, la función `normalizeWhatsAppPhone()` (líneas 6–12) quita el prefijo `whatsapp:` y lo normaliza. El `$normalizedFrom` resultante sería `+573001234567`.

Pero en la BD, `whatsapp_phone_normalized` puede estar guardado como `573001234567` (sin el +), o como `+57 300 1234567` (con espacios). Cualquier discrepancia hace que `$guardian` sea `null` y el webhook retorna sin procesar.

**Fix:** La columna `whatsapp_phone_normalized` debe tener un índice y todos los números en la BD deben seguir el mismo formato. El script de normalización de los datos existentes:

```sql
-- Normalizar todos los whatsapp_phone_normalized a formato +XXXXXXXXXXX
UPDATE guardians 
SET whatsapp_phone_normalized = regexp_replace(
    CASE 
      WHEN whatsapp_phone_normalized LIKE '+%' THEN whatsapp_phone_normalized
      WHEN whatsapp_phone_normalized LIKE '57%' THEN '+' || whatsapp_phone_normalized
      ELSE '+57' || regexp_replace(whatsapp_phone_normalized, '[^0-9]', '', 'g')
    END,
    '[^0-9+]', '', 'g'
)
WHERE whatsapp_phone_normalized IS NOT NULL;
```

Y asegurar que al crear acudientes nuevos, siempre se normalice el teléfono antes de insertar.

---

## DEUDA TÉCNICA IDENTIFICADA (no son bugs críticos pero deben anotarse)

### Deuda 1 — Sin `AbortController` en Consultation.jsx

Si el usuario cierra el drawer mientras una query está en vuelo, el state update ocurre sobre un componente desmontado. React 18 maneja esto mejor pero puede causar warnings.

**Fix:**
```js
// En el useEffect de executeQuery:
const abortController = new AbortController();
// Pasar signal a la llamada HTTP
return () => abortController.abort();
```

### Deuda 2 — Sin manejo de timeout en llamadas a backend

Si el backend tarda más de 30s (query lenta en PostgreSQL), la UI queda cargando indefinidamente. Añadir timeout en el axios client:

```js
// En src/api/client.js:
const client = axios.create({
  baseURL: ...,
  withCredentials: true,
  timeout: 25000, // 25 segundos máximo
});
```

### Deuda 3 — Paginación en ConsultationDrawer no implementada

El backend limita resultados a 50–200 filas por módulo (LIMIT). Si hay más datos, el usuario no lo sabe y tampoco puede pedir más. La interfaz debería mostrar "Mostrando X de N resultados" y un botón "Cargar más".

### Deuda 4 — Sin feedback de carga en TeacherQueryPanel

Cuando el docente selecciona un grupo y fecha y ejecuta la consulta, el estado de carga no siempre es visible. Asegurar que `loadingData` muestra un spinner visible en el área del resultado.

### Deuda 5 — No hay guard de ruta para /consulta

Si un PORTERO o AUXILIAR escribe directamente `/consulta` en el URL, puede acceder aunque el sidebar no lo muestre. Agregar verificación de rol en el componente de ruta:

```jsx
// En App.jsx o en Consultation.jsx inicio:
const allowedForConsulta = [
  ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR
];
if (!allowedForConsulta.includes(user?.role)) {
  return <Navigate to="/" replace />;
}
```

### Deuda 6 — Sin ruta guard para /enrolamiento

La ruta `/enrolamiento` solo debe accesible para SECRETARIA. Actualmente el sidebar lo filtra, pero no hay guard en la ruta. Si un DOCENTE escribe la URL manualmente, llega a Enrollment.jsx. El backend `students.php` en POST requiere `['SECRETARIA', 'RECTOR', 'COORDINADOR']`, pero el frontend no tiene esa verificación.

### Deuda 7 — ConsultationDrawer abre con estado previo

Si el usuario cierra el drawer de un módulo y abre otro, el estado anterior (dynamicData, dynamicColumns, hasQueried) puede persistir brevemente. En Consultation.jsx:

```js
// Al setActiveItem(item) (al abrir nuevo módulo):
setDynamicData([]);
setDynamicColumns({});
setHasQueried(false);
setQueryError('');
```

Si esta limpieza no ocurre o no es síncrona antes de que el drawer renderice, el usuario verá datos del módulo anterior mientras carga el nuevo.

### Deuda 8 — Secretaria y módulo "Historial permisos" interno

En el menú de Consulta de Secretaria, no hay un módulo de "Historial permisos". El historial de permisos solo está en Auditoría (rector). Sin embargo, secretaria necesita saber quién tiene permiso hoy para verificar entradas/salidas. Se debería añadir a su menú: 'Permisos Activos Hoy' que consulte `attendance_incidents` con `incident_type IN ('PERMISO', 'AUTORIZAR_SALIDA')` para el día de hoy.

---

## VERIFICACIÓN DE ESTABILIDAD DE DATOS EN MULTI-TENANT

### Riesgo: RLS desactivado

El backend usa `school_id` en cada query como filtro manual (no RLS de PostgreSQL). Si algún endpoint olvida incluir `school_id` en el WHERE, una institución podría ver datos de otra. Se encontraron las siguientes queries sin filtro de `school_id`:

**En `consultations.php` línea 254–263 (Mensajes Internos):**
```sql
SELECT ... FROM internal_messages im
WHERE im.school_id = ? AND (im.receiver_user_id = ? OR im.sender_user_id = ?)
```
→ Está bien, tiene `school_id`. ✅

**En `consultations.php` línea 220–232 (Mensajes enviados - admin):**
```sql
SELECT ... FROM twilio_messages m
WHERE m.school_id = :sid
ORDER BY m.sent_at DESC
LIMIT 200
```
→ Está bien. ✅

**En `consultations.php` línea 234–246 (Mensajes enviados - no admin):**
```sql
SELECT ... FROM twilio_messages m
WHERE m.school_id = :sid AND m.sender_user_id = :tid
```
→ Está bien. ✅

**En `audit_full.php` (no leído completamente):** Verificar con grep que TODAS las queries incluyen `WHERE ... school_id = ?`. Comando de verificación:

```bash
grep -n "FROM " backend/alojamiento/routes/audit_full.php | head -60
```

Para cada SELECT, verificar que hay un WHERE con school_id en el contexto del bloque.

---

## ESTADOS DE ERROR ESPERADOS vs ACTUALES

### Cómo debería verse un módulo al fallar

**Estado actual:** El módulo muestra pantalla en blanco o "Sin datos disponibles" cuando hay un error 500. El usuario no sabe si es un error o si realmente no hay datos.

**Estado esperado:** Dos casos distintos:
1. **Sin datos reales:** Mostrar ícono de tabla vacía + "No hay registros en el rango de fechas seleccionado"  
2. **Error del servidor:** Mostrar ícono de alerta + "Error al consultar el módulo. Intenta de nuevo." + botón Reintentar

La diferencia entre ambos viene del campo `status` del backend:
- `{ status: 'ok', data: [] }` → caso 1 (sin datos)
- `{ status: 'error', message: '...' }` o error HTTP 500 → caso 2 (error)

En `Consultation.jsx`, `consultationsApi.queryModule` retorna `response.data ?? { data: [], columns: {} }`. Esto significa que si el backend devuelve un error HTTP 500, axios lanza una excepción y el `catch` en `Consultation.jsx` captura el error y setea `queryError`. Pero si el backend retorna `{"status":"error"}` con HTTP 200, entonces `response.data.status === 'error'` pero no hay excepción — el código lo trata como datos vacíos.

**Fix en `consultationsApi.queryModule`:**
```js
queryModule: async (moduleName, groupName = '', fromDate = '', toDate = '', studentId = '') => {
  const payload = { module: moduleName };
  if (groupName) payload.group_name = groupName;
  if (fromDate) payload.from_date = fromDate;
  if (toDate) payload.to_date = toDate;
  if (studentId) payload.student_id = studentId;
  const response = await client.post('/consultations/query', payload);
  const data = response.data ?? { data: [], columns: {} };
  // Si el backend reporta error explícito con HTTP 200, convertirlo a excepción
  if (data.status === 'error') {
    throw new Error(data.message || 'Error en el módulo de consulta');
  }
  return data;
}
```

---

→ **Continúa en PLAN8.md**
