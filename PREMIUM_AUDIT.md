# PREMIUM AUDIT — NEXO WebApp

**Auditoría profunda de UI/UX · percepción premium, enterprise, moderna y de sistema crítico**

**Fecha:** 2026-08-04
**Alcance:** `WebApp/src` completo (shell, primitivos, patrones, 11 pantallas, tokens, motion, dark/light, responsive, accesibilidad, consistencia transversal).
**Método:** lectura íntegra del código fuente + comparación contra `UI_UX_PLAN/*` (01–13) + `nexo_moodboard.html` / `nexo_screens.html` + benchmark contra Stripe Dashboard, Linear, Notion, Vercel, Render, Supabase, GitHub, Cursor, Figma, Arc.
**Postura:** el objetivo es el **mejor producto posible**, no la fidelidad al `UI_UX_PLAN`. Cuando el plan y la percepción premium entran en conflicto, gana la percepción premium y se argumenta por qué.

> Nota de método: esta auditoría es **visual y experiencial**. No se ejecutó el servidor ni se midió contraste con instrumentos; los valores de contraste citados son estimaciones a partir de los tokens OKLCH declarados. Las afirmaciones funcionales (bugs) se verifican por lectura del código.

---

## FASE 0 — Resumen ejecutivo

NEXO WebApp **tiene un sistema de diseño correcto y una identidad ausente**. Los tokens (color OKLCH, radios, motion, tipografía) están bien declarados y son coherentes. La capa de componentes es funcional y relativamente consistente. Pero **el lenguaje visual no se ha aplicado**: hay una firma definida (la *línea de situación*), un header de página definido (`PageHeader`), una jerarquía definida (página → sección → bloque) y un sistema de charts permitido — y **ninguno se usa**.

El resultado es un producto que se ve **limpio, sobrio y funcional**, pero **no premium, no moderno y no crítico**. Pasa la prueba de “SaaS administrativo bien hecho” y falla la prueba de “producto insignia de una institución”. Cambiando el logo, cualquier pantalla podría pertenecer a cualquier EdTech genérico.

**Puntaje global:** 5.4 / 10 — *clean generic SaaS*, no *premium enterprise*.

**Las 5 heridas que sangran la percepción premium:**

1. **Jerarquía plana.** 18 repeticiones del mismo encabezado ad-hoc (`h-6 w-0.5 rounded-full bg-accent` + label) en lugar del `PageHeader`/`Section`/`BlockTitle` diseñado. Pantalla, sección y bloque son visualmente idénticos.
2. **Identidad ausente.** La *línea de situación* (firma NEXO, `04 §4`) aparece una sola vez. En su lugar, un **chat bubble con cola** (`NexoChatBubble`, `rounded-bl-xs`) se usa como firma — contradiciendo explícitamente `04 §4` (“no es banner ni chat decorativo”) y `01 §4` (“tampoco es un chatbot”).
3. **Exceso de color.** StatCard, Operation y Consultation **inundan** cada tarjeta con su tono semántico (acento/warning/danger). 5 KPIs = 3 bloques de color compitiendo. 12 comandos = 12 tiles de color. `04 §3.4` fija “acento < 10% de la vista”. La paleta premium se reserva el color para *señalizar*, no para *decorar*.
4. **Ausencia de datos con forma.** Cero charts, cero tendencias, cero sparklines. La prop `trend` de StatCard existe y nunca se pasa. Para un producto institucional de métricas, esto se lee como “no pudimos con los gráficos”. Stripe, Linear y Vercel resuelven esto con un sparkline de 24px de alto.
5. **Tablas CRUD.** Las tablas de ConsultationDrawer son HTML crudo (`min-w-[500px]` + `overflow-x-auto`), sin header sticky, sin sort, sin selección, sin densidad configurable, sin versión móvil por prioridades. `03 §CMP-022` prohíbe “scroll horizontal como única solución”. En móvil, la consulta es horizontal-scroll obligatorio.

**Lo que sí está bien (y hay que proteger):**

- Tokens OKLCH con dark mode por luminosidad, no por invert.
- Motion estricto: `cubic-bezier(.22,1,.36,1)`, 150–300 ms, solo `transform`/`opacity`.
- Skeleton con barrido (no parpadeo) — `index.css` `.nx-skeleton` correcto.
- `tabular-nums` en métricas y tiempos.
- `focus-visible` 2px outline + `ring-offset` + `reduced-motion` media query.
- `Drawer`/`Dialog`/`ConfirmDialog` unificados en `Overlay.jsx` con `useOverlay` (foco, Escape, scroll-lock, restore).
- `Card asAction` usa `<button>` (accesible por teclado).
- Bottom nav móvil + sidebar desktop + safe-area insets.
- PWA + Tauri deep links + font-scale accesible.

---

## FASE 1 — Análisis visual profundo

### 1.1 Jerarquía, spacing, grid, densidad

**Diagnóstico: jerarquía plana, densidad uniforme, grid correcto pero desaprovechado.**

- **Encabezado ad-hoc repetido 18 veces.** El patrón `<div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" /> + <p className="text-label">` aparece en `Sidebar`, `Dashboard` (×7), `Consultation` (×3), `Enrollment`, `Notifications`, `TrackingModal`, `Operation` (×2). Es el *único* dispositivo de jerarquía de la app. No distingue página de sección de bloque. `Surface.jsx` exporta `PageHeader`, `Section`, `BlockTitle`, `MetaItem`, `Divider` — **cero usos** en páginas. El sistema diseñado existe y fue ignorado.
- **Densidad uniforme.** Todas las superficies usan `Surface` (caja blanca, borde 1px, radius 12px, sin sombra por defecto). Una tarjeta de KPI, un formulario, una lista de notas y un panel de filtros son **la misma caja**. No hay elevación diferenciada (`shadow-low`/`medium`/`dialog` existen pero casi no se aplican). El ojo no sabe qué es contenedor principal y qué es secundario.
- **Grid correcto.** `grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4` se usa consistentemente en Consultation, Enrollment, Operation. Bien. Pero el grid es **siempre 3 columnas de cards idénticas** — no hay variación: no hay grids asimétricos, no hay bento, no hay filas largas + cards laterales. La composición es plantillal.
- **Spacing vertical generoso** (`space-y-6`, `space-y-8` entre secciones). Bien para respiración, mal cuando todo respira igual: no hay ritmo. `02 §1 DEC-010` pide “una pantalla, una pregunta” — la pregunta no se distingue porque nada se eleva sobre nada.

**Prueba “solo títulos” (04 §12):** falla. Ocultando el contenido, las 11 pantallas muestran la misma barra azul de 6px + label 13px. No se puede inferir en qué pantalla se está.

**Prueba “blur test”:** falla. Difuminando cualquier pantalla, todas son una columna de cajas blancas con bordes. No hay silueta distintiva.

### 1.2 Tipografía

**Diagnóstico: escala correcta, contraste tímido, una sola familia.**

- **Escala bien declarada** (`text-display` 32 / `h1` 24 / `h2` 20 / `h3` 16 / `body` 14 / `body-sm` 13 / `label` 13 / `caption` 12). Proporciones sanas.
- **Contraste insuficiente entre niveles.** `h1` 24/600 vs `h2` 20/620 vs `h3` 16/620 — la diferencia entre h1 y h2 es solo 4px y el peso es similar (600 vs 620). En la práctica, el título de página y el título de sección se leen casi iguales. Premium requiere un salto claro (p.ej. h1 28/700, h2 20/620).
- **KPI tímido.** `text-metric` = 24px, `text-metric-lg` = 30px. El moodboard fija `.nx-stat-num` en 28px. Stripe usa 32–40px para KPIs. El número es **lo que el usuario vino a ver** y está al tamaño de un subtítulo. Debería dominar.
- **Una sola familia: Inter.** `03 §1.2` manda Inter. Inter es correcta, neutra, muy legible — y es la fuente por defecto de todo SaaS generado por IA en 2026. No aporta identidad. Para “producto insignia” una grotesk distintiva (Geist, Satoshi, Söhne, ABC Diatype) o un acento monoespaciado en métricas elevaría la percepción sin romper la legibilidad. **Propuesta:** mantener Inter como base, añadir un mono (JetBrains Mono / Geist Mono) para métricas, IDs y timestamps — el acento mono es la firma tipográfica más barata y efectiva de los dashboards premium (Vercel, Linear, GitHub).
- **`font-feature-settings: 'tnum'`** declarado en `body` — bien. Pero `.nx-tnum` se aplica manualmente en StatCard; los números de las tablas y los timestamps no lo usan y “bailan” al actualizar.

### 1.3 Sistema de color, light/dark, contraste

**Diagnóstico: tokens excelentes, aplicación excesiva, dark mode tímido.**

- **Light mode: muy bueno.** Canvas `oklch(98% 0.006 245)` con tinte azul frío mínimo, surface `99.3%`, texto `23%`. Calmo, institucional, no hospitalario. El tinte sutil diferencia de “blanco puro genérico”.
- **Dark mode: correcto técnicamente, accent invisible.** Canvas `oklch(18% 0.008 245)`, surface `22%`, accent `oklch(65% 0.06 245)`. La luminosidad sube (bien) pero la croma del accent baja a **0.06** — el azul se vuelve gris azulado. Sobre un canvas al 18% el accent al 65%/0.06 se lee como “gris claro sobre gris oscuro”. El comentario en `index.css` (“Linear/GitHub Dark vibe”) confirma la intención, pero Linear mantiene su accent perceptible (croma 0.13+). Este accent no señaliza, se camufla.
- **Exceso de color en superficies.** StatCard inunda la tarjeta completa con `bg-[var(--nx-surface-accent)]` + `border-[var(--nx-border-accent)]`. Operation hace lo mismo con sus 12 comandos. Consultation con sus 3 tonos. Resultado: pantallas con 3–12 bloques de color compitiendo. `04 §3.4` fija “acento < 10% de la vista”. La paleta premium (Stripe, Linear, Vercel) **reserva** el color para señalizar estado en un punto pequeño (dot, borde 2px, icono), no para pintar la caja. La regla empírica: si una captura en blanco y negro pierde información, hay demasiado color.
- **RiskBadge roto por diseño.** Mapea `bajo`/`normal`/`medio`/`alto` **todos** a `warning` (naranja); solo `critico` → `danger`. “Riesgo bajo” se ve naranja. Semánticamente incorrecto y peligroso en un producto de seguimiento de riesgo. Debería ser: `bajo` → neutral/success, `medio` → warning, `alto`/`critico` → danger.
- **Contraste estimado.** Texto `23%` sobre canvas `98%`: ~14:1 (AAA). Texto-muted `48%` sobre `98%`: ~4.8:1 (AA para texto normal, borderline para small). Sobre surface-subtle `96%`: ~4.5:1. Acento `48%/0.115` sobre canvas `98%`: ~4.2:1 — **borderline AA para texto**, no usar accent para body text (no se usa, correcto). En dark, text-muted `70%` sobre canvas `18%`: ~6:1 (AA+). Accent `65%/0.06` sobre canvas `18%`: ~3.2:1 — **por debajo de AA para texto**; solo sirve como borde/icono, no como texto. Bien usado, mal si se usa en links.

### 1.4 Ritmo visual, hover/focus, animaciones, microinteracciones

**Diagnóstico: motion ejemplar, microinteracciones correctas, una violación.**

- **Motion: sobresaliente.** `cubic-bezier(.22,1,.36,1)` en todo, 150–250 ms, solo `transform`/`opacity`. Drawer 250ms x:100%, Dialog 200ms scale 0.97 + y:8, page transition 200ms opacity+y:6, SearchableSelect dropdown 150ms. `reduced-motion` media query que colapsa todo a instantáneo. Esto es referencia-calidad.
- **Hover: correcto y discreto.** Cards `hover:shadow-medium`, bordes `hover:border-accent`, iconos `hover:text-text`. Fiel a `08 §3`.
- **Focus-visible: bien.** `outline 2px ring-accent offset 2px` en IconButton, Button, SearchableSelect. `Card asAction` hereda focus de `<button>`. Pero **StatCard clickable** en Dashboard usa `<button>` con `focus-visible:ring` — bien. Las filas de tabla `hover:bg-subtle` **no tienen focus-visible** — navegación por teclado en tablas invisible.
- **Skeleton: correcto.** `.nx-skeleton` usa barrido lineal 1.6s, no parpadeo. Fiel al moodboard. Bien.
- **Violación: `nx-blink` notification dot.** `animation: nx-blink 1.8s infinite` con `scale(1.5)` y `opacity 0.5↔1`. `04 §3.5` (“sin parpadeo”) y `08 §1` (“sin pulso/parpadeo decorativo”) lo prohíben. Un dot rojo parpadeante es exactamente el cliché que el doc proscribe. Debe ser un dot estático o, si es urgente, un pulso de *halo* (box-shadow expandiéndose) no de opacidad.
- **Press feedback:** `nx-pressable` con `active:scale-[0.98]` — bien, solo en targets grandes.
- **OperationResult `animate-seal`:** check con sello animado en éxito — `08 §3` dice “Success: confirmación textual; no depender de check animado”. La animación existe pero hay texto también. Aceptable, pero el sello es decorativo.

### 1.5 Cards, tablas, inputs, sidebar, dashboard, métricas, operaciones, notificaciones, perfil, login, onboarding

- **Cards:** `Card` base correcta (radius 12, border 1, sin sombra). `asAction` accesible. **Pero no soporta tono semántico** — el tono se aplica externamente con className override, generando 18 variantes ad-hoc. Falta un `Card tone="warning|danger|accent"` canónico.
- **Tablas:** HTML crudo, `min-w-[500px]`, `overflow-x-auto`, sin sticky header, sin sort, sin densidad, sin versión móvil. `03 §CMP-022` exige “encabezados, versión móvil por prioridades, alternativa de detalle”. En móvil la consulta es scroll-horizontal obligatorio. **Por debajo del estándar premium.**
- **Inputs:** `Input`/`Select`/`SearchableSelect`/`Textarea` consistentes (h-12, radius 8, border, focus ring 3px). `SearchableSelect` bien hecho (search sticky, multi, clearable, aria). **Bug:** dropdown absolute sin portal → dentro de un Drawer con `overflow-y-auto` se **clips**. En ConsultationDrawer los filtros SearchableSelect pueden no mostrar su lista completa.
- **Sidebar:** 220px desktop, bottom-nav móvil. Estructura correcta. **Duplicación:** NavItem se renderiza dos veces (mobile secondary + desktop full) con markup idéntico → riesgo de drift. El pie (tema, perfil, cerrar sesión) en desktop está al final del scroll, no sticky — en viewports cortos se desplaza fuera. `CMP-030` “Wide fijo” exige sticky. Fallo heredado de T7-A-01, no corregido.
- **Dashboard:** KPIs en 3 mini-grids (2+2+1) por tono. Visualmente fragmentado. Sin charts. Sin tendencia. `NexoChatBubble` como “novedades” — chat metaphor. `ScheduleTask` (coordinador) es un panel warning gigante que domina el viewport.
- **Métricas:** StatCard con `tone`, `statusText`, `tabular-nums`. Bien diseñado. Pero `trend` nunca se pasa. Sin sparkline. El número (24px) es pequeño para un KPI.
- **Operaciones:** 12 comandos en grid 3-col, todos color-drenched. Hick: 12 sin agrupar (límite 7). El formulario es **plano** (todos los campos de golpe, hasta 5 selects + textarea) — `SCR-OPS-02` exige “≤5 campos por paso, resumen antes de enviar”. No hay pasos (el `Stepper` existe pero solo muestra 1 paso). No hay resumen previo. `DEC-013` incumplido. El resultado usa `OperationResult` (bien) pero el camino hasta ahí es un muro de formulario.
- **Notificaciones:** Lista de `NexoChatBubble` con timestamp. Chat metaphor. Sin filtros, sin agrupar por día, sin marcar-leído masivo, sin búsqueda. Funcional pero CRUD.
- **Perfil:** 1045 líneas, 6+ Dialog/Drawer locales anidados. Sección de contacto: 3 filas × (eye-toggle + “Cambiar” link) = 6 acciones en una card. “Configuración de horarios” → botón “Ajustes” → Drawer → botón “Editar” → `OnboardingScheduleModal` (754 líneas, full-screen blocking). **3 superficies anidadas para un setting.** `DEC-011` (una acción primaria) incumplido en la card de contacto.
- **Login:** Logo `h-28` centrado + card blanca. Sin escena, sin identidad institucional. El saludo “Buenas tardes / Inicia tu jornada en NEXO” **antes** de autenticar — `01 §9 REC-001` lo prohíbe (“el saludo es posterior al login”). Es la primera impresión del producto y es la primera pantalla que falla el swap test.
- **Onboarding:** `OnboardingScheduleModal` es un modal full-screen **bloqueante** sin escape ni back. Se renderiza *en lugar de* Layout cuando `onboarding_required`. Para un primer arranque es un muro. Premium onboarding (Linear, Vercel, Notion) es progresivo, con escape y “lo hago después”.

### 1.6 Responsive y cross-screen

- **Mobile:** bottom-nav 56px + safe-area. Bien. Pero tablas = scroll horizontal obligatorio. SearchableSelect dropdown se clipa en drawers. Dashboard teacher: 5 KPIs en 2+2+1 con la danger card en una versión compacta separada — irregular.
- **Desktop:** sidebar 220px + content `lg:pl-[220px]`. Bien. Topbar 72px (token dice 64px — mismatch).
- **Tablet:** no hay breakpoint específico; `md:` cubre. Las grids 3-col en `lg:` colapsan a 2 en `md:` — bien.
- **Zoom 400% / reflow:** no verificado en runtime. Los tokens usan rem y el `font-scale` accesible ayuda. Pero `min-w-[500px]` en tablas rompe reflow a 320px.

---

## FASE 2 — Comparación con referentes premium

### 2.1 Por qué se sienten premium (principios extraídos)

| Referente | Principio premium que NEXO debería adoptar |
|---|---|
| **Stripe Dashboard** | Color reservado a señalizar (dot, borde). KPIs grandes (32px+) con sparkline 24px. Tablas densas con sticky header, sort, densidad configurable. Empty states con pequeña ilustración propia. |
| **Linear** | Near-black canvas, accent perceptible incluso en dark. Tipografía ultra-tight. Keyboard-first (cmd+k). Row hover mínimo. Cero decoración. |
| **Notion** | Composición por bloques con claridad. Jerarquía tipográfica fuerte (h1 claramente mayor que h2). Calma. |
| **Vercel** | Acento **monoespaciado** en métricas/IDs/timestamps — firma tipográfica instantánea. Alto contraste B/W + navy. Geometría estricta. |
| **Render / Supabase** | Limpio pero genérico — **este es el nivel actual de NEXO**. No es el techo. |
| **GitHub** | Tablas densas con filtros persistentes, status pills, sticky header. Empty states con personalidad. |
| **Cursor / Figma / Arc / Framer** | Identidad visual distintiva (no se confunden con ningún otro). NEXO no la tiene. |

### 2.2 Lo que NEXO ya comparte con los premium (proteger)

- Motion estricto (Linear, Vercel).
- Canvas con tinte sutil, no blanco puro (Stripe, Linear).
- `tabular-nums` en métricas (Stripe, GitHub).
- Skeleton con barrido (Linear, Vercel).
- Focus-visible limpio (todos).
- Dark mode por luminosidad (Linear, GitHub).

### 2.3 Lo que separa a NEXO de los premium (cerrar)

- **Identidad**: falta firma visual recurrente (Linear tiene el issue pill, Vercel el mono, Stripe el gradiente sutil en headers).
- **Densidad de datos**: NEXO no tiene charts; todos los premium sí.
- **Jerarquía**: NEXO es plano; los premium tienen 3 niveles claros (página → sección → bloque) con tratamiento distinto.
- **Color**: NEXO sobre-pinta; los premium señalizan.
- **Tablas**: NEXO es HTML crudo; los premium son componentes con sticky/sort/density.
- **Keyboard**: NEXO no tiene cmd+k; Linear, Vercel, Stripe, GitHub sí.

---

## FASE 3 — Detección y clasificación de problemas

Formato: **ID · Qué · Por qué duele UX/visual · Solución · Prioridad.**

### P0 — rompen la percepción premium

**P0-01 · Identidad ausente / firma no desplegada.**
La *línea de situación* (`04 §4`, `nx-signature-line` en moodboard) aparece una sola vez (`Dashboard.jsx:614`). En su lugar se usa `NexoChatBubble` (cola de chat) como firma recurrente en Notifications, Dashboard stream, Operation empty state. **Duele:** el producto no se reconoce; falla el swap test; contradice la filosofía explícita (“no es chat”). **Solución:** reemplazar `NexoChatBubble` por `SituationLine` como elemento recurrente de “novedad/evento”; reservar el chat bubble exclusivamente para mensajería WhatsApp bidireccional acudiente. **Prioridad:** P0 — es la herida de identidad.

**P0-02 · Jerarquía plana / PageHeader no usado.**
`Surface.jsx` exporta `PageHeader`, `Section`, `BlockTitle` — 0 usos. 18 encabezados ad-hoc idénticos. **Duele:** no se distingue pantalla de sección de bloque; falla prueba “solo títulos”; el producto se lee como plantilla. **Solución:** adoptar `PageHeader` en toda página (con eyebrow, título, meta de fecha/alcance/estado de datos); `Section` para bloques dentro de página; `BlockTitle` para sub-bloques. Eliminar la barra azul ad-hoc. **Prioridad:** P0 — afecta toda la app.

**P0-03 · Exceso de color en StatCard / Operation / Consultation.**
Cada tarjeta se inunda con su tono semántico. 5 KPIs = 3 bloques de color; 12 comandos = 12 tiles de color. **Duele:** viola `04 §3.4` (<10% acento); el color deja de señalizar y compite; se lee “decorativo” no “premium”. **Solución:** StatCard neutra (surface blanca) con solo el icono/dot en el tono; Operation agrupar por categoría (Asistencia / Permisos / Críticos) con un solo tile de color por grupo, no por comando; Consultation mantener cards neutras, color solo en el borde izquierdo de 3px (como `nx-risk-indicator` del moodboard). **Prioridad:** P0.

**P0-04 · Tablas CRUD sin tratamiento premium.**
HTML crudo, `min-w-[500px]`, `overflow-x-auto`, sin sticky/sort/densidad/móvil. **Duele:** en móvil scroll horizontal obligatorio (`03 §CMP-022`); se lee “no invertimos en datos”; por debajo de Stripe/GitHub. **Solución:** componente `Table` con header sticky, sort indicator, densidad sm/md, versión móvil por “card de prioridad” (los 3 campos clave) + “ver detalle”. **Prioridad:** P0 — afecta Consultation, Auditoría, Reportes.

**P0-05 · Ausencia total de visualización de datos.**
Cero charts, cero sparklines, cero tendencias. `trend` prop de StatCard nunca se pasa. **Duele:** un producto institucional de métricas sin gráficas se lee “CRUD no analytics”; falla el North Star de “detectar situaciones” (las situaciones requieren tendencia, no solo valor puntual). **Solución:** sparkline 24px en StatCard (últimos 7 días); mini-área en Dashboard rector para asistencia institucional; barras comparativas por grupo. Librería: `recharts` o SVG a mano (sin dependencia pesada). **Prioridad:** P0.

**P0-06 · Login sin identidad institucional.**
Logo + card blanca + saludo pre-auth. **Duele:** primera impresión = genérica; saludo vacío viola `01 §9`; falla swap test. **Solución:** login con escena institucional sutil (patrón de la línea de situación como fondo, o foto del colegio si existe), sin saludo personal pre-auth; copy factual (“Acceso institucional NEXO”). **Prioridad:** P0 — es la puerta.

**P0-07 · Operation: 12 comandos sin agrupar + formulario plano.**
Grid 3-col de 12 tiles color-drenched + formulario de 5 campos de golpe sin pasos ni resumen. **Duele:** Hick (12 > 7); `DEC-013` (sin resumen previo); `SCR-OPS-02` (≤5 campos por paso); una citación masiva se dispara sin ver destinatarios. **Solución:** agrupar comandos en 3–4 categorías (Asistencia, Permisos y Salidas, Críticos, Académico); formulario en pasos con `Stepper` real (no decorativo); resumen explícito con lista de destinatarios antes de ejecutar. **Prioridad:** P0 — flujo crítico del producto.

**P0-08 · RiskBadge semánticamente roto.**
`bajo`/`normal`/`medio`/`alto` → todos `warning`. **Duele:** “riesgo bajo” se ve naranja = alarma falsa; en un producto de seguimiento de riesgo esto es peligroso y destruye confianza. **Solución:** `bajo` → neutral/success, `medio` → warning, `alto`/`critico` → danger. **Prioridad:** P0 — bug de seguridad perceptual.

### P1 — reducen calidad significativamente

**P1-01 · Dark mode accent imperceptible.** Accent dark `oklch(65% 0.06 245)` = gris azulado. **Duele:** el accent no señaliza en dark; links/iconos pierden contraste (~3.2:1 < AA texto). **Solución:** subir croma a 0.10–0.13 (Linear-level). **Prioridad:** P1.

**P1-02 · NexoChatBubble como firma.** Cola de chat (`rounded-bl-xs`) en Notifications, Dashboard, Operation. **Duele:** contradice `04 §4` y `01 §4`; metáfora de chatbot en producto que no es chat. **Solución:** reemplazar por `SituationLine` (línea de situación). **Prioridad:** P1 (solapado con P0-01; aquí el alcance es eliminar el bubble, no solo añadir la línea).

**P1-03 · Sidebar pie no sticky.** En viewports cortos el pie (tema/perfil/logout) se desplaza fuera de viewport. **Duele:** `CMP-030` “Wide fijo” incumplido; el árbol rector deja de ser rector al hacer scroll. **Solución:** sidebar flex column con `flex-1` scrollable + pie `shrink-0` sticky al fondo. **Prioridad:** P1.

**P1-04 · KPI tímido (24px).** `text-metric` 24px. **Duele:** el número es lo que el usuario vino a ver y está al tamaño de un subtítulo; no domina. **Solución:** 32px mínimo para KPIs principales, 40px para el KPI hero. **Prioridad:** P1.

**P1-05 · `nx-blink` notification dot.** Parpadeo de opacidad + scale. **Duele:** viola `04 §3.5` y `08 §1`; cliché de app ansiosa. **Solución:** dot estático; si urgente, halo pulsante (box-shadow expandiéndose) no opacidad. **Prioridad:** P1.

**P1-06 · SearchableSelect sin portal.** Dropdown absolute se clipa en Drawer con overflow. **Duele:** filtros dentro de ConsultationDrawer pueden no mostrar lista completa; bug funcional. **Solución:** portal a `document.body` con positioning por `floating-ui` o portal manual + `position: fixed`. **Prioridad:** P1.

**P1-07 · Tablas sin focus-visible en filas.** `hover:bg-subtle` pero sin focus. **Duele:** navegación por teclado invisible; WCAG 2.2. **Solución:** `focus-visible:ring` en fila o wrapper `<button>` por fila. **Prioridad:** P1.

**P1-08 · Perfil anidado (3 superficies para 1 setting).** Horarios → Ajustes (Drawer) → Editar (Modal full-screen). **Duele:** profundidad excesiva; `DEC-011` (una primaria) incumplido en card de contacto (6 acciones). **Solución:** unificar edición de horarios en un Drawer con secciones, no modal full-screen; card de contacto con una acción primaria “Editar” que abre un Drawer con todos los campos. **Prioridad:** P1.

**P1-09 · OnboardingScheduleModal bloqueante sin escape.** Full-screen, no back, no “después”. **Duele:** primer arranque = muro; abandono probable. **Solución:** onboarding progresivo dentro del Layout, con “Completar más tarde” y resumen de lo pendiente en el dashboard. **Prioridad:** P1.

**P1-10 · Topbar 72px vs token 64px.** Mismatch. **Duele:** inconsistencia token/impl; el topbar es más alto de lo diseñado. **Solución:** alinear a 64px o actualizar token. **Prioridad:** P1.

**P1-11 · Consultation: 3 niveles visualmente idénticos.** Módulos y submódulos = mismo grid 3-col + misma card. **Duele:** el usuario no sabe en qué nivel está sin mirar la URL/back. **Solución:** nivel 1 = grid de módulos con tratamiento hero (icono grande, título h2, descripción); nivel 2 = lista densa de submódulos (no grid de cards); nivel 3 = drawer. **Prioridad:** P1.

**P1-12 · Saludo pre-auth en Login.** “Buenas tardes / Inicia tu jornada”. **Duele:** `01 §9 REC-001` prohíbe saludo pre-auth; promesa vacía. **Solución:** copy factual sin saludo. **Prioridad:** P1.

### P2 — detalles

**P2-01 · `style.css` legacy muerto.** `#app { max-width:1280px; text-align:center }` + `button { background:#1a1a1a }`. No se importa en `main.jsx` (solo `index.css`). Confuso, riesgo de import accidental. **Solución:** eliminar. **Prioridad:** P2.

**P2-02 · `react-select` en dependencias, no usado.** `SearchableSelect` es custom. **Solución:** `npm uninstall react-select`. **Prioridad:** P2.

**P2-03 · Inter como única familia.** Neutra pero genérica. **Solución:** añadir mono (Geist Mono / JetBrains Mono) para métricas, IDs, timestamps — firma tipográfica barata y efectiva. **Prioridad:** P2.

**P2-04 · Iconografía repetida.** `AlertTriangle` aparece en danger cards, empty states, situation lines, confirm. Fatiga. **Solución:** auditar uso de iconos; diversificar (ShieldAlert, Siren, OctagonAlert, TriangleAlert ya existen en lucide). **Prioridad:** P2.

**P2-05 · `tabular-nums` no aplicado en tablas/timestamps.** Sólo en StatCard. **Solución:** aplicar `.nx-tnum` a todas las celdas numéricas y timestamps. **Prioridad:** P2.

**P2-06 · Sin cmd+k / command palette.** Para uso intensivo diario. **Solución:** palette con navegación + acciones frecuentes. **Prioridad:** P2.

**P2-07 · EmptyState sin personalidad.** Tile 52px + texto. **Solución:** ilustración tipográfica sutil o mini-diagrama propio (no genérico). **Prioridad:** P2.

**P2-08 · Sin skeleton de shell.** Al cargar, solo el logo pulsa; el shell no esqueletoniza. **Solución:** skeleton de sidebar + topbar. **Prioridad:** P2.

**P2-09 · Densidad de Dashboard teacher irregular.** 2+2+1 con danger card en variante compacta separada. **Solución:** grid unificado 5-col en desktop, 2-col en móvil, todos misma card. **Prioridad:** P2.

**P2-10 · `font-feature-settings` global pero sin `cv02`/`cv11` verificados.** Inter requiere estos features para alternates. **Solución:** verificar render. **Prioridad:** P2.

### P3 — pulishing

**P3-01 ·** Doble NavItem markup en Sidebar (mobile + desktop) — riesgo de drift. Unificar.
**P3-02 ·** `OperationResult` `animate-seal` decorativo — mantener pero no depender de él.
**P3-03 ·** Login 2FA: botón “Reenviar/Volver” con doble función según estado — separar.
**P3-04 ·** `SituationLine` apenas usado — desplegar como firma.
**P3-05 ·** `Card` sin prop `tone` canónico — añadir.
**P3-06 ·** Sin filtros persistentes en Consultation (se pierden al volver).
**P3-07 ·** Notificaciones sin marcar-leído masivo ni agrupación por día.
**P3-08 ·** `h1` 24px vs `h2` 20px — salto tímido; subir h1 a 28px.
**P3-09 ·** Sin breadcrumbs en niveles profundos (Consultation nivel 3).
**P3-10 ·** `text-display` 32px casi no se usa — reservar para KPI hero.

---

## FASE 4 — Mejoras propuestas (aunque contradigan el UI_UX_PLAN)

### 4.1 Imposición de jerarquía de 3 niveles (contradicción parcial con la práctica actual)

**Propuesta:** toda pantalla debe tener exactamente:
1. **PageHeader** (eyebrow monoespaciado + título h1 28px + meta line con fecha/alcance/estado de datos + acción primaria opcional a la derecha).
2. **Section** (título h2 20px + optional subtítulo, separado por `border-b` no por barra azul).
3. **BlockTitle** (h3 16px, sin barra, dentro de una section).

Eliminar la barra azul ad-hoc de 6px como dispositivo de jerarquía. Reemplazarla por:
- **Eyebrow monoespaciado** (12px, accent, uppercase, letter-spacing 0.06em) sobre títulos de página — es la firma tipográfica.
- **Borde izquierdo de 3px** en `SituationLine` y `RiskBadge` (ya en moodboard) — es la firma estructural.

**Por qué contradice el plan:** el plan define `Section` con título 22px; propongo 20px para aumentar el salto con h1. El plan no exige eyebrow mono; lo propongo para identidad.

### 4.2 Reducción de color a señalización (contradicción con StatCard/Operation actuales)

**Propuesta:** regla estricta — el color semántico (accent/warning/danger) **solo** puede aparecer en:
- Dot de 8px (StatusDot).
- Borde izquierdo de 3px (SituationLine, RiskBadge, card de alerta).
- Icono de 16–20px dentro de un tile neutro.
- Texto de badge/pill.
- Fondo subtle al 8–12% **solo** en hover/selected, no en estado default.

**Prohibido:** card completa con `bg-surface-accent` + `border-accent` en estado default. StatCard vuelve a neutra; el tono vive en el icono y el dot.

**Por qué contradice el plan:** `03 §CMP-027` permite “tono psicológico” en StatCard; propongo retirarlo porque el exceso de color destruye la calma premium.

### 4.3 Charts mínimos (extiende el plan)

**Propuesta:** añadir `CMP-026b Sparkline` (SVG, 24px alto, 80px ancho, sin ejes, solo línea + área sutil) en StatCard; `CMP-026c MiniArea` (120×40) en Dashboard rector para asistencia institucional 7 días. Sin librería pesada: SVG a mano con `path` + `linearGradient`.

**Por qué extiende el plan:** `03 §CMP-026` permite charts pero no los especifica para KPIs. La ausencia total de visualización es la brecha premium más grande.

### 4.4 Tabla premium (extiende el plan)

**Propuesta:** `CMP-022b DataTable` con:
- Header sticky, sort indicator, densidad sm/md/lg.
- Versión móvil: card de prioridad (3 campos clave) + “ver detalle”.
- Selección de fila opcional.
- Focus-visible en fila.
- Virtualización opcional para >100 filas.

### 4.5 Command palette (extiende el plan)

**Propuesta:** `CMP-040 CommandPalette` (cmd+k / ctrl+k) con:
- Navegación a cualquier módulo.
- Acciones frecuentes (“Citar acudiente de [grupo]”, “Iniciar caso de [estudiante]”).
- Búsqueda de estudiantes.
- Toggle tema.

El plan menciona Raycast como referencia pero no lo especifica. Para uso intensivo diario es la diferencia entre “app cómoda” y “app premium”.

### 4.6 Login con escena (contradicción con el plan implícito)

**Propuesta:** login con:
- Panel izquierdo (desktop): patrón de líneas de situación animadas sutilmente (la firma NEXO como wallpaper), sobre canvas tintado. En móvil, solo header con logo + tagline factual.
- Panel derecho: formulario, sin saludo personal, copy “Acceso institucional”.
- 2FA con campo único semántico (pegar permitido).

**Por qué contradice:** el plan `SCR-AUTH-01` dice “panel único 320–520px; no split hero en móvil”. Propongo split en desktop (no móvil) porque la primera impresión de un producto insignia no puede ser una card centrada.

### 4.7 Onboarding progresivo (contradicción con el plan implícito)

**Propuesta:** eliminar `OnboardingScheduleModal` full-screen bloqueante. En su lugar:
- Primer login → dashboard con banner `SituationLine` warning “Configura los horarios del colegio” + CTA “Configurar ahora” / “Lo hago después”.
- CTA abre un Drawer (no modal full-screen) con el flujo por jornadas.
- Pendiente persiste en el dashboard hasta completar.

**Por qué contradice:** el plan implica onboarding bloqueante para garantizar configuración. Propongo progresivo porque el bloqueo genera abandono y el dashboard con recordatorio es igual de efectivo y más premium.

### 4.8 Acento monoespaciado (extiende el plan)

**Propuesta:** añadir `font-mono` (Geist Mono o JetBrains Mono) aplicado a:
- Eyebrows de PageHeader.
- Métricas en StatCard (el número).
- IDs de estudiante, tracking, dispositivo.
- Timestamps en listas y tablas.
- Slugs de módulo en Consultation (modo debug).

**Por qué:** es la firma tipográfica más barata y efectiva de los dashboards premium (Vercel, GitHub, Linear). Inter sigue como base. No rompe legibilidad.

---

## FASE 5 — Evaluación de identidad visual

| Pregunta | Respuesta | Evidencia |
|---|---|---|
| ¿Se siente premium? | **No.** Se siente limpio y funcional, como Render/Supabase. No como Stripe/Linear. | Sin charts, sin firma, color excesivo, jerarquía plana. |
| ¿Se siente moderna? | **A medias.** Motion y tokens son 2026; composición y ausencia de charts son 2021. | Motion correcto; CRUD sin visualización. |
| ¿Se siente enterprise? | **No.** Enterprise = densidad de datos, tablas serias, filtros persistentes, cmd+k. NEXO no los tiene. | Tablas HTML crudo, sin palette, sin densidad configurable. |
| ¿Se siente de sistema crítico? | **No.** Crítico = señalización precisa de riesgo. RiskBadge roto; SOS sin distinción visual fuerte. | RiskBadge bajo=warning; SOS es solo un botón rojo más. |
| ¿Se reconoce al instante? | **No.** Falla swap test. | Sin firma; chat bubble genérico. |
| ¿Transmite calma institucional? | **Sí.** Canvas tintado, motion sobrio, sin ruido. | Tokens light mode. |
| ¿Transmite urgencia cuando hace falta? | **A medias.** El color existe pero saturado pierde fuerza. | 12 tiles rojos/anaranjados compitiendo. |
| ¿Invita a tocarla? | **Sí, pero no a explorar.** Hover correcto pero sin descubrimiento (sin palette, sin atajos). | Motion bueno; ausencia de cmd+k. |

---

## FASE 6 — Calificación (1–10)

| Dimensión | Score | Justificación |
|---|---:|---|
| Dirección artística | **5** | Coherente pero genérica; sin firma; chat bubble contradice filosofía. |
| UX | **6** | Flujos correctos; Operation 12-card + formulario plano + Perfil anidado degradan. |
| UI | **6** | Tokens buenos; composición plana; tablas CRUD. |
| Accesibilidad | **7** | focus-visible, aria, reduced-motion; falla focus en filas, SearchableSelect clipping, contraste accent dark. |
| Consistencia | **4** | 18 encabezados ad-hoc; PageHeader sin usar; 3 niveles de Consultation idénticos; doble NavItem. |
| Sistema de diseño | **7** | Tokens excelentes; adopción pobre (PageHeader, Section, Charts no usados). |
| Arquitectura visual | **5** | Sin niveles de jerarquía; sin firma; grid siempre 3-col idéntico. |
| Jerarquía | **4** | Plano; h1≈h2≈h3; barra azul como único dispositivo. |
| Tipografía | **6** | Escala correcta; contraste tímido; una sola familia; KPI pequeño. |
| Color | **5** | Tokens buenos; exceso de aplicación; dark accent imperceptible; RiskBadge roto. |
| Modo oscuro | **6** | Técnico correcto; accent al 0.06 croma = invisible. |
| Modo claro | **7** | Canvas tintado, calmo, institucional. |
| Responsive | **6** | Bottom-nav bueno; tablas scroll-only; SearchableSelect clipping; topbar mismatch. |
| Sensación premium | **5** | Limpio pero genérico; sin “esto costó mucho”. |
| Identidad | **3** | Falla swap test; sin firma desplegada; chat metaphor contradice doc. |

**Promedio: 5.4 / 10.**

---

## FASE 7 — Plan de acción priorizado

### Sprint 1 — identidad y jerarquía (ataca P0-01, P0-02, P1-02, P1-12)
1. Desplegar `SituationLine` como firma recurrente; eliminar `NexoChatBubble` de Notifications/Dashboard/Operation (reservar solo para WhatsApp bidireccional).
2. Adoptar `PageHeader` + `Section` + `BlockTitle` en las 11 pantallas; eliminar la barra azul ad-hoc.
3. Añadir eyebrow monoespaciado (introducir `font-mono`) en PageHeader.
4. Login: eliminar saludo pre-auth; añadir escena institucional en desktop.

### Sprint 2 — color y datos (ataca P0-03, P0-04, P0-05, P1-04)
5. StatCard neutra + color solo en icono/dot; subir KPI a 32px.
6. Operation: agrupar 12 comandos en 3–4 categorías; formulario en pasos con `Stepper` real + resumen previo.
7. Consultation: nivel 1 hero, nivel 2 lista densa, nivel 3 drawer (no 3 grids idénticos).
8. `DataTable` con sticky/sort/densidad/móvil-card.
9. `Sparkline` en StatCard; `MiniArea` en Dashboard rector.

### Sprint 3 — bugs y dark mode (ataca P0-08, P1-01, P1-03, P1-05, P1-06, P1-07, P1-10)
10. RiskBadge: mapeo semántico correcto.
11. Dark accent: subir croma a 0.10–0.13.
12. Sidebar pie sticky.
13. `nx-blink` → dot estático o halo pulsante.
14. SearchableSelect con portal.
15. Focus-visible en filas de tabla.
16. Topbar 64px (alinear token).

### Sprint 4 — perfil, onboarding, polishing (ataca P1-08, P1-09, P2-*, P3-*)
17. Perfil: unificar edición en Drawers; card de contacto con una primaria.
18. Onboarding progresivo (eliminar modal full-screen bloqueante).
19. Eliminar `style.css` y `react-select` muertos.
20. Command palette (cmd+k).
21. `tabular-nums` en tablas/timestamps.
22. Diversificar iconografía.

### Métricas de aceptación (cómo saber que llegamos a premium)
- **Swap test:** 5 capturas de NEXO sin logo → 4/5 testers identifican “es NEXO” por la línea de situación + eyebrow mono.
- **Solo títulos test:** 4/5 testers explican propósito y próximo paso en ≤5s ocultando contenido.
- **Blur test:** silueta de Dashboard distingue 3 niveles (header hero / KPIs / stream).
- **Color test:** captura en B/N pierde <10% de información.
- **Data test:** toda métrica con tendencia (sparkline o texto de delta).
- **Keyboard test:** cmd+k llega a cualquier módulo en ≤2 teclas; tab navega tablas con focus visible.
- **Dark test:** accent perceptible a 1m de distancia en dark mode.

---

## Apéndice A — Inventario de archivos clave revisados

**Tokens y configuración:** `tailwind.config.js`, `src/index.css`, `index.html`, `package.json`.
**Shell:** `layout/Layout.jsx`, `layout/Sidebar.jsx`, `App.jsx`.
**Primitivos:** `components/ui/Button.jsx`, `Card.jsx`, `Input.jsx`, `Select.jsx`, `SearchableSelect.jsx`, `Surface.jsx` (PageHeader/Section/BlockTitle/MetaItem/Divider), `Skeleton.jsx`, `Badge.jsx`, `EmptyState.jsx`, `IconButton.jsx`, `Overlay.jsx` (Drawer/Dialog/ConfirmDialog/useOverlay), `Stepper.jsx`.
**Patrones:** `components/patterns/StatCard.jsx`, `SituationLine.jsx`, `RiskBadge.jsx`, `NexoChat.jsx`, `OperationResult.jsx`, `StatusDot.jsx`, `StudentItem.jsx`, `ScheduleTask.jsx`, `OnboardingScheduleModal.jsx`.
**Páginas:** `pages/Login.jsx`, `Dashboard.jsx`, `Operation.jsx`, `Notifications.jsx`, `Profile.jsx`, `Consultation.jsx`, `ConsultationDrawer.jsx`, `Enrollment.jsx`, `Seguimiento.jsx`, `TrackingModal.jsx`.
**Documentación:** `UI_UX_PLAN/01_PRODUCT_DESIGN_PHILOSOPHY.md`, `02_DESIGN_PRINCIPLES.md`, `03_DESIGN_SYSTEM.md`, `04_VISUAL_LANGUAGE.md`, `07_SCREEN_SPECIFICATIONS.md`, `08_MICRO_INTERACTIONS.md`, `13_FRONTEND_AUDIT_T7.md`, `nexo_moodboard.html`, `nexo_screens.html`.

## Apéndice B — No se modificó ni un archivo

Esta auditoría es de lectura y análisis. No se escribió código ni se tocaron archivos del proyecto (salvo la creación de este documento `PREMIUM_AUDIT.md`).
