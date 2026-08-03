# Documentación de cambios — NEXO WebApp

## Sesión del 3 de agosto de 2026

---

## Auditoría y corrección Backend ↔ Frontend

### Problema: "Error de consulta — Módulo no soportado"

**Causa raíz:** Varios submódulos del frontend enviaban slugs que no existían en el backend, o usaban el slug genérico `incidents` para tipos de eventos diferentes.

### Correcciones frontend

**`src/pages/Consultation.jsx`:**
- Agregado `'Permisos': 'active_permissions'` a `MODULE_SLUGS` (faltaba).
- Agregado `'Seguimientos': 'student_tracking_completed'` a `MODULE_SLUGS` (faltaba).
- Cambiados slugs de eventos críticos de `incidents` a slugs específicos:
  - `'Evasiones Internas': 'evasions'`
  - `'SOS Emitidos': 'sos_emitted'`
  - `'Daños Reportados': 'damages_reported'`
  - `'Situaciones Críticas': 'critical_situations'`

### Correcciones backend — `backend/api/routes/consultations.php`

- **Filtro por grado:** Agregado `$gradeFilter` a las consultas `late_arrivals`, `absences`, `active_permissions` (ya existía el helper pero no se usaba en estas queries).
- **Nuevos cases en el switch:**
  - `justified_absences` — Consulta `attendance_incidents` con tipos `JUSTIFIED_ABSENCE`, `INASISTENCIA_JUSTIFICADA`.
  - `evasions` — Consulta `attendance_incidents` con tipos `EVASION`, `EVASION_INTERNA`, `CLASSROOM_EVASION`.
  - `sos_emitted` — Consulta `sos_alerts` con join a `users` para mostrar emisor.
  - `damages_reported` — Consulta `user_commands` donde `command_type = 'DAÑO'`, extrae payload JSON.
  - `critical_situations` — Consulta `user_commands` donde `command_type = 'SITUACION_CRITICA'`, extrae payload JSON.

### Correcciones backend — `backend/api/routes/operations.php`

- **Nueva operación `situacion_critica`:**
  - Agregado `'/operations/situacion_critica' => 'situacion_critica'` al `$pathMap`.
  - Agregado `case 'situacion_critica'` en el switch que:
    - Inserta un registro en `attendance_incidents` con tipo `SITUACION_CRITICA`.
    - Notifica a RECTOR y COORDINATOR vía WhatsApp (Twilio).
    - Crea notificaciones in-app para RECTOR y COORDINATOR.
    - Registra el comando en `user_commands`.

### Correcciones backend — Permisos RBAC

**`backend/api/sql/nexo_seed.sql`:**
- Agregado permiso `operations.situacion_critica` a la tabla `permissions`.
- Agregado `operations.situacion_critica` a los roles: TEACHER, SECRETARY, COUNSELOR.
- (RECTOR y COORDINATOR reciben todos los permisos automáticamente.)

**`backend/api/sql/nexo_full_migration.sql`:**
- Agregado permiso `operations.situacion_critica` a la tabla `permissions`.
- Agregado `assign_permission_to_role` para RECTOR, COORDINATOR, TEACHER, SECRETARY, COUNSELOR.

**`backend/api/tests/integration_test.php`:**
- Agregado `operations.situacion_critica` a la lista de permisos esperados.

### Verificación de integridad

- **Build frontend:** `npx vite build` — sin errores.
- **Tests frontend:** 29 archivos, 339 tests — todos pasan.
- **Sintaxis PHP:** `php -l` en `consultations.php` y `operations.php` — sin errores.
- **Auditoría slugs:** Todos los slugs del frontend (`MODULE_SLUGS`) tienen un `case` correspondiente en el backend.

---

### 1. Botón "Situación Crítica" en Operaciones (todos los roles)

**Archivos modificados:**
- `src/config/roles.js` — Agregado comando `situacion_critica` al catálogo `OPERATION_COMMANDS` con icono `Siren`, disponible para todos los roles (`ALL_ROLES`). Import de `Siren` desde `lucide-react`.
- `src/pages/Operation.jsx` — Agregado `situacion_critica` al `COMMANDS_CATALOG` con `tone: 'danger'` (mismo color que SOS), campos `['location', 'message']`. Agregado caso en el `switch` de `handleSubmit` que llama `operationsApi.execute('situacion_critica', payload, '/operations/situacion_critica')`. Import de `Siren`.
- `src/pages/Notifications.jsx` — Agregado caso `situacion_critica` en `humanizeMessage` que genera: "Se reportó una situación crítica por [reporter]. Ubicación: [location]. Detalle: [reason]."

**Comportamiento:**
- El botón aparece en la página de Operaciones para todos los roles.
- Tiene el mismo color rojo (`danger`) que el botón SOS.
- Incluye campos de ubicación y mensaje/detalles.
- El backend decide a quién notificar (rector y coordinador, o según el rol del emisor).
- La notificación se humaniza con ubicación y detalles.

---

### 2. Rector: nuevos módulos de consulta

**Archivo modificado:** `src/pages/Consultation.jsx`

**Módulos anteriores (eliminados):**
- Ejecutivo Institucional, Reportes Consolidados, Históricos, Auditoría, Mensajería, Exportaciones

**Módulos nuevos:**

#### Reportes de Asistencia (tone: accent)
- Inasistencias
- Inasistencias Justificadas
- Llegadas Tarde
- Salidas Pedagógicas
- Permisos

#### Reportes de Eventos Críticos (tone: danger)
- Seguimientos (casos resueltos, con botón "Ver detalles")
- Evasiones Internas
- SOS Emitidos
- Daños Reportados
- Situaciones Críticas
- Spam al Nodo

**Permisos:** El rector (`ROLES.RECTOR`) puede exportar (Excel, Word, PDF) en todos los submódulos.

---

### 3. Coordinador: mismos módulos que rector

**Archivo modificado:** `src/pages/Consultation.jsx`

**Módulos anteriores (eliminados):**
- Incidentes, Permisos, Auditoría, Disciplina

**Módulos nuevos:** Idénticos a los del rector (Reportes de Asistencia + Reportes de Eventos Críticos).

**Diferencia:** El coordinador (`ROLES.COORDINADOR`) **no** puede exportar (`canExport = user?.role === ROLES.RECTOR`).

---

### 4. Secretaria: cambios en Gestión Estudiantil

**Archivo modificado:** `src/pages/Consultation.jsx`

- **Agregado:** Submódulo "Grados" (consulta de grados sexto a once, sin letra).
- **Agregado:** Submódulo "Grupos" ya existente, ahora separado de grados.
- **Eliminado:** Submódulo "Cambios Registro".
- **Eliminados anteriormente:** Módulos Personal, Mensajería, Históricos, Control de Acceso.

**Archivo modificado:** `src/pages/Enrollment.jsx`

- **Paso 3 del alta (Grado y grupo):** Ahora el campo "Grado" usa opciones fijas (Sexto, Séptimo, Octavo, Noveno, Décimo, Once) sin letra. El campo "Grupo" se filtra según el grado seleccionado.
- **Vista de búsqueda:** Agregado filtro por grado junto al filtro por grupo existente. La grilla pasó de 2 a 3 columnas para acomodar el nuevo filtro.
- **API:** `studentsApi.getAll` ahora acepta parámetro `grade` para filtrar por grado.

**Archivo modificado:** `src/api/students.js`
- Agregado parámetro `grade` a `getAll()` que se envía como query param al backend.

---

### 5. Inasistencias Justificadas + Filtro por Grado

**Archivo modificado:** `src/pages/Consultation.jsx`

- **Profesor (Mis Clases):** Agregado submódulo "Inasistencias Justificadas" con icono `ShieldCheck`.
- **Coordinador y Rector (Reportes de Asistencia):** Agregado submódulo "Inasistencias Justificadas".
- Agregado `TEACHER_MODULES` incluye "Inasistencias Justificadas".
- Agregado `MODULE_SLUGS['Inasistencias Justificadas'] = 'justified_absences'`.

**Filtro por grado en consultas:**

**Archivos modificados:**
- `src/pages/Consultation.jsx` — Agregado estado `selectedGrade` y `setSelectedGrade`. Se pasa a `ConsultationDrawer`. Se resetea al cambiar de submódulo. Se envía a `consultationsApi.queryModule`.
- `src/pages/ConsultationDrawer.jsx` — Agregado `GRADO_OPTIONS` (sexto a once). `TeacherQueryPanel` y `AdminFilterPanel` ahora reciben `selectedGrade`/`setSelectedGrade` y renderizan un `SearchableSelect` para grado antes del filtro de grupo.
- `src/api/consultations.js` — `queryModule` ahora acepta parámetro `grade` que se envía en el payload al backend.

---

### 6. Botón "Ver detalles" en consultas

**Archivo modificado:** `src/pages/ConsultationDrawer.jsx`

- Agregado constante `DETAIL_MODULES` que lista todos los submódulos que pueden tener detalles:
  - Seguimiento Estudiantil, Alertas, Seguimientos completados, Seguimientos
  - Permisos, Permisos Emitidos, Permisos de Salida, Permisos Internos
  - SOS Emitidos, Evasiones Internas, Situaciones Críticas, Daños Reportados
  - Spam al Nodo, Estudiantes con Permiso

- En ambas ramas de tabla (admin y no-filtrada), se agrega columna "Acción" con botón "Ver detalles" (variant `quiet`, size `sm`) cuando el módulo está en `DETAIL_MODULES` y hay `student_id` en la fila.
- El botón abre `TrackingModal` con los datos del estudiante y metadatos del evento.

---

### 7. Nuevos slugs de módulos

**Archivo modificado:** `src/pages/Consultation.jsx`

Agregados a `MODULE_SLUGS`:
- `'Inasistencias Justificadas': 'justified_absences'`
- `'Evasiones Internas': 'incidents'`
- `'SOS Emitidos': 'incidents'`
- `'Daños Reportados': 'incidents'`
- `'Situaciones Críticas': 'incidents'`
- `'Spam al Nodo': 'biometric_spam'`
- `'Permisos de Salida': 'school_exits'`
- `'Permisos Internos': 'active_permissions'`
- `'Grados': 'all_groups'`

---

### Resumen de archivos modificados

| Archivo | Cambios |
|---------|---------|
| `src/config/roles.js` | Comando `situacion_critica` + import `Siren` |
| `src/pages/Operation.jsx` | Catálogo + submit handler + import `Siren` |
| `src/pages/Notifications.jsx` | Humanización de notificación `situacion_critica` |
| `src/pages/Consultation.jsx` | rbacModules rector/coordinador/secretaria/docente, nuevos slugs, estado `selectedGrade` |
| `src/pages/ConsultationDrawer.jsx` | `GRADO_OPTIONS`, `DETAIL_MODULES`, filtros de grado, botones "Ver detalles" |
| `src/api/consultations.js` | Parámetro `grade` en `queryModule` |
| `src/api/students.js` | Parámetro `grade` en `getAll` |
| `src/pages/Enrollment.jsx` | `GRADO_OPTIONS`, filtro por grado en alta y búsqueda, filtrado de grupos por grado |
