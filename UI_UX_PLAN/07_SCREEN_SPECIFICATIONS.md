# 07. Especificaciones de pantallas

**Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md). Catálogo completo de páginas y superficies transitorias del producto objetivo.

## 1. Contrato de pantalla

Cada `SCR-*` debe implementar: pregunta/objetivo, usuarios, precondición, jerarquía, primaria/secundarias, componentes, datos/permisos, estados, responsive, teclado/touch/lector, motion, transición, telemetría y aceptación. Reglas globales: [02_DESIGN_PRINCIPLES.md](./02_DESIGN_PRINCIPLES.md), componentes: [03_DESIGN_SYSTEM.md](./03_DESIGN_SYSTEM.md).

## 2. Catálogo maestro

| ID | Pantalla/superficie | Pregunta | Roles | Flujo/capacidad | Estado |
|---|---|---|---|---|---|
| SCR-AUTH-01 | Login | ¿Cómo entro a mi jornada? | todos | FLOW-AUTH-01, CAP-AUTH-01 | N2 |
| SCR-AUTH-02 | 2FA | ¿Cómo confirmo que soy yo? | todos | FLOW-AUTH-01, CAP-AUTH-02 | N2 |
| SCR-AUTH-03 | Recuperación | ¿Cómo recupero acceso? | todos | FLOW-AUTH-03 | N4 |
| SCR-HOME-01 | Inicio contextual | ¿Qué requiere atención? | todos | FLOW-HOME-01, CAP-HOME-01, CAP-HOME-02 | N2 |
| SCR-HOME-02 | Hoy en el colegio | ¿Qué compone mi jornada? | todos | CAP-HOME-03 | N4 |
| SCR-HOME-03 | Detalle métrica/situación | ¿Qué significa y qué hago? | según dato | FLOW-HOME-01, CAP-HOME-01 | N2 parcial |
| SCR-OPS-01 | Operaciones | ¿Qué acción quiero realizar? | todos filtrado | FLOW-OPS-01, FLOW-OPS-02, FLOW-OPS-03, FLOW-OPS-04, FLOW-OPS-05, FLOW-OPS-06, FLOW-OPS-07, FLOW-OPS-08, FLOW-OPS-09, FLOW-OPS-10, FLOW-OPS-11, FLOW-OPS-12, FLOW-OPS-BASE, CAP-OPS-01, CAP-OPS-02, CAP-OPS-03, CAP-OPS-04, CAP-OPS-05 | N2 |
| SCR-OPS-02 | Formulario operación | ¿Qué falta para ejecutar? | según acción | FLOW-OPS-BASE, CAP-OPS-01, CAP-OPS-02, CAP-OPS-03, CAP-OPS-04 | N2 |
| SCR-OPS-03 | Resultado/entrega | ¿Qué ocurrió después de confirmar? | actor | FLOW-MSG-01, CAP-OPS-01, CAP-OPS-02, CAP-OPS-03, CAP-OPS-04, CAP-MSG-01 | N2 |
| SCR-NOT-01 | Conversación NEXO | ¿Qué ocurrió mientras trabajaba? | todos | FLOW-MSG-01, FLOW-MSG-02, CAP-MSG-01, CAP-MSG-02 | N2 |
| SCR-NOT-02 | Detalle notificación | ¿Por qué importa y qué hago? | autorizado | FLOW-MSG-02, CAP-MSG-02 | N3/N4 UI |
| SCR-CASE-01 | Casos Activos | ¿Quién requiere seguimiento? | rector, coord., psico | FLOW-CASE-01, FLOW-CASE-02, FLOW-CASE-03, CAP-CASE-01 | N2 |
| SCR-CASE-02 | Caso | ¿Qué cambió y qué acción corresponde? | autorizados | FLOW-CASE-01, FLOW-CASE-02, CAP-CASE-01 | N2 |
| SCR-CASE-03 | Nota/evolución/cierre | ¿Cómo registro la evolución? | psico/gestores | FLOW-CASE-02, FLOW-CASE-03, CAP-CASE-01 | N2 |
| SCR-QRY-01 | Consultas | ¿Qué información busco? | rector, coord., docente, secret., psico | FLOW-QRY-01, CAP-QRY-01 | N2 con brechas |
| SCR-QRY-02 | Resultados/detalle | ¿Qué muestran los registros? | autorizado | FLOW-QRY-01, CAP-QRY-01 | N2 con brechas |
| SCR-AUD-01 | Auditoría | ¿Qué ocurrió y quién actuó? | rector | FLOW-AUD-01, CAP-AUD-01 | N2 |
| SCR-AUD-02 | Integridad/evento | ¿Es verificable este historial? | rector | FLOW-AUD-02, CAP-AUD-01 | N2 |
| SCR-REP-01 | Informes | ¿Qué necesito compartir? | rector | FLOW-REP-01, CAP-REP-01 | N2 |
| SCR-REP-02 | Preview/exportación | ¿Qué contiene el archivo? | rector | FLOW-REP-01, CAP-REP-01 | N2 |
| SCR-ENR-01 | Estudiantes/enrolamiento | ¿Quién falta por habilitar? | secretaría | FLOW-ENR-01, FLOW-ENR-02, FLOW-ENR-03, CAP-ENR-01 | N2/parcial |
| SCR-ENR-02 | Alta/biometría | ¿Qué falta para completar el registro? | secretaría | FLOW-ENR-01, FLOW-ENR-02, CAP-ENR-01 | parcial |
| SCR-PRO-01 | Perfil | ¿Qué dato de mi cuenta gestiono? | todos | FLOW-PRO-01, FLOW-AUTH-02, CAP-PRO-01 | N2 |
| SCR-PRO-02 | Seguridad/verificación | ¿Cómo protejo o verifico mi cuenta? | todos | FLOW-PRO-01, CAP-PRO-01 | N2 |
| SCR-PRO-03 | Apariencia/instalación/sync | ¿Cómo adapto NEXO a este dispositivo? | todos | FLOW-PRO-01, FLOW-PWA-01, FLOW-PWA-02, FLOW-NATIVE-01, CAP-PRO-01, CAP-PWA-01, CAP-NATIVE-01 | N2/parcial |
| SCR-SYS-01 | Sin permiso | ¿Cómo vuelvo a una zona válida? | todos | transversal | N2 |
| SCR-SYS-02 | Offline/no disponible | ¿Qué puedo hacer sin conexión? | todos | FLOW-PWA-02, CAP-PWA-01 | parcial |
| SCR-SYS-03 | Conflicto/sync | ¿Cómo resuelvo un cambio concurrente? | actor | FLOW-PWA-02, CAP-PWA-01 | N4 UI |
## 3. Especificaciones por familia

### SCR-AUTH-01 Login
- **Precondición/datos:** sin sesión; email, password; endpoint auth.
- **Jerarquía:** marca, título “Inicia tu jornada”, campos, recuperar, primaria “Entrar”.
- **Estados:** validación, enviando, credenciales, red, bloqueo, sesión expirada.
- **Responsive:** panel único 320–520 px; no split hero en móvil ni animación bloqueante.
- **Accesibilidad:** autocomplete, mostrar contraseña, Enter, foco al error, anuncio no intrusivo.
- **Motion/transición:** fundido ≤150 ms hacia shell; reduced motion instantáneo.
- **Telemetría:** `auth_attempt`, resultado categórico sin credenciales, duración.
- **Aceptación:** 95% éxito de usuarios con credenciales; password manager funciona.

### SCR-AUTH-02 2FA
Destino enmascarado, seis dígitos en un solo campo semántico o grupo accesible, pegar permitido, reenviar y cambiar método si existe. Primaria “Verificar”. Error no borra código hasta corrección. Objetivo WCAG 3.3.8.

### SCR-HOME-01 Inicio contextual
- **Contenido:** topbar + `CMP-100`; línea “Hoy”; hasta 5 prioridades; eventos recientes.
- **Variantes:** rector/coordinador institución; docente métricas de grupo; secretaría tareas; psico casos; servicio órdenes.
- **Primaria:** la prioridad número uno, no una CTA genérica.
- **Datos:** stats/events/tareas/casos; actualización visible.
- **Estados:** skeleton; día tranquilo; parcial; offline cache; sin grupo.
- **Responsive:** wide puede usar 2 columnas 2:1; compact secuencia prioridad → métricas → eventos.
- **Telemetría:** prioridad abierta, tiempo hasta primera acción, cambio grupo.
- **Aceptación:** propósito y prioridad reconocidos ≤5 s.

### SCR-HOME-02 Hoy en el colegio
Barra/resumen colapsado; al abrir, agenda, clases, solicitudes y anomalías con una sola siguiente acción. No banners múltiples ni chat abierto. Cierra con Escape y devuelve foco.

### SCR-HOME-03 Detalle
Título factual, periodo, lista contextual y explicación. Primaria depende de situación. El usuario vuelve al mismo estado de Inicio. No ficha integral del estudiante.

### SCR-OPS-01 Operaciones
- **Contenido:** acciones autorizadas agrupadas “Frecuentes”, “Comunicar”, “Seguridad/soporte”; máximo 5 prioritarias visibles.
- **Componente:** `CMP-101` action cards; búsqueda/agrupación si crece.
- **Primaria:** ninguna global; cada card es destino, no dos énfasis dentro.
- **Estados:** catálogo loading, sin permisos, red; SOS siempre visible si autorizado.
- **Aceptación:** acción frecuente encontrada ≤10 s; no aparecen acciones prohibidas.

### SCR-OPS-02 Flujo de operación
Encabezado con acción y contexto, progreso textual, ≤5 campos por paso, resumen antes de enviar. Sticky action en compact sin tapar contenido. Cancelar, volver y conservar borrador. `CMP-107` solo en consecuencia crítica. Telemetría por paso/error/abandono sin PII.

### SCR-OPS-03 Resultado
Icono + estado textual, frase de alcance, ID/hora, entrega WhatsApp y siguientes acciones “Ver estado”/“Volver”. Si timeout: “Aún no sabemos si se registró”, consulta idempotente; no botón duplicador.

### SCR-NOT-01 Conversación NEXO
- **Contenido:** lista cronológica `CMP-104`, no composer; filtros Todos, Requiere acción, Casos, Operaciones.
- **Fila:** mensaje humano, tiempo, estado y CTA.
- **Estados:** no novedades, sin coincidencias, error parcial, offline.
- **Responsive:** lista + detalle en wide; navegación a detalle en compact.
- **Aceptación:** usuario diferencia información de acción ≥90%.

### SCR-NOT-02 Detalle
Evento, evidencia, objeto mínimo, estado WhatsApp/respuesta y acción. Información psicológica sensible solo tras permiso. Retorno exacto.

### SCR-CASE-01 Casos Activos
Lista priorizada por backend, filtros estado/responsable/grupo, resumen textual. No ordenar riesgo en frontend salvo valor recibido. Vacío “No hay casos activos” con explicación. Wide lista+preview; compact lista→página.

### SCR-CASE-02 Caso
`CMP-102`: estudiante contextual, nivel y tendencia, resumen NEXO, indicadores, timeline y responsable. Primaria según estado: iniciar/aceptar/registrar evolución. Explicación de riesgo y fecha. Sin datos personales irrelevantes.

### SCR-CASE-03 Nota/evolución/cierre
Formulario enfocado. Nota sensible indica audiencia. Guardado preserva texto; cierre usa confirmación crítica. Timeline anuncia inserción. Cero edición silenciosa de registros auditados.

### SCR-QRY-01 Consultas
Catálogo filtrado por rol y búsqueda. Título/pregunta, módulos por tarea, grupo/periodo persistente. Rector debe incluir ejecutivo y consolidados en objetivo. Módulos técnicamente vacíos se muestran “No disponible” solo en entornos donde sea honesto, no como vacío de datos.

### SCR-QRY-02 Resultados
Resumen superior, filtros activos, conteo/actualización, lista por defecto; tabla si prueba de comparación. Drawer contextual wide, página compact. Loading no borra resultados previos. Error preserva filtros. Exportar solo autorizado.

### SCR-AUD-01 Auditoría
Dominios, periodo, actor/acción y resultados. Tabla semántica wide; lista priorizada compact. Integridad es acción secundaria visible. Paginación/cursor. No exponer payload técnico por defecto.

### SCR-AUD-02 Integridad/evento
Resumen verificable/no verificable/ruptura, alcance, fecha y método; detalle técnico bajo disclosure. Ruptura ofrece escalar/copiar referencia, no reparar. Evento muestra actor, acción, objeto y hash solo a quien corresponda.

### SCR-REP-01 Informes
Tipos de informe, periodo y filtros. Primaria “Previsualizar”, no descargar a ciegas. Historial de exportaciones solo si backend existe; si no, planificado.

### SCR-REP-02 Preview/exportación
Muestra columnas, muestra de filas, cantidad y PII incluida/excluida. “Exportar CSV” tras validación. Progreso real y descarga recuperable. Compact no intenta mostrar tabla completa; presenta esquema/resumen.

### SCR-ENR-01 Enrolamiento
Búsqueda primero para evitar duplicados, lista con estado activo/biometría, primaria “Registrar estudiante”. Scroll infinito debe anunciar carga y ofrecer alternativa paginada. Activar/inactivar contextual, no trash icon.

### SCR-ENR-02 Alta/biometría
Stepper 4 etapas: básicos, identificación, grupo, biometría opcional. Guardar estudiante antes de hardware; biometría fallida queda pendiente. Confirmar consentimiento/política; calidad no se decide visualmente sin dato de hardware.

### SCR-PRO-01 Perfil
Secciones Cuenta, Contacto, Seguridad, Apariencia y Dispositivo. Una primaria por sección editada. Foto opcional con límites. Verificación visible. No mezclar borradores de varias secciones.

### SCR-PRO-02 Seguridad
Cambiar contraseña, métodos verificados y sesiones si soporte. Mensajes claros y autenticación accesible. Acciones destructivas no se agrupan con edición cotidiana.

### SCR-PRO-03 Apariencia/dispositivo
Tema Sistema/Claro/Oscuro, contraste/reduced motion respetando OS, instalación contextual, versión y `CMP-108`. No pedir permisos nativos antes de una acción que los necesite.

### SCR-SYS-01/02/03 Sistema
- **Sin permiso:** título, explicación mínima, “Volver a Inicio”; no revela dato.
- **Offline:** estado de red, última actualización, funciones disponibles/no disponibles y reintento.
- **Conflicto:** versión actual, cambio intentado, opciones seguras recargar/copiar; nunca “forzar” sin permiso.

## 4. Matriz pantalla × componente × endpoint

| Pantallas | Componentes clave | Cliente/ruta técnica |
|---|---|---|
| AUTH | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-036, CMP-037, CMP-039, CMP-040, CMP-041 | auth API `/auth/*` |
| HOME | CMP-020, CMP-021, CMP-027, CMP-100, CMP-011, CMP-023, CMP-026, CMP-031, CMP-039, CMP-041 | dashboard `/dashboard/stats`, `/events`, detail |
| OPS | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-009, CMP-010, CMP-020, CMP-036, CMP-039, CMP-040, CMP-041, CMP-101, CMP-105, CMP-107 | operations `/operations/*` o execute |
| NOT | CMP-021, CMP-025, CMP-104, CMP-105, CMP-011, CMP-034, CMP-035, CMP-039, CMP-041 | notifications, webhook inbound/status |
| CASE | CMP-001, CMP-021, CMP-024, CMP-102, CMP-103, CMP-106, CMP-036, CMP-039, CMP-041, CMP-107 | tracking, behavior/risk |
| QRY | CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-009, CMP-010, CMP-011, CMP-021, CMP-022, CMP-032, CMP-034, CMP-035, CMP-039, CMP-041 | `/consultations/query`, students/groups |
| AUD | CMP-004, CMP-009, CMP-021, CMP-022, CMP-024, CMP-034, CMP-035, CMP-039, CMP-041 | `/audit/*`, `/audit/integrity` |
| REP | CMP-001, CMP-007, CMP-009, CMP-022, CMP-026, CMP-036, CMP-039, CMP-040, CMP-041 | reports/preview/export |
| ENR | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-011, CMP-021, CMP-106, CMP-036, CMP-039, CMP-040, CMP-041, CMP-107 | students/groups + edge biometría |
| PRO | CMP-001, CMP-002, CMP-003, CMP-004, CMP-005, CMP-006, CMP-007, CMP-008, CMP-108, CMP-036, CMP-039, CMP-040, CMP-041 | `/users/me/*`, PWA/native adapters |
| Transversal | CMP-011, CMP-023, CMP-026, CMP-030, CMP-031, CMP-032, CMP-033, CMP-034, CMP-035, CMP-037, CMP-038, CMP-040 | shell, layout y patrones compartidos entre múltiples familias |
## 5. Telemetría ética

Registrar ID de pantalla/flujo, resultado, duración, error categórico, breakpoint/capacidad y uso de ayuda. Nunca nombre, documento, notas, mensaje, teléfono ni contenido consultado. Retención y acceso definidos institucionalmente. El personal institucional debe ser informado de que se mide uso (pantallas, flujos, tiempos) sin identificar contenido ni PII. Telemetría deshabilitada no bloquea tareas.

## 6. Criterios de aceptación

- Toda pantalla pertenece al menos a un flujo y capacidad.
- Cada una cubre todos los estados y retorno.
- Variantes por rol cambian contenido/acción, no duplican estructura.
- Teclado, touch y lector completan la misma tarea.
- Ninguna pantalla expone más datos de estudiante que la tarea exige.


## 7. Trazabilidad consolidada

Cada `SCR-*` del catálogo enlaza flujos, capacidades y componentes de forma explícita en las tablas anteriores. Las superficies `SCR-SYS-01/02/03` son transversales al shell y se activan desde cualquier flujo ante 403, offline o conflicto.
