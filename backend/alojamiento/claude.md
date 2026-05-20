Actúa como Ingeniero de Software Principal Frontend y Desarrollador WebGL Senior. Tu tarea es construir una Landing Page interactiva premium para "Project NEXO" utilizando React (Vite), Three.js (mediante @react-three/fiber y @react-three/drei) y GSAP (con ScrollTrigger). 

Debes generar componentes altamente modulares, limpios y optimizados para la GPU.

### stack tecnológico requerido
- React 18+ (Vite)
- `@react-three/fiber` y `@react-three/drei` para la escena 3D.
- `gsap` y `gsap/ScrollTrigger` para la coreografía de scroll y animaciones.
- `tailwindcss` para el layout y tipografía.

---

### reglas de arquitectura y optimización (obligatorio)
1. **Instancia Única de Canvas**: Toda la experiencia visual interactiva debe ocurrir dentro de un único `<Canvas>` global con posición fija (`fixed top-0 left-0 w-full h-screen -z-10`). No instancies múltiples lienzos.
2. **Controlador de Cámara Unificado (Camera Rig)**: Implementa un componente `<CameraRig />` que interpole la posición de la cámara (`camera.position`) y el punto de enfoque (`camera.lookAt`) de forma suave usando `gsap.to` o `MathUtils.lerp` en el bucle `useFrame`.
3. **Optimización de Memoria (GPU Cleanup)**: Toda geometría, textura y material generado dinámicamente debe liberarse explíctamente usando `.dispose()` al desmontar componentes (`useEffect`).
4. **Reutilización de Recursos**: Las partes del hardware del nodo deben representarse como componentes hijos de un grupo común para manipular sus posiciones locales individuales sin romper la jerarquía.
5. **Rendimiento**: Limita el uso de luces dinámicas pesadas. Usa `Environment` de `@react-three/drei` para iluminación global basada en imágenes (IBL) y sombras suaves optimizadas. Mantén la tasa a 60 FPS estables.

---

### coreografía de secciones y especificación de desarrollo

#### 1. Pantalla de Carga (The Preloader)
- **UI**: Overlay de pantalla completa en gris mate (`#1a1a1a`). En el centro, un SVG minimalista del isotipo NEXO con animación de opacidad/escala en bucle suave (CSS o GSAP).
- **Lógica WebGL**: Utiliza `useProgress` de `@react-three/drei` para monitorear la carga de geometrías y texturas PBR (metal cepillado, acabados mate).
- **Transición**: Al llegar al 100%, GSAP realiza un deslizamiento vertical (`yPercent: -100`) de la cortina del preloader con un easing `power4.inOut`.

#### 2. Introducción Cinematográfica (Hero Section)
- **UI**: Fondo con un sutil gradiente radial CSS de estudio (`#f4f6f2` a un blanco/gris suave con destellos verdes tenues). Tipografía sans-serif de alto contraste, amplia y limpia en la izquierda.
- **Visual 3D**: El modelo del "Nodo NEXO" (puedes simularlo con un grupo de geometrías primitivas combinadas de forma fotorrealista: carcasa de acero extruido, cilindro biométrico, pantalla plana y puertos traseros) se posiciona a la derecha (`x: 2`, `y: 0`, `z: 0`).
- **Interacción**: Para interactuar con inercia, integra un control orbital limitado (`<OrbitControls>` con `enableZoom={false}` y restricciones de ángulo) para permitir al usuario rotar levemente el nodo con el mouse.

#### 3. Quiénes Somos (La Visión)
- **UI**: Layout de grilla limpia a dos columnas. Izquierda: Texto corporativo de alta gama, alineado con espaciado amplio. Derecha: Vacío para el canvas.
- **Coreografía GSAP**: Al scrollear, la cámara desplaza el nodo hacia la derecha (`x: 3.5`), reduce la escala global del grupo en un 20% e inicia una rotación suave automática en el eje Y.

#### 4. Anatomía de Hardware (Exploded View)
- **UI**: Textos laterales fijos que aparecen y desaparecen mediante opacidad con el scroll.
- **WebGL / GSAP**: 
  - Centra el nodo en pantalla (`x: 0`).
  - Anima la posición local de los sub-componentes (carcasa exterior `z: +3`, panel trasero `z: -3`, PCB interna permanece en `z: 0`).
  - Dibuja líneas tridimensionales dinámicas (pueden ser `<Line>` de `@react-three/drei`) que apunten desde los componentes hacia anotaciones HTML flotantes usando `<Html>` (con oclusión activada para que se oculten si la geometría las tapa).

#### 5. Cifrado Perimetral (Seguridad Descentralizada)
- **UI**: El fondo CSS transiciona suavemente hacia un gris grafito profundo (`#121212`).
- **WebGL**:
  - Contracción neumática firme: Reensambla las piezas del nodo instantáneamente en sus posiciones originales (`z: 0`).
  - Rota el nodo 180 grados sobre el eje Y para revelar el panel posterior.
  - Instancia una esfera de protección con efecto wireframe o un material personalizado semitransparente con `MeshDistortMaterial` que envuelva levemente el nodo, simulando un escudo de datos activo.

#### 6. Ecosistema en Acción (Sincronización Hardware + Software)
- **UI**: Pantalla dividida. Izquierda: Nodo anclado a pared virtual. Derecha: Simulación interactiva de una interfaz móvil.
- **Coreografía**: 
  - Desplaza el nodo a `x: -3` y rótalo para que parezca montado en la pared lateral de la escena.
  - Genera un efecto de parpadeo de LED verde (`MeshStandardMaterial` con `emissive` e intensidad variable en el bucle de renderizado).
  - Haz viajar un haz de luz (partículas o un spline animado) desde el nodo hacia la derecha de la pantalla.
  - Muestra un mock interactivo de interfaz móvil (con CSS puro) que ejecute la siguiente secuencia interactiva al hacer clic en un botón:
    1. Botón "Citar acudiente" -> Abre formulario (Grupo, Estudiante, Motivo).
    2. Botón "Enviar" -> Transiciona a una ventana de chat estilo WhatsApp con un mensaje push interactivo de NEXO (Opciones 1 o 2).

#### 7. Escalabilidad Institucional (Cierre Técnico)
- **WebGL**:
  - La cámara hace un zoom out drástico (`z` de la cámara aumenta suavemente).
  - Utiliza `<Instances>` y `<Instance>` de `@react-three/drei` (o `InstancedMesh` nativo) para clonar el modelo del nodo de forma eficiente en una cuadrícula tridimensional de 3x3 suspendida en el espacio.

#### 8. Despliegue Global (Sección de Descargas)
- **UI**: Desvanecimiento completo de la escena WebGL (reducir opacidad del lienzo a 0 o desmontar de forma segura).
- **Interacción**: Renderiza una sección puramente HTML/CSS con una cuadrícula de 5 tarjetas premium (iOS, Android, Web App, Windows, macOS). Cada tarjeta debe tener un efecto interactivo de inclinación 3D (Tilt) implementado con animaciones suaves de CSS `transform: perspective() rotateX() rotateY()` que sigan la posición local del cursor de forma fluida.

---

### entregables esperados
Genera el código modular completo:
1. `App.jsx` (Orquestador con Canvas y ScrollTrigger).
2. `Scene.jsx` (Manejo de la escena R3F, luces, cámara rig e instanciaciones).
3. `NexoModel.jsx` (Geometría del hardware, materiales PBR, y referencias para el exploded view).
4. Estilos CSS necesarios para el preloader y el efecto hover 3D Tilt.

---

### CONTEXTO DE LIMPIEZA DE CÓDIGO ANTERIOR (IMPORTANTE)
Venimos de eliminar una idea de página desarrollada anteriormente. Debes identificar, limpiar y remover cualquier residuo, dependencia obsoleta, estilos huérfanos o lógica del concepto antiguo. La implementación actual debe ser escrita de forma limpia, robusta y completamente desde cero sobre una arquitectura modular sólida.

---

### directivas de optimización de contexto y ahorro masivo de tokens (estricto)
Para evitar el consumo innecesario de tokens de entrada/salida y optimizar el tiempo de respuesta, sigue estas reglas de generación:

1. **Sin Explicaciones de Relleno**: Omita introducciones históricas, resúmenes teóricos de WebGL o tutoriales de instalación. Ve directo al código y a la arquitectura técnica.
2. **Uso de Diffs y Placeholders**: Cuando realices modificaciones en componentes existentes, no reescribas todo el archivo. Genera únicamente la función o el bloque de código que cambia, utilizando comentarios claros como `// ... [el resto del código se mantiene igual] ...` para indicar la continuidad.
3. **Optimización de Geometría**: Para el modelo 3D en `NexoModel.jsx`, no escribas miles de líneas de datos de vértices manuales. Utiliza primitivas de Three.js (`<boxGeometry>`, `<cylinderGeometry>`, `<torusGeometry>`) combinadas de manera inteligente para simular el hardware.
4. **CSS Simplificado**: No generes archivos CSS masivos. Utiliza las clases utilitarias de Tailwind CSS directamente en los componentes para estructurar el layout, limitando el CSS personalizado al mínimo indispensable (como el efecto Tilt de las tarjetas).
5. **Código Conciso**: Evita comentarios redundantes dentro del código. Limítate a documentar las matrices de transformación del 3D y los triggers de GSAP que requieran precisión matemática.
