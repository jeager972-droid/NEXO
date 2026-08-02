# 13. Auditoría de frontend T7 — contradicciones con la fuente

**Fecha:** 2026-07-26
**Alcance:** `WebApp/src` completo (shell, primitivos, patrones, 13 pantallas).
**Autoridad:** [UX_DESIGN.md](./UX_DESIGN.md) → [01](./01_PRODUCT_DESIGN_PHILOSOPHY.md)–[12](./12_FRONTEND_IMPLEMENTATION_GUIDE.md) → [nexo_moodboard.html](./nexo_moodboard.html) / [nexo_screens.html](./nexo_screens.html).
**Método:** lectura completa de la documentación, inventario de componentes, comparación pantalla a pantalla contra `SCR-*`, `CMP-*` y `DEC-*`.

---

## 1. Diagnóstico general

El frontend actual **cumple los tokens** (color, radios, motion, tipografía están correctamente declarados en `index.css` y `tailwind.config.js`) pero **no cumple el lenguaje**. Es un sistema de diseño correcto aplicado con gramática de plantilla administrativa.

Las tres fallas estructurales:

1. **Ausencia de la firma NEXO.** [04_VISUAL_LANGUAGE.md §4](./04_VISUAL_LANGUAGE.md) define la *línea de situación* (`nx-sig` en el moodboard) como la firma visual del producto. No existe en ninguna pantalla. Sin ella NEXO **muestra datos** en vez de **detectar situaciones**: falla el North Star completo.
2. **Jerarquía plana.** Todas las pantallas usan el mismo `<Section title subtitle>` de 22 px. No hay diferencia visual entre "Panel Docente" (pantalla) y "Eventos recientes" (bloque). Falla la prueba "solo títulos" de [04 §12](./04_VISUAL_LANGUAGE.md).
3. **Superficie única sin identidad.** Todo es la misma caja blanca con borde de 1 px. Falla la prueba *blur test* y la prueba *swap test*: cambiando el logo, cualquier pantalla podría pertenecer a cualquier SaaS.

---

## 2. Contradicciones por severidad

### P0 — rompen la filosofía o son bugs funcionales

| # | Hallazgo | Ubicación | Fuente violada |
|---|---|---|---|
| A-01 | El sidebar es `lg:relative` dentro de un contenedor `min-h-screen` que crece con el contenido: el pie (tema, perfil, cerrar sesión) **se desplaza fuera del viewport** al hacer scroll. El árbol rector deja de ser rector. | `layout/Sidebar.jsx:57-64`, `layout/Layout.jsx:75-78` | `CMP-030`: "Wide fijo" |
| A-02 | Mensajes de error del backend se pintan crudos: `Credenciales incorrectas (P)`, `Credenciales incorrectas (U1)`, `Error 500`, `Network Error`. | `pages/Login.jsx:56`, todas las páginas | [01 §3](./01_PRODUCT_DESIGN_PHILOSOPHY.md): "Nunca «Error 500»" |
| A-03 | El resultado de una operación es un `<div>` verde con el `message` literal del backend (`"Citación encolada para envío"`). No existe `SCR-OPS-03`. | `pages/Operation.jsx:316-320` | `SCR-OPS-03`, `DEC-017` (Peak-End), `CMP-105` |
| A-04 | El icono "mostrar contraseña" se posiciona con `top-[30px]` absoluto respecto al campo **incluyendo el label**: queda descentrado. Repetido 4 veces (login + 3 en perfil). | `Login.jsx:128`, `Profile.jsx:330,334,338` | `CMP-002`, `DEC-020` |
| A-05 | `auditApi.exportConsolidated()` **no existe** en `api/audit.js`. El botón "Exportar" de Auditoría lanza `TypeError`. | `pages/Audit.jsx:202` vs `api/audit.js` | `DEC-016` |
| A-06 | Las tarjetas de estudiante en Enrolamiento no son interactivas: no hay forma de abrir el estudiante. | `pages/Enrollment.jsx:197-206` | `SCR-ENR-01`, `CMP-106` |
| A-07 | `alert()` nativo del navegador como manejo de error en el flujo de seguimiento. | `pages/Dashboard.jsx:606,617` | `CMP-037`, [01 §3](./01_PRODUCT_DESIGN_PHILOSOPHY.md) |
| A-08 | Rector/Coordinador no ven **Permisos activos**; solo 3 KPIs. Docente sí ve 4. Inconsistencia de modelo mental entre roles. | `pages/Dashboard.jsx:149-153` | `UX_DESIGN.md` §Home |
| A-09 | Auditoría existe como módulo independiente y duplica Informes; además su guard de rol (`ADMIN_ROLES` incluye coordinador y secretaría) contradice `DEC-IA-03`. | `pages/Audit.jsx:24,247` | `DEC-IA-03` |

### P1 — degradan la percepción premium

| # | Hallazgo | Fuente violada |
|---|---|---|
| B-01 | Login abre con "Buenas tardes / Inicia tu jornada en NEXO". Saludo antes de autenticar: promesa vacía, sin valor informativo. | [01 §9 REC-001](./01_PRODUCT_DESIGN_PHILOSOPHY.md): el saludo es **posterior** al login |
| B-02 | Estados vacíos = una línea de texto (`"Sin eventos recientes"` / `"No tienes novedades pendientes."`). Sin icono, sin aire, sin siguiente paso. | `CMP-041`: "título factual, causa, acción disponible y alternativa" |
| B-03 | Las 4 tarjetas de métrica son cajas blancas idénticas con un número. Sin identidad, sin color psicológico, sin estado textual, sin tendencia. | `CMP-027`: "nombre, estado textual, tendencia, periodo" |
| B-04 | `Select` usa el carácter `▼` como flecha: rompe la coherencia de iconografía lineal 1.75–2 px. | [04 §3.7](./04_VISUAL_LANGUAGE.md) |
| B-05 | Skeleton usa parpadeo de opacidad (`0.5 ↔ 1`) en vez del barrido del moodboard; llama la atención sobre sí mismo. | `nexo_screens.html:160-162` |
| B-06 | Métricas sin `font-variant-numeric: tabular-nums`: los dígitos bailan al actualizar. | [03 §1.2](./03_DESIGN_SYSTEM.md) |
| B-07 | Drawers sin focus trap, sin `Escape`, sin devolución de foco al disparador; overlay sin `role="dialog"`. 5 drawers distintos reimplementados a mano. | `CMP-034`, [09_ACCESSIBILITY.md](./09_ACCESSIBILITY.md) |
| B-08 | El drawer del dashboard anima con `spring` (`damping:30, stiffness:300`): rebote e imprevisibilidad temporal. | [08 §1](./08_MICRO_INTERACTIONS.md): solo `cubic-bezier(.22,1,.36,1)`, ≤300 ms |
| B-09 | Perfil: 3 columnas de contacto con 3 botones cada una (Guardar / Eliminar / Verificar) = 9 acciones compitiendo. | `DEC-011` (una primaria), `SCR-PRO-01` |
| B-10 | Operaciones vuelca **todos** los campos de golpe (hasta 5 selects + textarea) sin pasos, sin resumen y sin contexto. | `SCR-OPS-02`: "≤5 campos por paso, resumen antes de enviar" |
| B-11 | Topbar repite `school · rol` en cada pantalla y el `Section` repite el título: duplicación de encabezado. | `CMP-031`: "no duplica navegación" |
| B-12 | `text-display` (32 px) usado dentro de tarjetas de 28 px de alto en móvil; el número domina sobre la etiqueta que le da sentido. | [02 §1 DEC-015](./02_DESIGN_PRINCIPLES.md) |
| B-13 | Enrolamiento se titula "Matrícula" en pantalla y "Enrolamiento" en el menú. | `DEC-018` (consistencia semántica) |
| B-14 | Página en blanco entre módulos: no hay `PageHeader` con contexto (fecha, alcance, estado de datos). | `SCR-HOME-01`, `DEC-016` |

### P2 — deuda de sistema

- Cinco implementaciones distintas de drawer, tres de modal, dos de "select buscable", dos de toast.
- `Card` no soporta tono semántico ni estado de foco; `asAction` usa `<div onClick>` (no accesible por teclado).
- `EmptyState` sin variante de error ni de "sin permiso".
- No existe `CMP-107` (confirmación crítica) pese a que SOS y salidas lo exigen.
- No existe `CMP-105` visual (estado WhatsApp): se sustituye por un texto con spinner infinito.

---

## 3. Lectura psicológica del flujo de Operaciones

Estado actual: **catálogo de 10 tarjetas idénticas → formulario plano de 5 campos → banda de color**.

| Ley | Diagnóstico |
|---|---|
| **Hick** | 10 opciones sin agrupar en una sola vista. [02 §2](./02_DESIGN_PRINCIPLES.md) fija ≤7 sin agrupación. |
| **Fitts** | El destino (tarjeta) es grande, pero la acción real está a 5 campos de distancia sin progreso visible. |
| **Carga cognitiva** | El formulario pide simultáneamente grupo, estudiante, fecha, hora y mensaje. Cinco decisiones concurrentes sin secuencia. |
| **Goal Gradient** | No hay progreso: el usuario no sabe cuánto falta, así que no percibe avance. Abandono probable. |
| **Prevención de error** | Se envía sin resumen. Una citación masiva se dispara sin ver a quién llega. `DEC-013` incumplido. |
| **Peak-End** | El cierre —lo único que se recuerda— es una banda verde con jerga de cola de mensajería. Es el peor momento del flujo y es el último. |

**Alternativa implementada:** conversación guiada de tres tiempos —*Elegir* → *Confirmar* → *Resultado*— con una sola decisión dominante por paso, contexto persistente en el encabezado, resumen explícito de alcance antes de ejecutar y pantalla de resultado con estado real de entrega.

---

## 4. Elementos del Moodboard ausentes en el producto

| Elemento del moodboard | Estado | Acción |
|---|---|---|
| Línea de situación (`nx-sig`) | ausente | implementada como `SituationLine` en todos los Home |
| Conversación NEXO (`nx-chat`) | ausente | implementada en Notificaciones |
| Estado vacío con tile de 52 px (`nx-empty`) | ausente | `EmptyState` reconstruido |
| Indicador con tendencia (`nx-stat-trend`) | ausente | `StatCard` con estado textual + tendencia |
| Bloque de riesgo con borde izquierdo (`nx-risk`) | ausente | aplicado a Casos y a alertas del Home |
| Barras desplegables (`nx-bar`) | ausente | aplicadas al agrupamiento de Notificaciones e Informes |
| Skeleton con barrido | parpadeo | reemplazado |
| Ring de foco de 3 px al 12% en inputs | ausente | añadido |
| `accent-strong` / `danger-strong` en hover | ausente | añadidos como tokens |
| Cifras tabulares | ausente | activadas globalmente en métricas |
| Stepper (`nx-step-ind`) | parcial (solo enrolamiento) | extraído como primitivo |
| Timeline (`nx-tl`) | ausente | disponible como primitivo |

---

## 5. Decisiones de esta iteración

| ID | Decisión | Justificación |
|---|---|---|
| DEC-FE-01 | El sidebar pasa a `position: fixed` con scroll propio; el contenido compensa con padding lateral. | `CMP-030`; el pie de navegación debe ser alcanzable siempre. |
| DEC-FE-02 | Todo mensaje de servidor pasa por `humanizeError()` antes de renderizarse: se eliminan códigos entre paréntesis, prefijos HTTP, trazas y jerga de cola. | [01 §3](./01_PRODUCT_DESIGN_PHILOSOPHY.md). No modifica backend: es normalización de presentación. |
| DEC-FE-03 | El saludo desaparece del login y se traslada al Home (post-autenticación), como marca REC-001. | El login responde "¿cómo entro?", no "¿qué hora es?". |
| DEC-FE-04 | Auditoría deja de ser módulo de navegación; Informes absorbe la descarga de auditorías y consolidados en CSV, Excel y Word. `/auditoria` redirige a `/informes`. | `DEC-IA-03` + instrucción de producto. Sin cambios de backend: se consumen los mismos `GET /audit/*` y `/reports/preview`. |
| DEC-FE-05 | Rector y Coordinador incorporan **Permisos activos** como cuarta métrica. | Paridad de modelo mental con Docente. |
| DEC-FE-06 | Consultas **no se reimplementa** en esta iteración. Propuesta en [14_CONSULTAS_EXPERIENCE_PROPOSAL.md](./14_CONSULTAS_EXPERIENCE_PROPOSAL.md). | Instrucción explícita de producto. |
| DEC-FE-07 | Las tarjetas de acción pasan a `<button>` con foco visible; `Card` gana tono semántico cerrado (`neutral·accent·success·warning·danger`). | `DEC-020`, `DEC-031` (Von Restorff). |

---

## 6. Criterios de salida verificados

- Ninguna pantalla depende del color como única señal.
- Cada pantalla tiene un encabezado, una pregunta y una acción primaria.
- Toda superficie transitoria cierra con `Escape` y devuelve el foco.
- Ningún texto de la interfaz expone jerga técnica, códigos ni estados internos.
- Motion limitado a `opacity`/`transform`, ≤300 ms, con `prefers-reduced-motion` respetado.
