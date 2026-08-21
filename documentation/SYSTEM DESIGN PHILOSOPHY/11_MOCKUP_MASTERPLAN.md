# 11. Masterplan de mockups

**Propósito:** producir un sistema visual coherente y prototipos validables, no una colección de pantallas.

## 1. Orden de producción

1. **Foundations:** color, tipografía, espacio, grid, temas, foco y motion.
2. **Primitives:** controles, labels, estados y contenido.
3. **Shell:** sidebar/topbar, grupo, red/sync y responsive.
4. **Páginas patrón:** Inicio, Operaciones, lista+detalle, formulario, consulta/tabla, perfil.
5. **Patrones NEXO:** Hoy, Caso, indicador, conversación, WhatsApp, confirmación y sync.
6. **Flujos P0:** auth, citación, permiso/salida, SOS, caso, enrolamiento.
7. **Estados límite:** offline, timeout, conflicto, 403, vacío y error.
8. **Roles/temas/viewports:** variantes mínimas trazables.
9. **Prototipo y validación.**
10. **Handoff versionado.**

No avanzar un nivel si el anterior carece de contraste, estados o owner.

## 2. Páginas patrón

| Patrón | Pantallas derivadas | Qué debe fijar |
|---|---|---|
| P-01 Shell de jornada | todas autenticadas | navegación, título, contexto, red |
| P-02 Prioridad + actividad | Home roles | jerarquía y densidad |
| P-03 Centro de acciones | Operaciones | cards justificadas y agrupación |
| P-04 Flujo guiado | operaciones/enrolamiento | formulario, resumen, resultado |
| P-05 Lista + detalle | notificaciones/casos/consultas | responsive y retorno |
| P-06 Registro comparativo | consultas/auditoría/report preview | tabla/lista/filtros |
| P-07 Cuenta/settings | perfil/instalación | agrupación y guardado |
| P-08 Estado del sistema | error/offline/conflicto | recuperación |

Variantes se crean como propiedades de rol, ancho, tema y estado. No se desacoplan en archivos inconexos.

## 3. Datos y contenido

- Dataset compartido: Colegio Central, grupos 7A/10B, jornada, estudiantes ficticios consistentes, operaciones y casos conectados.
- Fechas/hora `es-CO`; números y periodos reales.
- Mensajes NEXO describen señal, periodo y acción; sin lorem ipsum.
- Estados WhatsApp coherentes a través del flujo.
- Riesgo siempre suministrado como dato backend simulado y marcado, no calculado en diseño.
- PII ficticia y claramente no productiva.

## 4. Matriz de temas y viewports

Cada página patrón se crea en claro/wide y claro/compact. Dark se valida en shell, Home, formulario, caso, tabla, diálogo y estados semánticos; luego se propaga por tokens. Medium se produce cuando la composición cambia. High contrast/reduced motion se anotan y prototipan en controles críticos.

Viewports de referencia no son dispositivos: 360×800, 768×1024, 1280×800, 1440×900, además 320 CSS px a reflow.

## 5. Estados de prototipo

- Default, hover/focus/press/disabled/loading/error para controles.
- Loading, vacío, éxito, advertencia, error, 403, offline, obsoleto y conflicto para páginas.
- Retorno contextual y foco.
- Polling WhatsApp sin saltos.
- Teclado móvil y safe areas.
- Reduced motion sin coreografía.

## 6. Puertas de validación

### Gate 1. Sistema
Design + frontend + accesibilidad aprueban tokens, contraste, estados y composición. Cero decisiones visuales sin token.

### Gate 2. Arquitectura
Usuarios encuentran módulos y vuelven al origen. Éxito ≥90%, first-click correcto ≥85%.

### Gate 3. Tareas P0
Por rol: auth, primera acción, operación propia y recuperación. General ≥85%; docentes 60+ ≥80%; error crítico 0.

### Gate 4. Accesibilidad
Teclado/lector/reflow/touch/contraste; 0 bloqueantes/altos.

### Gate 5. Ingeniería
Datos, permisos, idempotencia, offline y estados técnicos factibles; brechas no se ocultan.

### Gate 6. Piloto institucional
Jornada real o simulada con interrupciones y red degradada. Confianza ≥5/7, SUS/UMUX-Lite según protocolo, sin fuga de datos.

## 7. Protocolos y métricas

Tareas se formulan por objetivo, no instrucciones de UI. Facilitador no enseña. Registrar:

- éxito completo/parcial/fallo;
- tiempo y primer clic;
- errores, retrocesos y ayuda;
- confianza 1–7 y carga SEQ 1–7;
- comprensión de estado/entrega;
- recuerdo de contexto tras interrupción;
- accesibilidad y estrategia usada.

Muestra por iteración: 5 usuarios para detección cualitativa por segmento prioritario, no como garantía estadística; estudios cuantitativos requieren muestra calculada. Incluir profesores 60+ en cada gate de tareas.

## 8. Handoff

Cada mockup lleva `SCR-*`, versión, fecha, owner, `FLOW-*`, `CMP-*`, estado técnico, endpoint/capacidad, rol, viewport, tema, copy, estados y notas a11y. Assets exportables son mínimos; iconos provienen de biblioteca acordada. No entregar medidas sueltas fuera de tokens.

## 9. Control de cambios

- Fuente de decisión: registro `DEC-*` enlazado.
- Semver del sistema: major rompe contratos; minor agrega patrón compatible; patch corrige.
- Cambios post-validación incluyen motivo, pantallas afectadas, migración y nueva prueba.
- Deprecación visible por mínimo dos ciclos; variantes huérfanas se eliminan solo tras inventario.
- Design System owner decide forma; Producto alcance; Accesibilidad veto normativo; Ingeniería factibilidad, sin redefinir UX unilateralmente.

## 10. Definition of Ready para implementación

- Flujo y pantalla aprobados.
- Datos/permiso/errores contratados.
- Todos los estados y responsive diseñados.
- Copy final y telemetría ética definidos.
- Teclado/lector/touch especificados.
- Estado técnico honesto y dependencias asignadas.

## 11. Definition of Done de mockup

- Usa solo tokens/componentes aprobados.
- Pasa gates correspondientes.
- No contiene PII real, lorem ipsum ni capacidades presentadas falsamente.
- Tiene claro/dark y compact/wide suficientes.
- Prototipo reproduce éxito, error, offline y retorno.
- Decisiones y preguntas abiertas están registradas.

## 12. Criterios de aceptación

- Los mockups usan exclusivamente tokens y componentes aprobados en [03_DESIGN_SYSTEM.md](./03_DESIGN_SYSTEM.md).
- Cada patrón cubre claro/oscuro, compact/wide y los estados límite definidos en el lote correspondiente.
- El prototipo reproduce éxito, error, offline y retorno contextual.
- No se presentan capacidades o estados como existentes si son N4 o carecen de backend.
- El handoff incluye `SCR-*`, `FLOW-*`, `CMP-*`, endpoint/capacidad, rol, viewport, tema, estado técnico, owner, versión y notas a11y.
- Decisiones y preguntas abiertas están registradas con `DEC-*`.
