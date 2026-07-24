# 05. Arquitectura de información

**Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md). **Modelo:** una arquitectura responsive, filtrada por rol. El acudiente es actor externo por WhatsApp.

## 1. Modelo mental

NEXO se organiza por preguntas de trabajo, no por tablas de base de datos:

1. **Inicio:** ¿qué necesita mi atención ahora?
2. **Operaciones:** ¿qué acción quiero realizar?
3. **Notificaciones:** ¿qué ocurrió mientras trabajaba?
4. **Casos Activos:** ¿qué estudiantes requieren seguimiento?
5. **Consultas:** ¿qué información necesito buscar?
6. **Auditoría:** ¿qué ocurrió, quién actuó y es íntegro?
7. **Informes:** ¿qué necesito compartir o exportar?
8. **Enrolamiento:** ¿quién debe quedar habilitado en el sistema?
9. **Perfil:** ¿cómo gestiono mi cuenta y preferencias?

## 2. Objetos canónicos

| Objeto | Identidad | Relaciones | Regla de exposición |
|---|---|---|---|
| Institución | sede/colegio | grupos, personal, agenda | contexto superior |
| Grupo | grado, nombre, jornada | estudiantes, docente, eventos | contexto persistente |
| Estudiante contextual | nombre, foto, grupo | eventos, operaciones, caso | sin ficha integral |
| Situación | tipo, tiempo, severidad | evidencia, recomendación | inicia decisión |
| Operación | tipo, actor, alcance, estado | estudiante/grupo, mensajes | flujo único |
| Caso | estado, riesgo, responsable | estudiante, indicadores, notas | expediente vivo |
| Indicador | tipo, nivel, tendencia, periodo | caso/estudiante | backend autoritativo |
| Mensaje NEXO | evento, explicación, CTA | operación/caso/agenda | solo lectura |
| Mensaje WhatsApp | destinatario, entrega, respuesta | acudiente, operación | PII enmascarada |
| Evento auditado | actor, acción, tiempo, integridad | cualquier objeto | inmutable |
| Informe | tipo, periodo, filtros | datos autorizados | preview antes de exportar |
| Usuario institucional | rol, permisos, sede | tareas, sesión | perfil propio |

## 3. Sitemap objetivo

```text
Autenticación
├─ Iniciar sesión (SCR-AUTH-01)
├─ Verificar 2FA (SCR-AUTH-02)
└─ Recuperar acceso (SCR-AUTH-03, planificado)

Shell institucional
├─ Inicio (SCR-HOME-01)
│  ├─ Hoy en el colegio (SCR-HOME-02)
│  └─ Detalle de situación/métrica (SCR-HOME-03)
├─ Operaciones (SCR-OPS-01)
│  ├─ Flujo de operación (SCR-OPS-02)
│  └─ Resultado y entrega (SCR-OPS-03)
├─ Notificaciones (SCR-NOT-01)
│  └─ Detalle contextual (SCR-NOT-02)
├─ Casos Activos (SCR-CASE-01)
│  ├─ Caso (SCR-CASE-02)
│  └─ Nota/evolución/cierre (SCR-CASE-03)
├─ Consultas (SCR-QRY-01)
│  └─ Resultados/detalle (SCR-QRY-02)
├─ Auditoría (SCR-AUD-01)
│  └─ Integridad/evento (SCR-AUD-02)
├─ Informes (SCR-REP-01)
│  └─ Previsualización/exportación (SCR-REP-02)
├─ Enrolamiento (SCR-ENR-01)
│  └─ Alta/biometría (SCR-ENR-02)
└─ Perfil (SCR-PRO-01)
   ├─ Cuenta y seguridad (SCR-PRO-02)
   └─ Apariencia, instalación y sync (SCR-PRO-03)
```

## 4. Navegación por rol

| Módulo | Rector | Coordinador | Docente | Secretaría | Portero | Auxiliar | Psicoorientador |
|---|---|---|---|---|---|---|---|
| Inicio | Sí | Sí | Sí | Sí | Sí | Sí | Sí |
| Operaciones | todas | dirección | aula | solicitud | SOS/solicitud/daño | SOS/solicitud/daño | bienestar |
| Notificaciones | Sí | Sí | grupos | sistema | órdenes | órdenes | seguimiento |
| Casos Activos | total | total | no lista | no | no | no | gestión |
| Consultas | ejecutivo | incidentes/permisos | mis clases | gestión | no | no | riesgo/seguimientos |
| Auditoría | Sí | no | no | no | no | no | no |
| Informes | Sí | no | no | no | no | no | no |
| Enrolamiento | no | no | no | Sí | no | no | no |
| Perfil | propio | propio | propio | propio | propio | propio | propio |

`SUPER_RECTOR` y `EDGE_NODE` son roles técnicos fuera del producto institucional objetivo. El acudiente no tiene sitemap WebApp.

## 5. Tareas y permisos

Cada capacidad incluye: estado técnico, nivel de evidencia (N1–N4), fecha de auditoría (2025-03-08), método (auditoría estática de UI, clientes API, rutas, workers y documentación), responsable (por asignar) y limitaciones conocidas (ver columna de estado). Ninguna capacidad es Nivel 1 sin prueba manual end-to-end.

| ID | Tarea | Roles objetivo | Capacidad | Estado técnico, evidencia 2025-03-08 |
|---|---|---|---|---|
| CAP-AUTH-01 | login/sesión/logout | todos | `/auth/*`, cookie/JWT, RBAC/RLS | Implementado (Pendiente validación), N2 |
| CAP-AUTH-02 | 2FA | todos según política | login + verify-2fa/OTP | Implementado (Pendiente validación), N2 |
| CAP-HOME-01 | inicio por rol | todos | stats/events | Implementado (Pendiente validación), N2 |
| CAP-HOME-02 | grupo persistente global | docente/contextuales | UI local parcial | Backend parcial; persistencia transversal no demostrada |
| CAP-HOME-03 | Hoy en el colegio/agenda | roles | visión | Planificado, N4 |
| CAP-OPS-01 | citar | rector/coordinador/docente/psico | operación + Twilio | Implementado (Pendiente validación), N2 |
| CAP-OPS-02 | salida/permiso | autorizados | operación + persistencia | Implementado (Pendiente validación), N2 |
| CAP-OPS-03 | SOS | todos institucionales | operación/panic según alcance | Implementado (Pendiente validación), N2 |
| CAP-OPS-04 | incidente/daño/solicitud | según rol | operaciones | Implementado (Pendiente validación), N2 |
| CAP-OPS-05 | agenda/reunión | rector/coordinador | visión | Planificado, N4 |
| CAP-MSG-01 | WhatsApp outbound/entrega | institucional → acudiente | cola worker/webhook | Implementado (Pendiente validación), N2 |
| CAP-MSG-02 | respuesta acudiente | acudiente externo | webhook inbound | Backend listo, N3; UI final no demostrada |
| CAP-CASE-01 | casos/riesgo/notas/evolución | rector/coordinador/psico | tracking + behavior | Implementado (Pendiente validación), N2 |
| CAP-QRY-01 | consultas por rol | cinco roles | motor query | Implementado (Pendiente validación), N2; módulos vacíos/legacy |
| CAP-AUD-01 | auditoría/integridad | rector objetivo | audit routes/hash | Implementado (Pendiente validación), N2 |
| CAP-REP-01 | preview/export CSV | rector | reports/audit | Implementado (Pendiente validación), N2 |
| CAP-ENR-01 | estudiantes/biometría | secretaría | students + edge | Backend parcial; captura real requiere hardware |
| CAP-PRO-01 | perfil/contacto/clave | todos | users | Implementado (Pendiente validación), N2 |
| CAP-PWA-01 | instalar/cache/background sync | todos | manifest/workbox | Implementado (Pendiente validación), N2; cola debe validarse |
| CAP-NATIVE-01 | Tauri/deep links | distribución futura | adaptador parcial | Backend parcial / integración parcial |
| CAP-NATIVE-02 | Capacitor | distribución futura | visión | Planificado, N4 |

**Método:** auditoría estática de UI, clientes API, rutas, workers, documentación y configuración. **Responsable:** equipo NEXO por asignar. **Limitación:** no hubo entorno end-to-end ni prueba manual; ninguna capacidad es Nivel 1.

## 6. Arquitectura responsive

La jerarquía lateral no cambia:

- **Wide:** sidebar fija, topbar contextual, contenido y panel auxiliar opcional.
- **Medium:** rail lateral colapsable; panel de detalle reemplaza columna secundaria cuando falta ancho.
- **Compact:** botón “Menú” abre el mismo árbol en panel lateral; no se crea bottom-nav ni arquitectura híbrida. El título y grupo activo quedan en topbar. Detalles y formularios se presentan como rutas o sheets breves.

El estado de navegación, grupo y retorno pertenece a la URL/estado de sesión cuando no expone PII. Deep links apuntan a objeto y acción, luego validan sesión/permiso.

## 7. Búsqueda y comandos

- Búsqueda global es objetivo futuro, disponible por botón y atajo solo si existe índice autorizado.
- Resultados agrupados por objeto y limitados por rol; no revelar existencia de objetos sin permiso.
- La paleta de comandos acelera acciones conocidas, nunca es única vía ni sustituye Operaciones.
- Consulta mantiene filtros en URL cuando son no sensibles; estudiante se referencia por ID opaco.

## 8. Retorno y rutas de escape

Cada detalle recibe `origin`, filtros, scroll y foco. “Volver” regresa al origen real, no siempre al módulo raíz. Cancelar formulario conserva borrador solo con consentimiento/contexto seguro. Cierre de sesión borra contexto sensible. Errores 403 ofrecen volver a Inicio; 404 no revela PII.

## 9. Registro de decisiones y discrepancias

| DEC | Conflicto | Autoridad aplicada | Decisión / resolución | Impacto | Estado |
|---|---|---|---|---|---|
| DEC-IA-01 | Código llama `Seguimiento`; fuente exige `Casos Activos` | UX_DESIGN.md | El módulo se documenta como **Casos Activos**; la etiqueta/ruta `Seguimiento` de `roles.js` se alineará en fase de implementación sin cambiar backend | Consistencia de producto | Resuelto documentalmente |
| DEC-IA-02 | Rector ausente de Consulta en ruta frontend actual | UX_DESIGN.md | Incluir rector en consultas objetivo; no afirmar implementación hasta prueba funcional | Brecha RBAC UI | Documentado |
| DEC-IA-03 | Backend documenta auditoría para COORDINATOR; fuente la reserva a Rector | UX_DESIGN.md para navegación | No exponer UI de Auditoría a coordinador; backend puede conservar permiso técnico | Seguridad vs funcionalidad | Resuelto documentalmente |
| DEC-IA-04 | Rector no tiene todas las operaciones en catálogo UI | UX_DESIGN.md | Completar objetivo de operaciones para rector; no afirmar implementación | Operaciones rector/coordinador | Documentado |
| DEC-IA-05 | Tarjetas para toda acción vs evitar cards arbitrarias | UX_DESIGN.md + prueba de patrón | Cards solo iniciadores operativos; listas/tablas cuando mejoren comparación | Equilibrio filosofía/densidad | Resuelto documentalmente |
| DEC-IA-06 | Manifest bloquea portrait | Responsive First | Recomendar orientación libre; `vite.config.js` debe ajustarse en implementación | PWA manifest vs responsive | Documentado |
| DEC-IA-07 | Etiqueta del rol de control de acceso: canonical `Portero` | UX_DESIGN.md | Canonical `Portero`; unificado en todos los entregables | Nomenclatura | Resuelto documentalmente |
| DEC-IA-08 | Etiqueta del rol de bienestar: canonical `Psicoorientador` | UX_DESIGN.md | Canonical `Psicoorientador`; unificado en todos los entregables | Nomenclatura | Resuelto documentalmente |
## 10. Criterios de aceptación

- Toda tarea pertenece a un rol, capacidad, flujo y pantalla.
- El acudiente solo aparece en WhatsApp y flujos externos.
- No existe navegación híbrida por breakpoint.
- Permisos se validan en backend; ocultar UI no se considera seguridad.
- Retorno restaura contexto y foco.
- Discrepancias se conservan auditables, no se silencian.


## 11. Trazabilidad consolidada

La matriz de tareas y permisos (`CAP-*`) enlaza con los flujos de [06_USER_FLOWS.md](./06_USER_FLOWS.md) y las pantallas de [07_SCREEN_SPECIFICATIONS.md](./07_SCREEN_SPECIFICATIONS.md). Cada discrepancia queda registrada en `DEC-IA-*` y se resuelve a favor de `UX_DESIGN.md` o se documenta como dependencia de implementación.
