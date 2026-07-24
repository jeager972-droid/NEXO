# 10. Plan de wireframes

**Propósito:** validar arquitectura, jerarquía y estados antes de estilo. No contiene dibujos ni decisiones de implementación.

## 1. Estrategia de cobertura

Se diseña una página patrón por familia y variantes de contenido/estado, no una pantalla independiente por rol. Viewports de prueba: compact 360×800 y 320×640; medium 768×1024; wide 1280×800; xwide 1440×900. También 400% reflow y touch/teclado.

## 2. Lotes

### Lote A. Arquitectura y shell, fidelidad baja, P0

| WF | Pantallas | Variantes |
|---|---|---|
| WF-A01 | SCR-HOME-01 | 7 roles, sidebar wide/rail/panel móvil, grupo/no grupo |
| WF-A02 | shell | perfil/tema/sync, permisos, deep link y retorno |
| WF-A03 | SCR-SYS-01, SCR-SYS-02, SCR-SYS-03 | 403, offline, obsoleto, conflicto |

**Validar:** menú lateral único, título/primaria, foco, safe areas y persistencia.

### Lote B. Tareas críticas, fidelidad media, P0

| WF | Flujo | Estados/viewports |
|---|---|---|
| WF-B01 | FLOW-AUTH-01 | login, 2FA, error, sesión; compact/wide |
| WF-B02 | FLOW-OPS-01 | citación completa + entrega; todos |
| WF-B03 | FLOW-OPS-03/04 | permiso/salida + confirmación; compact/wide |
| WF-B04 | FLOW-OPS-07 | SOS éxito/timeout/error; compact/wide |
| WF-B05 | FLOW-CASE-01/02 | lista, caso, nota; medium/wide/compact |
| WF-B06 | FLOW-ENR-01/02 | alta y biometría pendiente; wide/compact |

**Contenido realista:** nombres colombianos plausibles, grupos 7A/10B, fechas `es-CO`, estados WhatsApp y mensajes claros; nunca lorem ipsum.

### Lote C. Información y control, fidelidad media, P1

- WF-C01 Conversación NEXO y respuesta de acudiente.
- WF-C02 Consultas docente/coordinador/secretaría/psico/rector.
- WF-C03 Resultados como lista, tabla justificada y detalle.
- WF-C04 Auditoría, evento e integridad.
- WF-C05 Informes, preview y exportación larga.
- WF-C06 Perfil, seguridad, tema e instalación.

### Lote D. Estados límite y responsive, fidelidad media, P0

Cada patrón de A–C se prueba con:

- loading estable, vacío inicial y filtrado;
- error field/section/global;
- 403 y datos parcialmente autorizados;
- offline con caché, datos obsoletos, cola y conflicto;
- timeout y resultado desconocido;
- nombre 60 caracteres, texto 200%, 0/1/1000 registros;
- teclado abierto, safe area, landscape y ventana angosta;
- dark/high contrast/reduced motion anotados, no estilizados.

### Lote E. Refinamiento, fidelidad alta estructural, P2

Consolidar componentes, copy final, comportamiento de foco, transiciones, telemetría y handoff. No aplicar visual polish hasta aprobar arquitectura y tareas P0.

## 3. Anatomía que debe anotarse

Cada wireframe incluye:

1. `SCR-*`, `FLOW-*`, rol y pregunta.
2. Orden de lectura y landmarks.
3. Contenido y datos necesarios.
4. Acción primaria/secundarias y consecuencia.
5. Componentes `CMP-*` y variantes.
6. Permiso y privacidad.
7. Estado de red/actualización.
8. Teclado, foco inicial/retorno y touch.
9. Cambio por clase de ancho/capacidad.
10. Transición/origen/destino.
11. Eventos de telemetría sin PII.
12. Supuesto o pregunta abierta.

## 4. Inventario mínimo por rol

| Rol | Wireframes obligatorios |
|---|---|
| Rector | Home, todas operaciones objetivo, casos, consultas ejecutivo, auditoría, informes, agenda |
| Coordinador | Home, operaciones dirección, casos, consultas incidentes/permisos, agenda |
| Docente | Home con grupo, citación/permiso/incidente/SOS, consultas clases, notificaciones |
| Secretaría | Home, solicitud, consultas gestión, enrolamiento/biometría, notificaciones |
| Portero | Home órdenes, SOS, solicitud, daño, notificaciones |
| Auxiliar | Home órdenes, SOS, solicitud, daño, notificaciones |
| Psicoorientador | Home bienestar, citación/incidente/SOS, casos/notas, riesgo |
| Acudiente | solo storyboard WhatsApp outbound/respuesta, sin pantalla WebApp |

## 5. Matriz de cantidad controlada

No multiplicar 28 pantallas × 7 roles × 4 anchos. Base esperada: 20–26 wireframes patrón, 35–45 variantes de estado y 10–14 adaptaciones responsive decisivas. Una variante solo existe si cambia jerarquía, permiso, contenido o interacción.

## 6. Protocolo de revisión

1. **Diseño:** pregunta, jerarquía, patrón y vocabulario.
2. **Producto:** autoridad UX y alcance objetivo.
3. **Ingeniería:** datos, permiso, estados técnicos y brechas.
4. **Accesibilidad:** orden, foco, reflow, touch y lenguaje.
5. **Usuario:** tarea sin explicación del facilitador.

Cambios se registran con `DEC-*`; no se resuelven verbalmente sin trazabilidad.

## 7. Checklist de aprobación

- [ ] Una pregunta y una primaria.
- [ ] ≤5 prioridades en primer viewport.
- [ ] Datos realistas y privacidad contextual.
- [ ] Flujo feliz, error, permiso, offline y recuperación.
- [ ] Grupo/filtros/retorno persistentes.
- [ ] Componentes existentes; sin card anidada.
- [ ] Adaptación compact/medium/wide sin arquitectura híbrida.
- [ ] Orden de lectura, foco y targets anotados.
- [ ] Estado técnico/evidencia visible en handoff.
- [ ] Prueba de tarea cumple umbral del lote.

## 8. Criterios de salida

P0: ≥85% éxito general y ≥80% profesores 60+, sin bloqueo a11y; P1 ≥80%; errores críticos 0. Solo entonces pasan a mockup. Las preguntas de backend no se “diseñan alrededor”: quedan como dependencia explícita.

## 9. Criterios de aceptación

- Cada wireframe se vincula a un `SCR-*`, `FLOW-*`, rol y pregunta de trabajo.
- Se cubren los viewports compact (360×800 y 320×640), medium (768×1024), wide (1280×800) y xwide (1440×900), además de reflow 400%.
- Cada lote incluye éxito, error, permiso, offline, vacío, timeout y recuperación según corresponda.
- No se multiplican variantes equivalentes; cada variante justifica un cambio real de jerarquía, permiso, contenido o interacción.
- Los datos son realistas, coherentes con el dataset institucional y nunca lorem ipsum.
- Todos los componentes usados están en [03_DESIGN_SYSTEM.md](./03_DESIGN_SYSTEM.md).
- Las preguntas abiertas y decisiones quedan registradas con `DEC-*`.
