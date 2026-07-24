# 06. Flujos de usuario

**Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md). Todos los flujos contemplan carga, vacío, error, permiso, offline, timeout, cancelación y recuperación.

## 1. Contrato narrativo

Cada `FLOW-*` registra: actor, disparador, precondición, contexto, pasos, decisión, feedback, alternativas, resultado, siguiente acción, capacidad, pantallas asociadas (`SCR-*`) y estado técnico. La confirmación es proporcional al daño. El frontend no calcula reglas.

## 2. Autenticación y sesión

### FLOW-AUTH-01 Iniciar jornada
**Pantallas asociadas:** SCR-AUTH-01, SCR-AUTH-02, SCR-HOME-01.
**Capacidad:** CAP-AUTH-01.
**Actores:** todos los roles institucionales. **Capacidad:** CAP-AUTH-01. **Estado:** Implementado (Pendiente validación), N2.

1. Usuario abre NEXO; se muestra shell/skeleton sin espera ornamental.
2. Si cookie válida, `/auth/me` restaura usuario, permisos y contexto seguro.
3. Si no, `SCR-AUTH-01` solicita email y contraseña con autocompletado.
4. Backend responde sesión o exige 2FA.
5. Si 2FA, `SCR-AUTH-02` explica destino enmascarado, permite pegar código y reenviar con temporizador.
6. Tras éxito, saludo breve y entrada automática a `SCR-HOME-01`.

**Errores:** credenciales sin identificar cuál campo; 2FA expirado permite reenvío; red conserva email; bloqueo/panic explica contactar institución; sesión expirada guarda destino y vuelve tras login. **Accesibilidad:** sin CAPTCHA cognitivo; password managers y pegado permitidos.

### FLOW-AUTH-02 Cerrar sesión
**Pantallas asociadas:** SCR-PRO-01.
**Capacidad:** CAP-AUTH-01.
Perfil/sidebar → “Cerrar sesión” → invalidar servidor → limpiar contexto/caché sensible → login. Si red falla, limpiar acceso local y marcar revocación pendiente sin exponer datos.

### FLOW-AUTH-03 Recuperar acceso
**Pantallas asociadas:** SCR-AUTH-03.
**Capacidad:** CAP-AUTH-01.
**Planificado, N4.** Email/teléfono institucional → método accesible → verificación → nueva credencial → revocar sesiones. Nunca preguntas de memoria ni transcripción bloqueada.

## 3. Inicio por rol

### FLOW-HOME-01 Revisar jornada
**Pantallas asociadas:** SCR-HOME-01, SCR-HOME-02, SCR-HOME-03.
**Capacidad:** CAP-HOME-01.
1. Entrar a Inicio.
2. NEXO carga “Hoy en {colegio}”, prioridades y eventos.
3. Docente reconoce/cambia grupo activo; otros roles ven alcance autorizado.
4. Usuario abre una prioridad o métrica.
5. Detalle explica situación y ofrece una acción primaria.
6. Volver restaura grupo, scroll y foco.

**Variantes:** rector/coordinador ven institución; docente grupo; secretaría eventos/tareas; psico casos; portero/auxiliar órdenes. **Offline:** mostrar caché fechada y bloquear decisiones que exigen actualidad. **Estado:** CAP-HOME-01 N2; “Hoy” y agenda N4.

## 4. Operaciones

### Plantilla FLOW-OPS-BASE
**Pantallas asociadas:** SCR-OPS-01, SCR-OPS-02, SCR-OPS-03.
**Capacidad:** CAP-OPS-01–05.
1. Disparador desde `SCR-OPS-01`, Inicio, caso o notificación.
2. Preseleccionar grupo/estudiante si el origen lo autoriza.
3. Pedir solo decisiones requeridas.
4. Validar inline y cargar opciones autorizadas.
5. Mostrar resumen: acción, sujeto, fecha, alcance y comunicaciones.
6. Confirmar con verbo específico.
7. Backend registra operación y devuelve estado real.
8. `SCR-OPS-03` muestra ID, auditoría, WhatsApp/sync y siguiente acción.

**Concurrencia:** si cambió permiso/estado, no sobrescribir; explicar y recargar. **Timeout:** estado “resultado desconocido”, consultar por idempotency key antes de reintentar. **Offline:** solo encolar acciones explícitamente seguras; SOS, salidas y comunicaciones críticas requieren conexión confirmada.

| Flujo | Actor objetivo | Decisiones mínimas | Resultado | Pantallas |
|---|---|---|---|---|
| FLOW-OPS-01 Citar acudiente | rector, coordinador, docente, psico | estudiante, fecha/hora, motivo | citación + estado WhatsApp | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-02 Inasistencia | docente/autorizado | grupo, estudiante, fecha/motivo | registro + comunicación según regla backend | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-03 Generar permiso | rector, coordinador, docente | estudiante, motivo, rango | permiso activo | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-04 Autorizar salida | rector, coordinador/portero según permiso | estudiante, motivo | autorización verificable | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-05 Salida pedagógica | rector/coordinador | grupo, fecha, motivo/alcance | operación masiva + mensajes | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-06 Cambio horario | rector/coordinador | grupo, hora, motivo | aviso masivo | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-07 SOS | todos | ubicación, mensaje opcional | alerta crítica auditada | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-08 Incidente | rector/docente/psico según fuente | estudiante, lugar, descripción, destinatarios | incidente/caso potencial | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-09 Daño | rector/portero/auxiliar | lugar, descripción | solicitud de atención | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-10 Solicitud | todos | destinatario por rol, mensaje | orden/solicitud | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-11 Solicitar caso | rector/coordinador | estudiante, razón | caso/solicitud a psico | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-12 Programar agenda | rector/coordinador | tipo, audiencia, fecha/hora, detalle | evento y recordatorios | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |

FLOW-OPS-01 a 11 tienen soporte técnico variable N2; agenda es N4. La matriz de roles actual no coincide completamente con visión; prevalece objetivo y se marca brecha.

## 5. WhatsApp y acudiente externo

### FLOW-MSG-01 Entregar comunicación
**Pantallas asociadas:** SCR-OPS-03, SCR-NOT-01.
**Capacidad:** CAP-MSG-01.
Operación confirmada → backend persiste y encola → worker envía → proveedor reporta enviado/entregado/fallido → NEXO actualiza `CMP-105`. Usuario institucional no espera bloqueado. Fallo ofrece reintento autorizado o canal alterno; nunca “entregado” sin callback.

### FLOW-MSG-02 Respuesta del acudiente
**Pantallas asociadas:** SCR-NOT-02.
**Capacidad:** CAP-MSG-02.
Acudiente recibe WhatsApp → responde según instrucción → webhook valida firma → respuesta se vincula a operación → usuario recibe mensaje NEXO → abre contexto y actúa. Respuesta ambigua se marca “requiere revisión”, no se interpreta con certeza. **Estado:** backend listo N3, UI objetivo pendiente.

El acudiente no inicia sesión, no navega WebApp y no recibe links con PII sin protección.

## 6. Casos Activos

### FLOW-CASE-01 Abrir/iniciar caso
**Pantallas asociadas:** SCR-CASE-01, SCR-CASE-02, SCR-CASE-03.
**Capacidad:** CAP-CASE-01.
Situación o solicitud → lista priorizada → abrir `SCR-CASE-02` → revisar resumen, riesgo e indicadores → elegir “Iniciar seguimiento” o psicológico → asignar responsable → confirmar → timeline auditado.

### FLOW-CASE-02 Gestionar caso
**Pantallas asociadas:** SCR-CASE-02, SCR-CASE-03.
**Capacidad:** CAP-CASE-01.
Psico acepta → registra nota/evolución → estado se actualiza → responsables reciben notificación. Nota conserva autor/fecha; fallo no pierde texto. Datos sensibles tienen visibilidad restringida y nunca aparecen en notificación preview.

### FLOW-CASE-03 Cerrar/reabrir
**Pantallas asociadas:** SCR-CASE-03.
**Capacidad:** CAP-CASE-01.
Revisar criterios de backend → resumen de cierre → confirmar alcance → caso cerrado sin borrar historia. Reapertura requiere motivo y permiso. **Estado:** implementación técnica N2, validación funcional pendiente.

## 7. Consultas

### FLOW-QRY-01 Consultar información
**Pantallas asociadas:** SCR-QRY-01, SCR-QRY-02.
**Capacidad:** CAP-QRY-01.
Elegir módulo permitido → filtros con grupo persistente → ejecutar o auto-cargar si barato → resultados resumidos → abrir detalle contextual → volver conserva filtros. Vacío distingue “sin registros” de “filtros sin coincidencias”. Exportar solo si rol/capacidad lo permite.

**Módulos:** docente (tardes, inasistencias, ausentes, fuera, permisos, citaciones); coordinador (incidentes, vulneraciones, alertas, seguimiento, permisos/salidas); secretaría (estudiantes, grupos, acudientes, matrículas, personal, mensajes, históricos, acceso); psico (riesgo, completados); rector (ejecutivo y consolidados). Rector es objetivo N4 en frontend actual aunque backend tenga datos. Algunos módulos de auditoría retornan vacío, por tanto `Backend parcial`.

## 8. Auditoría e informes

### FLOW-AUD-01 Investigar evento
**Pantallas asociadas:** SCR-AUD-01, SCR-AUD-02.
**Capacidad:** CAP-AUD-01.
Rector elige dominio/periodo → resultados → evento → actor, acción, tiempo, objeto y evidencia → volver. Sin permisos retorna 403 sin datos. Integridad se presenta como verificación, no como garantía absoluta.

### FLOW-AUD-02 Validar integridad
**Pantallas asociadas:** SCR-AUD-02.
**Capacidad:** CAP-AUD-01.
Rector inicia verificación → progreso → resultado íntegro/no verificable/ruptura → explicación y escalamiento. Nunca ofrecer “reparar” desde UI.

### FLOW-REP-01 Exportar informe
**Pantallas asociadas:** SCR-REP-01, SCR-REP-02.
**Capacidad:** CAP-REP-01.
Tipo → periodo/filtros → preview y cantidad → confirmar exportación → generar → descargar accesiblemente. Grandes procesos continúan en segundo plano. CSV respeta locale y permisos; no incluye PII innecesaria. Estado N2 pendiente prueba.

## 9. Enrolamiento

### FLOW-ENR-01 Registrar estudiante
**Pantallas asociadas:** SCR-ENR-01, SCR-ENR-02.
**Capacidad:** CAP-ENR-01.
Secretaría busca duplicado → datos básicos → identificación → grado/grupo → resumen → guardar estudiante → vincular biometría opcional/separada → resultado. Si lector no está disponible, estudiante queda “Biometría pendiente”, no falla el alta.

### FLOW-ENR-02 Capturar biometría
**Pantallas asociadas:** SCR-ENR-02.
**Capacidad:** CAP-ENR-01.
Seleccionar estudiante guardado → verificar dispositivo/consentimiento/política → capturas guiadas → calidad desde hardware/backend → vincular → auditar. Timeout permite reintentar sin duplicar. **Estado:** Backend parcial; flujo con hardware real no verificado.

### FLOW-ENR-03 Activar/inactivar
**Pantallas asociadas:** SCR-ENR-01.
**Capacidad:** CAP-ENR-01.
Abrir estudiante contextual → explicar consecuencia → confirmar → estado actualizado y sincronización edge. Nunca eliminación irreversible desde lista.

## 10. Perfil, PWA y sincronización

### FLOW-PRO-01 Actualizar perfil/seguridad
**Pantallas asociadas:** SCR-PRO-01, SCR-PRO-02.
**Capacidad:** CAP-PRO-01.
Abrir perfil → editar un grupo de datos → verificar por OTP si aplica → éxito. Cambio de contraseña requiere actual y revoca sesiones según política. Subida de foto muestra límites y alternativa sin foto.

### FLOW-PWA-01 Instalar
**Pantallas asociadas:** SCR-PRO-03.
**Capacidad:** CAP-PWA-01.
Promoción no intrusiva tras intención/uso → explicar beneficio → prompt nativo si disponible o instrucciones iOS → confirmar instalación. Si no soporta, NEXO sigue funcional en web. N2 técnico.

### FLOW-PWA-02 Trabajar con red degradada
**Pantallas asociadas:** SCR-PRO-03, SCR-SYS-02, SCR-SYS-03.
**Capacidad:** CAP-PWA-01.
Detectar conexión → mantener shell y caché fechada → indicar qué funciona → acciones seguras guardadas localmente muestran “pendiente” → al volver red, sincronizar → resolver éxito/fallo/conflicto. Nunca aplicar optimistic feedback a SOS, salida, permisos críticos o envío masivo.

### FLOW-NATIVE-01 Deep link
**Pantallas asociadas:** SCR-PRO-03.
**Capacidad:** CAP-NATIVE-01.
Abrir `nexo://…` o enlace universal → validar sesión → validar permiso → abrir objeto; si no, login y retorno. Tauri parcial; Capacitor planificado.

## 11. Matriz de estados obligatorios

| Estado | Respuesta |
|---|---|
| Carga | skeleton estructural; conservar contexto previo |
| Vacío | causa + próximo paso |
| Error | mensaje, datos preservados, reintento/alternativa |
| Sin permiso | no revelar contenido; volver |
| Offline | caché fechada y capacidades limitadas |
| Obsoleto | fecha + actualizar antes de decisión crítica |
| Conflicto | comparar estado actual vs. intento; no sobrescribir |
| Timeout | resultado desconocido; consultar antes de repetir |
| Éxito | resultado, alcance, auditoría/entrega y siguiente acción |

## 12. Criterios de aceptación

- Cada operación usa FLOW-OPS-BASE y tiene confirmación proporcional.
- No se pierde entrada ante error o navegación accidental.
- Reintentos mutantes son idempotentes.
- Todos los flujos tienen ruta offline/permiso/timeout.
- El estado WhatsApp distingue cola, envío, entrega, fallo y respuesta.
- Cada flujo enlaza una pantalla de [07_SCREEN_SPECIFICATIONS.md](./07_SCREEN_SPECIFICATIONS.md).


## 13. Trazabilidad consolidada

Matriz completa `FLOW-*` → `CAP-*` → `SCR-*`. Los `FLOW-OPS-*` comparten las pantallas `SCR-OPS-01/02/03`; `FLOW-AUTH-02` se accede desde `SCR-PRO-01`; `FLOW-PWA-02` alcanza `SCR-PRO-03`, `SCR-SYS-02` y `SCR-SYS-03`.

| Flujo | Capacidad | Pantallas asociadas |
|---|---|---|
| FLOW-AUD-01 | CAP-AUD-01 | SCR-AUD-01, SCR-AUD-02 |
| FLOW-AUD-02 | CAP-AUD-01 | SCR-AUD-02 |
| FLOW-AUTH-01 | CAP-AUTH-01 | SCR-AUTH-01, SCR-AUTH-02, SCR-HOME-01 |
| FLOW-AUTH-02 | CAP-AUTH-01 | SCR-PRO-01 |
| FLOW-AUTH-03 | CAP-AUTH-01 | SCR-AUTH-03 |
| FLOW-CASE-01 | CAP-CASE-01 | SCR-CASE-01, SCR-CASE-02, SCR-CASE-03 |
| FLOW-CASE-02 | CAP-CASE-01 | SCR-CASE-02, SCR-CASE-03 |
| FLOW-CASE-03 | CAP-CASE-01 | SCR-CASE-03 |
| FLOW-ENR-01 | CAP-ENR-01 | SCR-ENR-01, SCR-ENR-02 |
| FLOW-ENR-02 | CAP-ENR-01 | SCR-ENR-02 |
| FLOW-ENR-03 | CAP-ENR-01 | SCR-ENR-01 |
| FLOW-HOME-01 | CAP-HOME-01 | SCR-HOME-01, SCR-HOME-02, SCR-HOME-03 |
| FLOW-MSG-01 | CAP-MSG-01 | SCR-OPS-03, SCR-NOT-01 |
| FLOW-MSG-02 | CAP-MSG-02 | SCR-NOT-02 |
| FLOW-NATIVE-01 | CAP-NATIVE-01 | SCR-PRO-03 |
| FLOW-OPS-01 | CAP-OPS-01 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-02 | CAP-OPS-04 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-03 | CAP-OPS-02 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-04 | CAP-OPS-02 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-05 | CAP-OPS-02 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-06 | CAP-OPS-04 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-07 | CAP-OPS-03 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-08 | CAP-OPS-04 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-09 | CAP-OPS-04 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-10 | CAP-OPS-04 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-11 | CAP-OPS-04 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-12 | CAP-OPS-05 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-OPS-BASE | CAP-OPS-01–05 | SCR-OPS-01, SCR-OPS-02, SCR-OPS-03 |
| FLOW-PRO-01 | CAP-PRO-01 | SCR-PRO-01, SCR-PRO-02 |
| FLOW-PWA-01 | CAP-PWA-01 | SCR-PRO-03 |
| FLOW-PWA-02 | CAP-PWA-01 | SCR-PRO-03, SCR-SYS-02, SCR-SYS-03 |
| FLOW-QRY-01 | CAP-QRY-01 | SCR-QRY-01, SCR-QRY-02 |
| FLOW-REP-01 | CAP-REP-01 | SCR-REP-01, SCR-REP-02 |
