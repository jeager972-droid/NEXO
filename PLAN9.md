# NEXO — Plan de Diagnóstico y Corrección Completa (Parte 9 de 10)

## CHECKLIST DE VERIFICACIÓN POST-FIX (Por rol de usuario)

Después de aplicar todas las correcciones, se debe verificar el comportamiento completo para cada rol de usuario usando los usuarios de prueba existentes en el sistema.

---

### VERIFICACIÓN ROL: DOCENTE

**Usuario de prueba:** `docente@nexo.edu`

#### Dashboard
- [ ] Al entrar, el dashboard muestra los grupos del docente con contador de presentes/ausentes/alertas/permisos
- [ ] Los contadores corresponden a estudiantes del grupo (no de toda la institución)
- [ ] Al hacer click en "Alertas (1)", el drawer muestra los alerts reales del grupo (no vacío)
- [ ] Al hacer click en "Presentes (N)", muestra la lista de estudiantes con entrada registrada hoy
- [ ] Al hacer click en "Ausentes (N)", muestra estudiantes sin registro biométrico de entrada hoy
- [ ] Si hay 0 alertas, la card dice "0" sin mostrar datos erróneos

#### Operación
- [ ] Botón "Citar acudiente" abre el drawer, se puede seleccionar estudiante, se envia → toast "Citación enviada" (no error rojo)
- [ ] Botón "Generar permiso" abre el drawer, se selecciona estudiante y motivo → toast "Permiso generado" (FASE 1 fix)
- [ ] Botón "SOS" abre drawer de emergencia → al enviar, el rector/coordinador recibe notificación interna
- [ ] Botón "Solicitud" abre drawer → al enviar, el destinatario recibe notificación
- [ ] Botón "Reportar incidente" → al enviar, coordinación recibe WhatsApp y notificación interna

#### Consulta
- [ ] Página /consulta carga sin error (no "Error de carga" / pantalla blanca)
- [ ] Módulo "Llegadas Tarde": selector de grupo muestra SOLO los grupos del docente
- [ ] Ejecutar consulta de "Llegadas Tarde" → muestra tabla con datos o mensaje "Sin registros"
- [ ] Módulo "Inasistencias" → idem
- [ ] Módulo "Citaciones" → muestra historial de citaciones del docente
- [ ] Las fechas en las tablas aparecen como "15 Jun 2024, 2:30 p. m." (no como ISO timestamp)
- [ ] Columnas como `incident_type` aparecen como "Tipo de incidente" (no como snake_case)

#### Notificaciones
- [ ] La campana muestra punto verde cuando hay notificaciones sin ver
- [ ] Al abrir /notificaciones, el listado muestra las notificaciones
- [ ] Si hay notificación tipo "Reagendamiento", al hacer "Ver detalles" muestra el motivo del acudiente
- [ ] Después de ver las notificaciones, el punto verde desaparece
- [ ] Si llega una nueva citación confirmada (respuesta del acudiente "1"), aparece notificación "Citación confirmada" con nombre del estudiante

---

### VERIFICACIÓN ROL: COORDINADOR

**Usuario de prueba:** `coordinador@nexo.edu`

#### Dashboard
- [ ] Dashboard muestra métricas globales de la institución (no solo de un grupo)
- [ ] Card "Alertas" muestra el total correcto (SOS + incidents)
- [ ] Al hacer click en "Alertas", el drawer muestra tanto SOS activos como incidents reales
- [ ] No muestra "0 registros" si el contador dice 1

#### Operación
- [ ] "Autorizar salida" → al enviar → toast de éxito (no error rojo) y acudiente recibe WhatsApp (FASE 1 fix)
- [ ] "Salida pedagógica" → al enviar con grupo seleccionado → toast de éxito, acudientes del grupo reciben WhatsApp (FASE 1 fix)
- [ ] "Cambio horario" → al enviar → toast de éxito
- [ ] "Citar acudiente" → funciona correctamente

#### Consulta
- [ ] /consulta carga sin pantalla en blanco
- [ ] "Seguimiento Estudiantil" → muestra tabla de estudiantes en seguimiento (o mensaje "Sin datos")
- [ ] "Alertas" → muestra incidents disciplinarios y de tipo riesgo
- [ ] "Vulneraciones" → muestra incidents de tipo incidente/daño/SOS
- [ ] "Spam Biométrico" → muestra eventos biométricos fallidos (si existen)
- [ ] "Permisos Emitidos" → muestra los permisos autorizados (si existen)
- [ ] "Salidas del colegio permitidas" → muestra autorizaciones de salida
- [ ] "Estudiantes" → lista paginada de estudiantes activos
- [ ] "Acudientes" → lista de acudientes con su teléfono
- [ ] Todas las fechas en formato 12h con zona horaria de Bogotá

#### Notificaciones
- [ ] Recibe notificaciones de SOS enviados por docentes
- [ ] Recibe notificaciones de incidentes reportados
- [ ] El punto verde en la campana se actualiza en ~30 segundos cuando llega nueva notificación

---

### VERIFICACIÓN ROL: SECRETARIA

**Usuario de prueba:** `secretaria@nexo.edu`

#### Dashboard
- [ ] Dashboard estándar con métricas del día

#### Operación
- [ ] Solo tiene acceso a "SOS" y "Solicitud"

#### Consulta
- [ ] /consulta carga correctamente
- [ ] "Estudiantes" → tabla paginada con búsqueda funcional
- [ ] "Grupos" → lista de grupos académicos
- [ ] "Profesores" → lista de docentes activos
- [ ] "Acudientes" → lista de acudientes
- [ ] "Matrículas" → lista de estudiantes con fecha de matrícula
- [ ] "Personal Institucional" → lista de todos los usuarios del sistema
- [ ] "Auxiliares" → solo auxiliares
- [ ] "Portería" → solo porteros
- [ ] "Mensajes Enviados" → historial de WhatsApps enviados (admins ven todos)
- [ ] Módulos sin datos muestran "Sin registros" (no error)

#### Enrolamiento
- [ ] /enrolamiento carga correctamente
- [ ] Botón "Nuevo Estudiante" abre el drawer
- [ ] Paso 1: llenar nombres y apellidos → "Siguiente" funciona
- [ ] Paso 2: llenar documento → "Siguiente" funciona
- [ ] Paso 3: seleccionar grado → resumen visible → botón "Guardar Registro" guarda y avanza
- [ ] Paso 4: si no hay lector biométrico → mensaje de error "No se encontró conexión con el lector" con botón "Cerrar — Completar biometría después"
- [ ] Paso 4: si hay lector → interfaz de captura de huella
- [ ] Después de guardar, el estudiante aparece en la lista de enrolamiento

---

### VERIFICACIÓN ROL: PORTERO

**Usuario de prueba:** `portero@nexo.edu`

#### Sidebar
- [ ] NO aparece "Consulta" en el menú lateral
- [ ] Solo aparece: Inicio, Operación, Notificaciones
- [ ] Si escribe `/consulta` en el URL directamente, es redirigido a /

#### Operación
- [ ] Solo tiene acceso a: "SOS", "Reportar daño", "Mandar solicitud"
- [ ] NO ve: "Citar acudiente", "Autorizar salida", "Generar permiso", etc.
- [ ] "Reportar daño" → tiene campos "Ubicación" y "Descripción"

---

### VERIFICACIÓN ROL: AUXILIAR

**Usuario de prueba:** `auxiliar@nexo.edu`

- [ ] NO aparece "Consulta" en el menú lateral
- [ ] Mismo comportamiento que Portero para la ruta directa
- [ ] En operación: "SOS", "Reportar daño", "Mandar solicitud"

---

### VERIFICACIÓN ROL: PSICORIENTADOR

**Usuario de prueba:** `psicorientador@nexo.edu`

#### Dashboard
- [ ] Dashboard similar a secretaria/coordinador (NO el de docente con grupos)
- [ ] Ve métricas globales de asistencia

#### Notificaciones
- [ ] Cuando el coordinador envía una solicitud de seguimiento al psicorientador, la notificación incluye botón "Empezar seguimiento"
- [ ] Al hacer click, navega a /seguimiento con el student_id del estudiante

#### Consulta
- [ ] Módulo "Análisis de Riesgo" → muestra datos de riesgo
- [ ] Módulo "Mis Clases → Llegadas Tarde" → funciona con selector de grupo

#### Seguimiento
- [ ] /seguimiento carga correctamente
- [ ] Puede crear y gestionar seguimientos estudiantiles

---

### VERIFICACIÓN ROL: RECTOR

**Usuario de prueba:** `rector@nexo.edu`

#### Auditoría
- [ ] Subsección "Asistencia → Llegadas tarde" → tabla sin duplicados
- [ ] Subsección "Permisos → Historial permisos" → tabla con datos (no error 500)
- [ ] Subsección "Disciplina → Spam biométrico" → timestamps en formato 12h
- [ ] Subsección "Salidas → Salidas clase" → registros SIN duplicados (fix AND sga.active=TRUE)
- [ ] Todas las subsecciones que antes daban 500 ahora retornan datos o "Sin registros"
- [ ] Ninguna celda muestra valores tipo `UNAUTHORIZED_ABSENCE` (debe decir "Inasistencia")
- [ ] Ninguna celda muestra `2024-06-15T12:30:00.000Z` (debe mostrar "15 Jun 2024, 12:30 p. m.")

#### Operación
- [ ] "Autorizar salida" → funciona correctamente con echo de éxito (FASE 1)
- [ ] "Salida pedagógica" → funciona con echo de éxito (FASE 1)

---

## CRITERIOS DE ÉXITO DEL PROYECTO

El sistema NEXO se considera **estable y listo para uso en producción real** cuando se cumplen todos los siguientes criterios:

### Criterios de Operaciones
1. Todos los comandos de operación institucional (10 comandos) retornan éxito visible al usuario cuando el backend los procesa exitosamente
2. El campo de motivo/descripción llega correctamente al backend para todos los comandos
3. Las notificaciones de confirmación/reagendamiento de citación llegan al professor emisor

### Criterios de Consulta
4. Todos los roles con acceso a /consulta pueden abrir al menos un módulo sin error
5. Ningún módulo muestra timestamps crudos (ISO 8601) ni enums sin traducir
6. Ningún módulo muestra nombres de columnas en snake_case sin humanizar

### Criterios de Roles
7. PORTERO y AUXILIAR no tienen acceso a /consulta
8. PSICORIENTADOR tiene dashboard apropiado (no el de docente)
9. PSICORIENTADOR recibe notificaciones de seguimiento con botón de acción

### Criterios de Notificaciones
10. El punto verde en la campana se actualiza en máximo 30 segundos cuando llega una notificación nueva
11. Las notificaciones de reagendamiento incluyen el motivo enviado por el acudiente

### Criterios de Auditoría (Rector)
12. La sección "Historial permisos" no retorna 500
13. Los logs de salida de clase no muestran duplicados
14. Todos los enums en las tablas de auditoría están humanizados

### Criterios de Enrolamiento
15. El proceso de enrolamiento tiene feedback visible en caso de error
16. El paso 4 verifica si hay lector biométrico y muestra el error apropiado si no hay

---

→ **Continúa en PLAN10.md (Índice maestro y referencias cruzadas)**
