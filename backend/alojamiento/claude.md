# NEXO — Landing Page de Categoría Mundial
## Arquitectura Estratégica Completa (10 Módulos)

---

## STACK TECNOLÓGICO
- React 18+ (Vite)
- `@react-three/fiber` + `@react-three/drei` → escena 3D
- `gsap` + `gsap/ScrollTrigger` → animaciones de scroll
- `tailwindcss` → layout y utilidades
- Phosphor Icons (thin/light stroke) → iconografía
- Fuente: `Plus Jakarta Sans` (ya en proyecto)

---

## TONO DE MARCA
**Palantir + Stripe.** Autoridad técnica sin arrogancia. Frialdad institucional con calor humano implícito.
- Audiencia: Rectores (emocional) + Secretarías de Educación (institucional)
- Posicionamiento: No tecnología — **responsabilidad institucional resuelta**
- Paleta: `#05070F` base · `#0A84FF` acento frío · `#E8EDF5` texto · `#1C2333` superficies

---

## MÓDULO 01 — HERO
**Objetivo:** Reconocimiento del dolor + primera impresión de inevitabilidad

### Copy
- **Eyebrow:** `Sistema de Custodia Educativa en Tiempo Real — Colombia`
- **H1:** `Cada minuto que un estudiante desaparece del sistema, la institución asume la responsabilidad.`
- **Subtítulo:** `NEXO cierra ese vacío. Control de presencia, trazabilidad completa y comunicación institucional automatizada — todo en una infraestructura que opera sin internet, sin excusas y sin puntos de falla.`
- **CTA Primario:** `Solicitar demostración institucional`
- **CTA Secundario:** `¿Eres rector o directivo? Ve cómo funciona en tu institución →`
- **Micro-copy:** `Sin compromisos. Presentación adaptada al contexto de tu institución.`

### Dirección Visual
- Fondo: `#05070F` (negro profundo / azul noche)
- Modelo 3D del nodo: ocupa 40% derecho del viewport en desktop
- Nodo rota lento con luz ambiental fría (azul/blanco) — acero inoxidable
- Entrada del título: text reveal línea por línea (GSAP SplitText)
- Sin imágenes de stock. Sin ilustraciones. Solo nodo + tipografía.
- Navbar: floating glass pill (`mt-6`, `mx-auto`, `w-max`, `rounded-full`)

### Componentes
- `Navbar.jsx` — floating pill con logo + links + botón Demo
- `HeroSection.jsx` — eyebrow + H1 + subtítulo + CTAs + micro-copy
- `NexoNode3D.jsx` — modelo 3D (R3F) con rotación lenta y luz IBL

---

## MÓDULO 02 — BARRA DE CREDIBILIDAD
**Objetivo:** Prueba social institucional temprana — reducción del riesgo percibido

### Copy
- **Ancla:** `Implementado en instituciones educativas de Colombia`
- **Métricas:**
  - `847 estudiantes monitoreados`
  - `12 instituciones`
  - `0 fugas de datos`
  - `30 min/día devueltos por profesor`
  - `98.7% uptime registrado`

### Dirección Visual
- Fondo ligeramente más claro que el hero (`#080C17`)
- Logos de instituciones (o cifras reales si no hay logos)
- Transición fluida desde el hero — sin separadores agresivos
- Números grandes con animación count-up al entrar en viewport

### Componentes
- `CredibilityBar.jsx`

---

## MÓDULO 03 — DECLARACIÓN DEL PROBLEMA
**Objetivo:** Agitación del dolor con empatía institucional — principio de espejo cognitivo

### Copy
- **Título:** `El problema no era la voluntad. Era la infraestructura.`
- **Bloque 1 — La lista de asistencia manual:** `30 minutos por profesor, por día. Multiplicado por cada docente de tu institución. Ese tiempo no vuelve — y nunca fue tiempo administrativo. Era tiempo de cátedra.`
- **Bloque 2 — El estudiante que nadie vio salir:** `Entre cambio de clase y cambio de clase, entre un baño y el siguiente, hay un espacio donde la institución pierde visibilidad. Cuando algo ocurre en ese espacio, la responsabilidad recae sobre todos.`
- **Bloque 3 — El padre que se enteró tarde:** `La inasistencia registrada en papel, archivada en una carpeta, comunicada tres días después — o nunca. La familia no estaba en el circuito. La institución tampoco.`
- **Cierre:** `NEXO no es una aplicación más. Es la infraestructura que cierra estos tres vacíos simultáneamente, en tiempo real, sin depender de la conexión a internet de la institución.`

### Dirección Visual
- Íconos: líneas finas estilo Phosphor (NO emojis ni clipart)
- Animación: GSAP ScrollTrigger — cada columna entra escalonada desde abajo, delay 150ms
- 3 columnas en desktop → stack en mobile

### Componentes
- `ProblemSection.jsx`

---

## MÓDULO 04 — CÓMO FUNCIONA
**Objetivo:** Claridad operativa sin tecnicismo — el cliente siente que ya sabe usar el sistema

### Copy
- **Título:** `Así opera NEXO. Sin capacitaciones de semanas. Sin cambios de hábito forzados.`
- **Paso 1:** `El estudiante llega → Coloca su huella en el nodo al entrar al salón. El registro ocurre en menos de un segundo. El profesor ya está enseñando.`
- **Paso 2:** `El sistema detecta la ausencia → Si un estudiante no registró presencia, NEXO lo identifica automáticamente y notifica al acudiente vía WhatsApp — sin intervención humana, sin formularios, sin demoras.`
- **Paso 3:** `La institución tiene visibilidad completa → Coordinadores y rectores acceden en tiempo real a un panel donde cada movimiento dentro de la institución queda registrado, auditado y descargable.`
- **Paso 4:** `Los patrones emergen solos → Salidas frecuentes al baño, llegadas tarde recurrentes, evasiones entre clases — NEXO cruza la información y genera alertas antes de que el problema escale.`
- **Micro-copy:** `Todo esto ocurre sin internet. Con batería de respaldo de 12 horas. Con conectividad M2M independiente.`

### Dirección Visual
- Línea conectora animada que se dibuja sola con GSAP ScrollTrigger (drawSVG / stroke-dashoffset)
- Cada paso aparece cuando la línea llega a él (reveal progresivo)
- Desktop: horizontal con línea de progreso luminosa
- Mobile: vertical con línea izquierda tipo timeline

### Componentes
- `HowItWorksSection.jsx`

---

## MÓDULO 05 — PROPUESTA DE VALOR CENTRAL
**Objetivo:** Anclaje de valor — contraste antes/después — mayor densidad persuasiva

### Copy
- **Título:** `De la operación reactiva a la custodia proactiva.`
- **Subtítulo:** `Las instituciones que operan con NEXO no esperan que algo ocurra para actuar. Saben qué ocurre, cuándo ocurre y quién es responsable — antes de que escale.`
- **Tabla Antes/Después:**
  | Sin NEXO | Con NEXO |
  |---|---|
  | Lista de asistencia manual, 30 min/día por docente | Registro automático en menos de 1 segundo por estudiante |
  | El padre se entera de la inasistencia días después | Notificación WhatsApp en tiempo real, el mismo momento |
  | El coordinador no sabe quién salió al baño ni cuántas veces | Panel de alertas con patrones detectados automáticamente |
  | Los registros existen en papel, vulnerables y dispersos | Auditoría digital inalterable, descargable en Word o Excel |
  | Si se va la luz o el internet, el sistema colapsa | Operación autónoma: batería 12h + conectividad M2M propia |
- **CTA Secundario:** `Descarga el resumen ejecutivo para secretarías de educación →`

### Dirección Visual
- Columna "Sin NEXO": tono gris frío, seco
- Columna "Con NEXO": tono azul luminoso, dinámico
- Animación de transición entre columnas al scroll

### Componentes
- `ValuePropSection.jsx`

---

## MÓDULO 06 — EL NODO (Producto Físico)
**Objetivo:** Tangibilidad + autoridad técnica — infraestructura, no app desechable

### Copy
- **Título:** `Construido para durar en las condiciones reales de una institución educativa colombiana.`
- **Subtítulo:** `No diseñado en un laboratorio ideal. Diseñado para cortes de luz, para humedad, para el uso diario de cientos de estudiantes — y para seguir funcionando.`
- **Especificaciones (especificación → significado real):**
  - `Carcasa de acero inoxidable → Resiste el uso intensivo diario sin degradarse`
  - `Batería interna de 12 horas → Opera durante cortes de luz sin interrupciones`
  - `Conectividad M2M con SIM Card → No depende del WiFi de la institución`
  - `Encriptación de grado militar → Los datos de tus estudiantes son intocables`
  - `Garantía de reemplazo en 5 años → Si falla, lo reemplazamos. Sin procesos. Sin costos ocultos.`
- **Micro-copy:** `El 70% de los costos de daño por causas naturales o ambientales son cubiertos por NEXO durante la vigencia del contrato.`

### Dirección Visual
- Modelo 3D del nodo centrado, grande, interactivo (rotación con cursor)
- Hotspots: puntos de luz pulsante (círculos con opacity breathing) sobre partes del nodo
- Al hover/tap: tooltip con el significado real (no la especificación técnica)
- Experiencia: impacto (Hero) → exploración (aquí) — misma secuencia que Apple

### Componentes
- `NodeSection.jsx` — wrapper HTML con hotspot UI
- `NexoNode3D.jsx` — reutilizado con modo "exploración" (interacción completa)

---

## MÓDULO 07 — ROLES Y CASOS DE USO
**Objetivo:** Identificación de rol + relevancia personalizada — mayor tasa de conversión B2B

### Copy
- **Título:** `NEXO opera diferente para cada rol. Pero todos ven lo mismo: control total.`
- **RECTOR:** `Accede a la auditoría completa de tu institución. Cada acción de cada rol queda registrada — incluyendo quién borró un registro y a qué hora. Tu firma institucional está protegida.`
- **COORDINADOR:** `Detecta patrones antes de que se conviertan en problemas. Evasiones entre clases, salidas frecuentes, llegadas tarde recurrentes — todo visible en un panel, con alertas automáticas.`
- **PROFESOR:** `Recupera 30 minutos diarios de cátedra. Cita acudientes con un botón. Reporta daños desde tu teléfono. Tu carga administrativa se reduce a cero.`
- **SECRETARÍA DE EDUCACIÓN:** `Implementa trazabilidad y custodia estudiantil a escala municipal o departamental. Accede a reportes consolidados por institución. Justifica la inversión con datos reales descargables.`

### Dirección Visual
- Tabs activos con línea inferior animada
- Transición entre tabs: fade + slide lateral suave
- Ícono del rol + título en grande + párrafo + lista de 3 funciones clave

### Componentes
- `RolesSection.jsx`

---

## MÓDULO 08 — DESCARGA DE LA APLICACIÓN
**Objetivo:** Acción de bajo compromiso + primer "sí" pequeño que predispone al contrato

### Copy
- **Título:** `Tu panel de control institucional. En todos tus dispositivos.`
- **Subtítulo:** `Disponible para Android, iOS, Windows, Mac y Linux. La misma información, en tiempo real, donde estés.`
- **Badge por plataforma:** `Descarga gratuita para instituciones vinculadas`
- **Micro-copy:** `El acceso completo se activa cuando tu institución implementa NEXO. La aplicación es el punto de entrada — el sistema es la transformación.`

### Dirección Visual
- Mockup de la app en dispositivos (desktop + mobile) — interfaz oscura visible
- Íconos de plataforma + botón de descarga por cada uno
- Fondo oscuro con glassmorphism

### Componentes
- `DownloadSection.jsx`

---

## MÓDULO 09 — SEGURIDAD Y CONFIANZA
**Objetivo:** Eliminación del miedo al riesgo + autoridad regulatoria colombiana

### Copy
- **Título:** `Los datos de tus estudiantes no son un activo de nadie más.`
- **Subtítulo:** `NEXO fue diseñado desde cero con protección de datos como principio de arquitectura, no como característica adicional.`
- **Puntos clave:**
  - `Los registros biométricos nunca salen del nodo en formato legible`
  - `Encriptación de extremo a extremo en cada transmisión`
  - `Auditoría de accesos: sabes exactamente quién tocó qué dato y cuándo`
  - `Cumplimiento con lineamientos de protección de datos del MEN y la SIC`
  - `Sin venta de datos. Sin terceros con acceso. Sin publicidad.`
- **Cierre:** `La confianza de una institución pública no se gana con palabras. Se demuestra con arquitectura.`

### Dirección Visual
- SVG animado minimalista: candado o escudo (NO genérico)
- Grid 2x3 de puntos con íconos Phosphor thin
- Fondo: profundo oscuro con sutil textura

### Componentes
- `SecuritySection.jsx`

---

## MÓDULO 10 — CTA FINAL
**Objetivo:** Conversión con dos caminos claros (rector → demo / secretaría → documento)

### Copy
- **Título:** `El próximo semestre puede empezar diferente.`
- **Subtítulo:** `La implementación de NEXO es más rápida de lo que imaginas. Una conversación es suficiente para saber si tu institución está ready.`
- **CTA Primario:** `Solicitar demostración institucional`
- **CTA Secundario:** `Descargar propuesta técnica para secretaría de educación`
- **Micro-copy:** `Sin costos de evaluación. Sin compromisos previos al contrato. Con acompañamiento desde el primer contacto.`
- **Contacto:** `contacto@nexo.edu.co · +57 310 000 0000 (WhatsApp)`

### Dirección Visual
- Fondo: gradiente oscuro profundo
- Urgencia real (no artificial): cada semestre sin NEXO = datos perdidos + tiempo no recuperado

### Componentes
- `FinalCTASection.jsx`

---

## FOOTER
- Logo NEXO
- Links legales: Política de privacidad · Tratamiento de datos (Ley 1581 Colombia)
- Redes sociales (solo si activas)
- Copyright

### Componentes
- `Footer.jsx`

---

## ARQUITECTURA DE ARCHIVOS (NUEVO)

```
src/
  landing/
    LandingPage.jsx          ← Orquestador principal
    sections/
      HeroSection.jsx        ← Módulo 01
      CredibilityBar.jsx     ← Módulo 02
      ProblemSection.jsx     ← Módulo 03
      HowItWorksSection.jsx  ← Módulo 04
      ValuePropSection.jsx   ← Módulo 05
      NodeSection.jsx        ← Módulo 06
      RolesSection.jsx       ← Módulo 07
      DownloadSection.jsx    ← Módulo 08
      SecuritySection.jsx    ← Módulo 09
      FinalCTASection.jsx    ← Módulo 10
      Footer.jsx
    components/
      Navbar.jsx
      ErrorBoundary.jsx
    core/
      NexoModel.jsx          ← Modelo 3D (modo rotación lenta + modo exploración)
  components/
    canvas/
      GlobalCanvas.jsx
```

---

## PALETA DE COLORES
```
--nx-void:      #05070F   ← fondo principal (hero, CTA final)
--nx-deep:      #080C17   ← fondo secundario (credibilidad)
--nx-surface:   #0E1525   ← superficies / cards
--nx-border:    #1C2B4A   ← bordes sutiles
--nx-blue:      #0A84FF   ← acento principal (azul frío institucional)
--nx-blue-dim:  #0056CC   ← acento hover
--nx-text:      #E8EDF5   ← texto principal
--nx-muted:     #6B7FA3   ← texto secundario
--nx-white:     #FFFFFF   ← texto de alto contraste
```

---

## REGLAS DE ANIMACIÓN
- Entradas: `translateY(40px) opacity(0)` → `translateY(0) opacity(1)` via IntersectionObserver
- Timing: `cubic-bezier(0.16, 1, 0.3, 1)` — easing spring natural
- Duración base: `700ms`
- Stagger entre elementos hermanos: `150ms`
- Línea progresiva (Módulo 04): stroke-dashoffset animado via ScrollTrigger
- Nodo 3D Hero: rotación Y automática `0.003 rad/frame`, parallax leve con mouse

---

## REGLAS ANTI-PATRÓN (OBLIGATORIAS)
- ❌ Sin imágenes de stock
- ❌ Sin emojis como íconos
- ❌ Sin fondos blancos o grises corporativos
- ❌ Sin gradientes de colores vivos (no verde neón, no morado genérico)
- ❌ Sin urgencia artificial ("oferta limitada")
- ❌ Sin exclamaciones en el copy
- ❌ Sin tecnicismos explícitos (SHA-256, Raspberry Pi, etc.)
- ✅ Solo íconos Phosphor (thin/light stroke)
- ✅ Solo tipografía Plus Jakarta Sans
- ✅ Solo animaciones via transform + opacity
- ✅ backdrop-blur solo en elementos fixed/sticky

---

## NOTAS ESTRATÉGICAS CLAVE
1. **El modelo 3D es el diferenciador visual más poderoso.** Usarlo dos veces: Hero (impacto, no se explica) → Módulo 06 (exploración con hotspots). Secuencia: impacto → descubrimiento.
2. **El copy habla al rector emocionalmente y a la secretaría institucionalmente** con las mismas frases.
3. **Urgencia real, no artificial:** cada semestre sin NEXO = datos perdidos + responsabilidades sin respaldo.
4. **El CTA de descarga del resumen ejecutivo es estratégico:** le da a la secretaría algo para llevar a su superior.
5. **WhatsApp en el CTA final es coherente** con la propuesta de valor del producto mismo.
