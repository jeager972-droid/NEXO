# 03. Sistema de diseño NEXO

**Nombre:** NEXO Quiet Operations  
**Versión:** 1.0 objetivo  
**Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md). Esta es una especificación tokenizable, no código.

## 1. Fundamentos

### 1.1 Color, estrategia restringida

Los valores definitivos se validan en sRGB y P3. Se expresan en OKLCH para evolución consistente.

| Token | Claro | Oscuro | Uso |
|---|---|---|---|
| `color.canvas` | `oklch(98% 0.006 245)` | `oklch(18% 0.008 245)` | fondo raíz |
| `color.surface` | `oklch(99.3% 0.004 245)` | `oklch(22% 0.009 245)` | superficie principal |
| `color.surface.subtle` | `oklch(96% 0.009 245)` | `oklch(26% 0.010 245)` | navegación/agrupación |
| `color.text` | `oklch(23% 0.018 245)` | `oklch(94% 0.007 245)` | texto principal |
| `color.text.muted` | `oklch(48% 0.018 245)` | `oklch(72% 0.012 245)` | secundario |
| `color.border` | `oklch(88% 0.010 245)` | `oklch(35% 0.012 245)` | división |
| `color.accent` | `oklch(48% 0.115 245)` | `oklch(72% 0.105 245)` | primaria/selección/info |
| `color.success` | `oklch(52% 0.125 155)` | `oklch(72% 0.115 155)` | correcto |
| `color.warning` | `oklch(62% 0.135 70)` | `oklch(78% 0.120 70)` | atención |
| `color.danger` | `oklch(52% 0.175 25)` | `oklch(72% 0.145 25)` | crítico/destructivo |

Cada semántico tiene `surface`, `text`, `border` y `strong`; combinaciones deben cumplir 4.5:1 texto normal, 3:1 texto grande/componentes. Datos nunca dependen solo de tono: usan etiqueta, icono/patrón y valor.

### 1.2 Tipografía

Familia: Inter Variable, fallback system-ui. Cifras tabulares para métricas, horas y tablas.

| Token | Tamaño/alto | Peso | Uso |
|---|---|---:|---|
| `type.display` | 32/38 | 650 | título principal amplio |
| `type.h1` | 28/34 | 650 | pantalla |
| `type.h2` | 22/28 | 620 | sección |
| `type.h3` | 18/24 | 620 | bloque |
| `type.body` | 16/24 | 430 | texto/inputs |
| `type.body.small` | 14/20 | 450 | apoyo |
| `type.label` | 14/18 | 600 | control |
| `type.caption` | 12/16 | 520 | metadato no crítico |

No usar mayúsculas extensas ni tracking exagerado. Línea de prosa 45–72 caracteres; títulos comprensibles aislados. Zoom 200% sin pérdida y reflow 400%.

### 1.3 Espaciado y forma

Escala: 4, 8, 12, 16, 24, 32, 48, 64 px. Densidades: `comfortable` por defecto, `compact` solo tablas de escritorio y elección explícita. Radios: 8 controles, 12 superficies, 16 paneles; píldora solo chips/estado. Bordes 1 px. Sombras solo superposición: baja, media y diálogo; nunca como decoración.

### 1.4 Grid y anchos

| Clase | Ancho | Composición |
|---|---|---|
| `compact` | 0–599 | 4 columnas, margen 16, gap 12, navegación lateral como panel superpuesto |
| `medium` | 600–1023 | 8 columnas, margen 24, gap 16, rail lateral colapsable |
| `wide` | 1024–1439 | 12 columnas, sidebar 240, contenido 720–1120 |
| `xwide` | ≥1440 | sidebar 256, contenido máx. 1280; no estirar lectura |

Se decide también por `pointer`, teclado, hover, safe areas y ventana, no por modelo. Acción móvil importante respeta safe-area inferior. Orientación libre salvo captura biométrica externa justificada.

### 1.5 Movimiento

Tokens: `instant 0`, `fast 150 ms`, `standard 200 ms`, `deliberate 250 ms`, `max 300 ms`; curva salida `cubic-bezier(.22,1,.36,1)`. Solo `opacity` y `transform`. Reduced motion elimina desplazamiento/escala y conserva cambios instantáneos o fundidos ≤100 ms. Sin sonido, parpadeo, rebote ni secuencias de entrada.

## 2. Contrato común de componentes

Todo componente interactivo especifica: anatomía, tamaños, variantes cerradas, default, hover, focus-visible, pressed, selected, disabled, loading, error, offline; nombre accesible, teclado, touch, lector, responsive, contenido y uso incorrecto. Foco: perímetro sólido ≥2 px y contraste ≥3:1; nunca oculto. Targets: 44×44 recomendados, nunca menos de WCAG 24×24 ni sin separación.

## 3. Primitivos y controles

### CMP-001 Botón
Anatomía: label verbal + icono opcional + progreso. Variantes: primary, secondary, quiet, danger. Tamaños 44 y 52 px. Un primary por estado. Loading conserva ancho y anuncia estado. Icon-only exige tooltip y nombre. No deshabilitar sin explicar motivo.

### CMP-002 Campo de texto / CMP-003 Textarea
Label persistente arriba, control, ayuda y error en flujo. Altura 48; textarea mínimo 3 líneas, crecimiento limitado. Placeholder es ejemplo, no label. Error junto al campo y resumen al enviar. `autocomplete` semántico.

### CMP-004 Búsqueda
Campo con label accesible, limpiar, estado y sugerencias. Resultados tras 2–3 caracteres o submit según costo. Escape cierra sugerencias; flechas recorren; Enter selecciona. Historial solo si aporta y respeta privacidad.

### CMP-005 Select / CMP-006 Combobox
Select nativo para listas cortas estables. Combobox para >7 opciones o búsqueda. Texto visible, no códigos. Soporta teclado APG, loading, vacío y error. En compact, lista puede ocupar sheet completa.

### CMP-007 Fecha y hora
Entrada editable + selector; formato visible local `es-CO`; no bloquear teclado. Indicar zona y restricciones. Rangos resumen inicio/fin. Errores como “La fecha debe ser posterior a hoy”.

### CMP-008 Checkbox / radio / switch
Checkbox para selección múltiple; radio para una opción; switch solo efecto inmediato reversible. Área 44 px; label activa. No usar switch para “guardar”.

### CMP-009 Filtros
Barra con filtros prioritarios, contador y “Limpiar”. Chips representan filtros activos, no acciones generales. En móvil abre sheet; aplicar puede ser inmediato si barato o botón único si consulta costosa.

### CMP-010 Chip / CMP-011 Badge
Chip es interactivo/seleccionable; badge es estado no interactivo. Máximo 2 por fila resumida. Estado siempre texto + semántica, no color solo.

## 4. Contenedores de información

### CMP-020 Card
Solo resumen autónomo o inicio de acción. Anatomía: título, apoyo, estado, acción/destino. Variantes `action`, `summary`, `case`; sin card anidada. Grid máximo 5 prioritarias; después agrupar o listar.

### CMP-021 Lista
Patrón por defecto para notificaciones, eventos, estudiantes y casos. Filas 56–88 px, jerarquía consistente, destino completo, acciones secundarias al final. Virtualizar si >100 visibles sin romper accesibilidad.

### CMP-022 Tabla
HTML semántico; caption, encabezados, orden anunciado, paginación. Solo comparación. En compact: conservar 2–3 campos prioritarios en lista y detalle, no scroll horizontal como única solución. Acciones masivas separadas.

### CMP-023 Calendario
Agenda/lista es default móvil; mes solo para explorar fechas. Eventos con texto y estado. Teclado completo, “Hoy”, zona horaria. No comunicar categoría solo por color.

### CMP-024 Timeline
Orden cronológico con fecha absoluta + relativa, actor, evento, resultado y evidencia. No es decorativa. Agrupa por día; datos auditados se distinguen de notas.

### CMP-025 Burbuja NEXO
Mensaje de solo lectura: origen NEXO, tiempo, texto, evidencia resumida y CTA contextual. No imita chat bidireccional ni muestra caja de escritura.

### CMP-026 Gráfico / CMP-027 Indicador
Gráfico: título, resumen, periodo, ejes/unidad, leyenda directa, alternativa tabular. Indicador: nombre, estado textual, tendencia, periodo y explicación. Sin 3D, gauges ni gráficas enormes.

## 5. Navegación y superficies

### CMP-030 Sidebar
Patrón rector. Logo, navegación filtrada, utilidad; abajo tema, perfil y cierre. Wide fijo; medium rail expandible; compact se abre desde botón “Menú” como panel lateral y conserva exactamente la misma jerarquía. `aria-current`, foco y Escape.

### CMP-031 Topbar
Título contextual, grupo activo cuando aplica, estado de red/sync y una acción. No duplica navegación.

### CMP-032 Tabs
Solo vistas hermanas del mismo objeto, máximo 5 visibles. Patrón APG; activación automática solo si instantánea. En compact, scroll con indicadores o select si excede.

### CMP-033 Breadcrumbs
Solo profundidad ≥2 en wide/medium. Compact usa “Volver a {origen}” preservando contexto.

### CMP-034 Drawer / CMP-035 Bottom sheet
Drawer para detalle secundario en wide; sheet para selección breve touch. Focus trap, Escape, retorno al disparador. Si el contenido es tarea larga, usar página.

### CMP-036 Diálogo
Confirmación crítica o decisión breve. Título describe consecuencia, cuerpo nombra alcance, primaria verbal específica. Foco inicial en opción segura; no cerrar accidentalmente durante envío.

### CMP-037 Alert / CMP-038 Toast-snackbar
Alert persiste junto al problema; toast confirma eventos no críticos y no contiene información única. Duración mínima legible, pausable; errores recuperables no desaparecen. Máximo uno visible.

### CMP-039 Skeleton / CMP-040 Loading
Skeleton replica estructura y se anima sutilmente o queda estático con reduced motion. Spinner solo dentro de control o proceso indeterminado compacto. No skeleton para datos en error/offline si existe caché.

### CMP-041 Empty/error state
Título factual, causa si se conoce, acción disponible y alternativa. Vacío inicial enseña; vacío filtrado ofrece limpiar; sin permiso no insinúa existencia de datos.

## 6. Patrones NEXO

### CMP-100 Selector de grupo
Persistente, muestra grupo y jornada; lista solo asignaciones autorizadas. Cambio actualiza contexto con confirmación si hay formulario sucio. Estado “Todos” solo roles institucionales autorizados.

### CMP-101 Acción operativa
Card de inicio → formulario de 3–5 decisiones → resumen → resultado. Muestra destinatarios y alcance WhatsApp antes de confirmar. Estados offline no prometen envío.

### CMP-102 Caso
Encabezado de estudiante contextual (foto, nombre, grupo), riesgo textual, resumen NEXO, indicadores, timeline, responsable y acciones. Nunca ficha completa.

### CMP-103 Indicador de riesgo
Nivel + tendencia + evidencia + periodo + “cómo se determinó” suministrado por backend. No permite recalcular en frontend.

### CMP-104 Conversación NEXO
Feed cronológico de mensajes de solo lectura, filtros mínimos y acciones inline. No bandeja de correo, no composer.

### CMP-105 Estado WhatsApp
En cola, enviado, entregado, leído (solo si proveedor confirma), fallido, respondido. Incluye hora, destinatario enmascarado, reintento autorizado y última actualización.

### CMP-106 Estudiante contextual
Foto opcional, nombre, grupo, estado relevante y una acción. Variantes por permiso sin añadir datos personales innecesarios.

### CMP-107 Confirmación crítica
Consecuencia, alcance, actor, irreversibilidad y frase de acción. Para SOS, salida, cierre de caso y comunicaciones masivas.

### CMP-108 Sincronización PWA
Estado global discreto: En línea, Sin conexión, Guardando localmente, Sincronizando N, Conflicto, Actualizado. Detalle revela cola y recuperación sin datos sensibles.

## 7. Contenido

Botones en infinitivo: “Enviar citación”, “Guardar nota”. Estados en participio: “Entregado”. Títulos en sentence case. Fechas absolutas en decisiones críticas; relativas solo como apoyo. Números con unidad. No “Aceptar” cuando puede decir “Autorizar salida”.

## 8. Visualización y estados completos

Toda superficie contempla carga, vacío inicial, vacío filtrado, éxito, advertencia, error recuperable/terminal, sin permiso, offline, datos obsoletos y conflicto. Los datos cacheados llevan “Actualizado {hora}”.

## 9. Gobierno

Tokens y componentes tienen owner de Design System + frontend; versión semántica. Cambios breaking requieren migración, deprecación mínima de dos releases y pruebas visuales/a11y. No se crean variantes fuera del inventario sin `DEC-*`, caso repetido ≥3 y revisión de accesibilidad.

## 10. Criterios de aceptación

- Cobertura de todos los componentes solicitados y estados comunes.
- Contraste validado en ambos temas.
- Cero cards anidadas y cero color como única señal.
- Responsive probado en 320, 360, 768, 1024, 1440 y zoom 400%.
- Cada patrón enlaza pantallas en [07_SCREEN_SPECIFICATIONS.md](./07_SCREEN_SPECIFICATIONS.md).
- Cada componente del inventario (`CMP-001–011`, `CMP-020–041` y `CMP-100–108`, con `CMP-012–019` y `CMP-028–029` reservados para extensiones futuras) tiene al menos un uso documentado en las matrices de 07 o en un patrón NEXO.
