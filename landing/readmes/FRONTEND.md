# NEXO Frontend — Documentación Técnica

## 1. Stack tecnológico

El frontend de la landing page de NEXO utiliza un conjunto de dependencias modernas de alto rendimiento en el ecosistema React y WebGL:

*   **react** y **react-dom** (`^18.2.0`):
    *   *Uso en el proyecto:* Framework base para la construcción de interfaces declarativas basadas en componentes y el control del estado interactivo de la aplicación.
    *   *Alternativas y Razón de elección:* React es el estándar de la industria y la opción nativa idónea para integrarse de forma robusta con la biblioteca de renderizado declarativo en 3D `@react-three/fiber` y el ecosistema modular de componentes.
*   **gsap** (`^3.15.0`):
    *   *Uso en el proyecto:* Motor principal de animación. Implementa la arquitectura *Sticky Scroll* (vía ScrollTrigger) en la transición de secciones, la animación secuencial de entrada de caracteres en los titulares de CTA (`FinalCTASection.jsx`), la animación de los pasos en la línea de tiempo (`HowItWorksSection.jsx`) y el cambio dinámico entre pestañas (`RolesSection.jsx`).
    *   *Alternativas y Razón de elección:* Supera ampliamente a CSS y Framer Motion en rendimiento al manipular directamente propiedades nativas sin causar sobrecarga en el bucle de eventos de React. Cuenta con el plugin `ScrollTrigger` que permite un control sumamente preciso de la sincronización basada en el scroll.
*   **three** (`^0.166.0`):
    *   *Uso en el proyecto:* Motor WebGL subyacente de bajo nivel utilizado para la renderización, iluminación, texturizado y cómputo espacial de los modelos y redes en 3D.
    *   *Alternativas y Razón de elección:* Es la biblioteca 3D por excelencia en la web. Permite interactuar con la GPU de forma eficiente y es un requisito fundamental para el ecosistema de React Three Fiber.
*   **@react-three/fiber** (`^8.17.14`):
    *   *Uso en el proyecto:* Integrador reconciliador que permite escribir escenas de Three.js utilizando componentes reactivos declarativos y hooks dentro de la estructura estándar de React.
    *   *Alternativas y Razón de elección:* Elimina la necesidad de escribir cientos de líneas de código de Three.js imperativo y facilita la manipulación del árbol de objetos 3D sincronizado con el estado de React.
*   **@react-three/drei** (`^9.121.4`):
    *   *Uso en el proyecto:* Provee componentes auxiliares y utilidades para Three.js. Se utiliza para cargar y pre-cargar el modelo GLB (`useGLTF`), definir el material dinámico de la esfera de protección (`MeshDistortMaterial`) y configurar luces de ambiente externas (`Environment`).
    *   *Alternativas y Razón de elección:* Evita escribir cargadores y materiales dinámicos a mano, garantizando mejores prácticas de carga diferida y manejo de texturas.
*   **tailwindcss** (`^4.3.0`) & **@tailwindcss/vite** (`^4.3.0`):
    *   *Uso en el proyecto:* Integración de estilos y compilación ágil mediante el nuevo compilador basado en Vite.
    *   *Alternativas y Razón de elección:* Permite estructurar de forma rápida clases utilitarias integradas en el pipeline de Vite, aunque la base estética del diseño principal de la landing page descansa sobre un robusto sistema de tokens en `index.css`.
*   **@studio-freight/lenis** (`^1.0.42`):
    *   *Uso en el proyecto:* Instalada pero **inactiva**. Se prefiere la física nativa del scroll del navegador para evitar interferencias de latencia con la renderización 3D y las animaciones de ScrollTrigger a lo largo del viewport.

---

## 2. Estructura de carpetas

A continuación se detalla el árbol completo de la carpeta `src/`, explicando el propósito y las dependencias de cada archivo:

```
src/
├── App.jsx                  # Componente raíz de la aplicación.
├── index.css                # Sistema global de estilos, tokens CSS y clases core.
├── main.jsx                 # Punto de entrada de renderizado de React en el DOM.
└── landing/                 # Contenedor del ecosistema de la Landing Page de NEXO.
    ├── LandingPage.jsx      # Orquestador maestro que renderiza las secciones y activa ScrollTrigger global.
    ├── components/          # Componentes de UI globales y Hooks reutilizables.
    │   ├── ContactModal.jsx     # Formulario modal interactivo de contacto y cotización.
    │   ├── CustomCursor.jsx     # Cursor de ratón personalizado compuesto por un punto y un anillo fluido.
    │   ├── ErrorBoundary.jsx    # Capturador de errores de renderizado para evitar caídas en producción.
    │   ├── Navbar.jsx           # Barra de navegación flotante fija con enlaces directos a las secciones.
    │   ├── NexoCanvas.jsx       # Contenedor del canvas WebGL que decide entre renderizar el modelo 3D o la red en red.
    │   ├── useReveal.js         # Hook que implementa IntersectionObserver para transiciones fluidas de entrada.
    │   └── useStickyScroll.js   # Hook core que gestiona el comportamiento de entrada y salida de las secciones.
    ├── core/                # Elementos centrales e inmutables del render 3D.
    │   └── NexoModel.jsx        # Lector del modelo GLB con auto-rotación y control orbital alternativo.
    ├── scenes/              # Destinado para composiciones complejas de escenas (actualmente vacío).
    └── sections/            # Bloques funcionales independientes que forman la Landing Page.
        ├── CredibilityBar.jsx   # Barra de logotipos y credibilidad (actualmente desactivada).
        ├── DownloadSection.jsx  # Sección con enlaces de descarga de la aplicación y vista previa.
        ├── FinalCTASection.jsx  # Cierre comercial con llamado a la acción interactivo y materialización de caracteres.
        ├── Footer.jsx           # Pie de página institucional con políticas y copyright.
        ├── HeroSection.jsx      # Pantalla inicial de gran impacto estético con el nodo 3D interactivo.
        ├── HowItWorksSection.jsx# Línea de tiempo interactiva que dibuja un trazo SVG a medida que se hace scroll.
        ├── NodeSection.jsx      # Detalle técnico del nodo físico con hotspots interactivos tridimensionales.
        ├── Preloader.jsx        # Pantalla de carga inicial del sitio.
        ├── ProblemSection.jsx   # Tabla interactiva comparativa del sistema tradicional frente a NEXO.
        ├── RolesSection.jsx     # Pestañas interactivas animadas con GSAP enfocadas en la experiencia por usuario.
        ├── SecuritySection.jsx  # Rejilla que detalla las capas de seguridad offline de la plataforma.
        └── ValuePropSection.jsx # Exposición de los 3 pilares tecnológicos fundamentales del sistema.
```

### Detalle de Archivos Clave en `src/`:
*   `App.jsx`: Renderiza e integra `<LandingPage />` envuelto en un `<ErrorBoundary />`. Depende de React.
*   `index.css`: Contiene la declaración de fuentes de Google Fonts, custom properties (`--nx-*`), resets CSS globales, animaciones y clases utilitarias de UI. Depende del compilador de TailwindCSS.
*   `main.jsx`: Punto de arranque que monta la app de React en el elemento `#root` de HTML. Depende de React y ReactDOM.

---

## 3. Arquitectura de secciones

La landing page de NEXO utiliza un patrón estructural unificado llamado **Section-Wrapper / Section-Inner** para lograr transiciones fluidas y transiciones 3D fluidas sin parpadeos visuales.

### ¿Por qué existe esta estructura?
En una web tradicional, las secciones simplemente se desplazan de manera secuencial en la pantalla. Para lograr un efecto inmersivo y de alta gama, NEXO implementa pantallas fijas temporales donde el usuario continúa haciendo scroll pero el contenido permanece inmóvil en el visor, animándose de forma interna antes de dar paso a la siguiente sección.

### position:sticky y ScrollTrigger
1.  **`.section-wrapper` (El riel de scroll):** Es un elemento contenedor de flujo normal cuya altura determina la cantidad de scroll que el usuario debe recorrer para superar la sección (ej. `100vh` para secciones estándar o `380vh` para secciones con micro-animaciones internas complejas).
2.  **`.section-inner` (El contenedor fijo):** Tiene la regla `position: sticky; top: 0; height: 100vh; overflow: hidden;`. Esto hace que, en cuanto el wrapper entra en el viewport, el contenedor `.section-inner` se "ancle" de forma fija en la pantalla durante todo el trayecto de scroll del wrapper, liberándose de forma natural al completarse la altura de este.
3.  **useStickyScroll.js:** Este hook aplica la animación de entrada y de salida a cada sección `.section-inner` de forma completamente reactiva vinculándose a su respectivo `.section-wrapper`.
    *   *Entrada:* Desplaza y escala el contenido (`yPercent: 6, scale: 0.98, opacity: 0` a valores naturales) desde el `90%` al `20%` del scroll del trigger.
    *   *Salida:* Empuja el contenido hacia arriba (`yPercent: -6, scale: 0.98, opacity: 0`) desde el `30%` del fondo hasta que sale por completo de la pantalla.

---

## 4. Sistema de animaciones GSAP

GSAP (GreenSock Animation Platform) controla y sincroniza toda la experiencia visual interactiva.

### Registro de Plugins
El registro de plugins (`gsap.registerPlugin(ScrollTrigger)`) se realiza **únicamente una vez** en el archivo global `LandingPage.jsx` para evitar redundancias de inicialización o pérdida de rendimiento en memoria.

### El patrón `gsap.context()`
Dentro del hook `useEffect` de los componentes, las animaciones se encapsulan bajo `gsap.context((self) => { ... })`. Al desmontar el componente, se retorna `() => ctx.revert()`.
*   *¿Por qué?* Esto limpia por completo todos los triggers y timelines creados en esa ejecución, previniendo fugas de memoria y evitando duplicidad de ScrollTriggers tras recargas rápidas de React en fase de desarrollo.

### Scrub Bidireccional
La sincronización exacta de la animación con la barra de desplazamiento se activa mediante `scrub: 0.8`. Al configurarse de esta manera, la animación avanza al bajar el scroll y retrocede de forma fluida al subir.
*   **ease: 'none'**: Es estrictamente obligatorio en animaciones con scrub bidireccional. Si se define una función de facilidad como `power3.out`, la animación se ejecuta a ritmos diferentes en el descenso respecto al ascenso, rompiendo la fluidez y provocando comportamientos asimétricos o desajustes de posición visual.

### Diferencia entre `toggleActions` y `scrub`
*   `scrub`: La animación está directamente atada a la posición del scroll (ej. el trazo del cable SVG en `HowItWorksSection.jsx`).
*   `toggleActions`: El scroll actúa meramente como un interruptor de encendido (ej. `play none none none` en los bloques de texto). Al pasar por la coordenada definida, la animación se ejecuta por completo a velocidad de tiempo real de forma autónoma.

### Hook `useReveal.js`
Utiliza el `IntersectionObserver` de JavaScript nativo para detectar la visibilidad de elementos que poseen la clase `.nx-reveal`. En cuanto entran un 15% en pantalla, añade la clase `.is-visible`, lo que dispara una transición de CSS fluida y fluida. Se usa para elementos decorativos o textos secundarios que no requieren de una sincronización por fotograma de scroll con GSAP.

---

## 5. Sistema 3D (Three.js + React Three Fiber)

NEXO integra un sofisticado sistema 3D de WebGL optimizado para no obstaculizar la navegación.

### `NexoCanvas.jsx`
Es el lienzo maestro que encapsula el componente `<Canvas>` de React Three Fiber.
*   **Props:** Acepta `type` (ej. `'hero'`, `'node'`, `'grid'`), `scale`, `showShield` (muestra el campo protector), `coldLight` (activa iluminación fría en azul cobalto) y `scrollProgress`.
*   **Modo Solo / Grid:** En los modos individuales renderiza el modelo físico `<NexoModel />`. En modo `'grid'`, despliega el componente `<InstitutionalNetwork />`.

### `NexoModel.jsx`
*   **Carga:** Utiliza `useGLTF('/assets/models/nodonuevo.glb')` para recuperar el modelo 3D optimizado.
*   **Normalización:** Para evitar deformaciones o que el modelo salga de la cámara según el tamaño original del archivo físico, calcula de forma reactiva el `THREE.Box3` de la escena y lo re-escala de forma matemática a una dimensión objetivo (`TARGET_SIZE = 2.6 * scale`), centrando el pivote exactamente en su masa central real (`-center.x, -center.y, -center.z`).
*   **Rotación Automática:** Se ejecuta en un bucle frame-a-frame de alta frecuencia usando el hook `useFrame` sumando `AUTO_ROTATION_SPEED = 0.004` radianes por frame. Esto mantiene la rotación viva incluso si el scroll está inactivo.
*   **Drag orbital con DragOverlay:** Para permitir que el usuario examine el modelo arrastrando con el ratón o el dedo sin interrumpir el scroll normal de la página, el Canvas WebGL tiene `pointer-events: none`. Encima de él se posiciona un `div` invisible llamado `DragOverlay` con `touch-action: pan-y`. Este captura el arrastre horizontal, calcula la diferencia del delta (`dx` y `dy`) y lo pasa de manera segura al bucle `useFrame` del modelo 3D, el cual actualiza suavemente la rotación del grupo en los ejes X e Y.

### Iluminación Profesional de 3 Puntos
Configurada mediante tres luces direccionales para lograr volumetría prémium:
1.  *Key Light (Luz Principal):* Ubicada a `[5, 5, 5]`, intensidad `2.2`, blanca cálida. Aporta la iluminación base.
2.  *Fill Light (Luz de Relleno):* Ubicada a `[-5, -2, 3]`, intensidad `0.8` en tonos beige cálidos, suaviza las sombras.
3.  *Rim Light (Luz de Contorno):* Ubicada a `[-3, 5, -5]`, intensidad `1.5`, color azul eléctrico (`#4FACFE`). Genera un contorno de luz en los bordes para separar el modelo tridimensional de la profunda oscuridad del fondo.
*(En modo coldLight, estos colores viran a gamas de azul hielo con mayor contraste).*

### `InstitutionalNetwork` (La Red)
Representa visualmente la conectividad institucional en la sección de descarga. Genera de forma estática 16 nodos distribuidos espacialmente (`NETWORK_NODES`), conectando a través de un búfer de líneas (`THREE.BufferGeometry`) a todos aquellos que se encuentran a una distancia menor a 3.8 unidades, creando una red orgánica interconectada interactiva.

---

## 6. Componentes globales

*   `Navbar.jsx`: Barra flotante ultra-limpia y minimalista que combina el isotipo `NEXO` con navegación directa por anclajes de sección, estructurada con la directiva `aria-label` para accesibilidad.
*   `ContactModal.jsx`: Modal de alta fidelidad que contiene un formulario interactivo detallado para la solicitud de implantación de NEXO, implementando controles de clic externos para su cierre y previniendo el scroll del body cuando está activo.
*   `CustomCursor.jsx`: Estructurado con un micro-punto central blanco (`#cursor-dot`) que sigue al ratón instantáneamente y un anillo exterior traslúcido (`#cursor-ring`) que lo sigue con un factor de interpolación lineal (`lerpFactor = 0.12`). Al pasar sobre elementos interactivos (botones, enlaces), el anillo se expande de `32px` a `48px` y adquiere un tono azul resplandeciente. Se auto-desactiva en dispositivos con pantallas táctiles.
*   `ErrorBoundary.jsx`: Componente de React de clase clásico que actúa como red de seguridad. Si algún componente 3D o timeline de GSAP genera una excepción no controlada, captura el error visualizando una consola formateada y limpia en lugar de colapsar la aplicación completa en el navegador.

---

## 7. Diseño y tokens

El diseño cuenta con un sistema estricto de variables personalizadas (CSS Custom Properties) definidas en `:root` dentro de `index.css`:

| Variable | Valor | Uso Técnico |
| :--- | :--- | :--- |
| `--nx-void` | `#05070F` | Fondo principal profundo y oscuro del sitio. |
| `--nx-deep` | `#080C17` | Fondo secundario ligeramente más claro para contraste de secciones. |
| `--nx-surface` | `#0E1525` | Superficie base de tarjetas (`.nx-card`) y componentes interactivos. |
| `--nx-surface-2` | `#111C30` | Superficie de resalte (hover) para elementos de UI. |
| `--nx-border` | `#1C2B4A` | Bordes generales de la interfaz para definición sutil. |
| `--nx-blue` | `#0A84FF` | Color de acento primario (Azul NEXO de alto contraste). |
| `--nx-text` | `#E8EDF5` | Color de texto principal de alta legibilidad. |
| `--nx-muted` | `#6B7FA3` | Color para subtítulos y textos descriptivos de baja jerarquía. |

### Clases de Tipografía Core:
*   `.nx-h1`: Títulos principales de escala masiva (`clamp(2.2rem, 5vw, 4rem)`) con un interlineado ultra-compacto (`1.1`).
*   `.nx-h2`: Títulos de sección con tipografía Plus Jakarta Sans de peso extra-bold (`800`).
*   `.nx-body`: Párrafos con interlineado holgado (`1.75`) para máxima claridad de lectura.

### Animaciones Keyframe
*   `pulse-ring`: Genera un anillo expansivo translúcido en los hotspots interactivos del mapa del nodo.
*   `shield-pulse`: Regula la sutil pulsación y deformación de escala (`1` a `1.04`) del escudo energético 3D.

---

## 8. Build y despliegue

La landing page se compila optimizando cada recurso para asegurar una carga inicial por debajo del segundo.

### Configuración en `vite.config.js`
*   **outDir: '../'**: Indica que la salida de la compilación de producción debe generarse una carpeta por encima de la ubicación del frontend (es decir, en el directorio de alojamiento del backend). Esto permite que el servidor web principal sirva el sitio estático compilado de forma directa.
*   **manualChunks**: Distribuye y segmenta las librerías externas de gran volumen para evitar cuellos de botella de descarga:
    *   `three-vendor`: Agrupa a `three`, `@react-three/fiber` y `@react-three/drei`.
    *   `gsap-vendor`: Aísla la librería de animación de GSAP.

### Flujo Completo de Despliegue
1.  **Fase Local:** El desarrollo y las pruebas en tiempo real se ejecutan mediante `npm run dev` en el puerto `3000`.
2.  **Verificación y Compilación:** Se corre `npm run build` para compilar los scripts minimizados de JS y optimizar los archivos de estilos CSS.
3.  **Control de Versiones:** Se realiza la adición y confirmación de los archivos mediante Git (`git add . && git commit`).
4.  **Entrega y Despliegue:** Al hacer `git push`, el pipeline de integración continua de **Railway** detecta el cambio en la rama principal, compila la aplicación y despliega la nueva versión en producción de manera automatizada.

> [!WARNING]
> **Advertencia sobre archivos >50MB:** El modelo GLB físico localizado en `public/assets/models/nodonuevo.glb` posee un tamaño cercano a los 66MB. GitHub desplegará una alerta preventiva durante el empuje por superar el umbral estándar de 50MB recomendados por archivo. El archivo se subirá de forma exitosa, pero es vital mantener el modelo optimizado y no incrementar su tamaño final para preservar el rendimiento del sitio.

---

## 9. Decisiones de arquitectura

*   **Desactivación de Lenis:** Aunque el suavizado de scroll (Smooth Scrolling) de Lenis añade fluidez estética, su interpolación interfiere directamente con la física de refresco de ScrollTrigger de GSAP en navegadores móviles e introduce una penalización de procesamiento que afecta la estabilidad de fotogramas del lienzo 3D.
*   **Registro Centralizado de ScrollTrigger:** Al invocar `gsap.registerPlugin` una sola vez en el contenedor principal `LandingPage.jsx`, garantizamos que no haya registros duplicados que provoquen desalineaciones en los cálculos internos de ScrollTrigger.
*   **Ubicación del modelo GLB en `/public`**: Al estar situado en la raíz estática pública, se puede pre-cargar instantáneamente de forma paralela al inicio del análisis del DOM de React sin sobrecargar el flujo de procesamiento inicial del bundler de Vite.
*   **ScrollTrigger.refresh() con retraso de 1200ms:** Al renderizarse modelos WebGL dinámicos y layouts fluidos, los elementos modifican su altura exacta tras cargarse por completo. El retardo de 1.2 segundos garantiza que todas las dimensiones finales estén plasmadas en el DOM antes de que GSAP calcule las coordenadas exactas de activación de scroll.
*   **DragOverlay como Elemento Div Independiente:** Integrar los eventos de arrastre directamente dentro del lienzo de Three.js causa parpadeos táctiles e inhabilita por completo el scroll de la página en dispositivos móviles. Al usar un `div` superpuesto flotante con CSS clásico, se independizan los gestos de forma óptima.

---

## 10. Guía para el desarrollador

### Cómo añadir una nueva sección correctamente:
1.  Crea el archivo `.jsx` de la sección en `src/landing/sections/`.
2.  Utiliza la estructura base del wrapper y el sticky inner:
    ```jsx
    import { useRef } from 'react'
    import { useStickyScroll } from '../components/useStickyScroll'
    
    export default function NuevaSection() {
      const wrapperRef = useRef()
      const innerRef = useRef()
      useStickyScroll(wrapperRef, innerRef)
      
      return (
        <div ref={wrapperRef} className="section-wrapper" id="nueva-seccion">
          <section ref={innerRef} className="section-inner" style={{ background: 'var(--nx-void)' }}>
            {/* Contenido aquí */}
          </section>
        </div>
      )
    }
    ```
3.  Importa y renderiza la nueva sección en `src/landing/LandingPage.jsx` dentro del flujo principal del elemento `<main>`.
4.  Si la sección requiere un scroll más prolongado para realizar animaciones en su interior, añade la clase `.section-wrapper--tall` al wrapper y personaliza el valor del estilo `height` (ej. `style={{ height: '250vh' }}`).

### Cómo cambiar el modelo 3D:
1.  Coloca tu nuevo archivo en formato binario GLB dentro del directorio estático `/public/assets/models/`.
2.  Abre `src/landing/core/NexoModel.jsx` y modifica el valor de la constante `MODEL_PATH`:
    ```javascript
    const MODEL_PATH = '/assets/models/tu-nuevo-modelo.glb'
    ```
3.  Asegúrate de cambiar también la llamada de pre-carga que se encuentra en la última línea del mismo archivo:
    ```javascript
    useGLTF.preload('/assets/models/tu-nuevo-modelo.glb')
    ```

### Cómo añadir un nuevo rol en RolesSection:
Abre `src/landing/sections/RolesSection.jsx`, localiza la constante `ROLES` en la parte superior e inserta un nuevo objeto de configuración respetando la siguiente estructura:
```javascript
{
  id: 'nuevo-rol',
  label: 'Nombre del Rol',
  icon: ( <svg>...</svg> ),
  headline: 'Frase de impacto comercial',
  body: 'Texto descriptivo detallado sobre la utilidad de NEXO en este rol.',
  features: [
    'Característica destacada 1',
    'Característica destacada 2',
    'Característica destacada 3',
  ]
}
```
El panel y las pestañas horizontales se adaptarán de forma automática calculando las animaciones fluidas de transición de GSAP correspondientes.

### Checklist de Debugging para ScrollTrigger:
Si las animaciones basadas en scroll se activan antes de tiempo, se rompen o muestran un comportamiento visual errático, realiza las siguientes verificaciones rápidas:
*   [ ] ¿El trigger de la animación dentro de la sección apunta al elemento `wrapperRef.current` y no al `innerRef.current`? (El trigger siempre debe ser el wrapper móvil externo).
*   [ ] Si la sección posee una animación con la opción `scrub` activa, ¿el estilo de facilidad (`ease`) está configurado estrictamente como `'none'`?
*   [ ] ¿Se está desmontando de forma segura el trigger llamando a `ctx.revert()` en el ciclo de limpieza de `useEffect`?
*   [ ] ¿Ejecutaste manualmente `ScrollTrigger.refresh()` tras realizar inserciones dinámicas de elementos en el DOM?
