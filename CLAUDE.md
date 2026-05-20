# NEXO — Hoja de Ruta de Diseño Industrial V3

## Hardware Real (fuente de verdad)
- `assets/nodo-front.jpg` — Frente: caja landscape brushed steel (W:H ~1.3:1, depth ~0.27H)
  - Scanner huella: bezel negro recessed, left ~40%, verde interno
  - LED verde Ø12mm + botón metálico Ø8mm, upper-right pair
  - OLED horizontal (70×22mm equiv.), off-center right, mid-height → texto verde "I.E La Paz"
  - Speaker grille (dot matrix), lower-right
  - Cerradura cilíndrica, lower-left
  - Antena cilíndrica gris Ø8mm, top-center
- `assets/nodo-back.jpg` — Reverso: placa plana brushed steel, 4 tornillos esquina
- `assets/nodo-side.jpg` — Lateral: profundidad ~30% de ancho, seam de dos piezas visible
- `assets/nodo-desert.jpg` — Desierto: 3 LEDs verdes alineados, arena cubriendo superficie
- `assets/nodo-impact.jpg` — Impacto: pared bloques hormigón gris, piedras en trayectoria
- `assets/nodo-rain.jpg` — Lluvia: nodo expuesto a agua

## Three.js Geometría del Nodo (coordenadas normalizadas)
```
Cuerpo: BoxGeometry(2.6, 2.0, 0.7)  → color 0xb2b2a8, metalness 0.85, roughness 0.35
Scanner bezel: BoxGeometry(0.80, 1.05, 0.10) @ (-0.62, 0.10, 0.35) → 0x0a0a0a
Scanner pad:   PlaneGeometry(0.55, 0.75) @ (-0.62, 0.12, 0.41) → green emissive
LED verde:     SphereGeometry(0.065) @ (0.38, 0.72, 0.36) → 0x00ff44 emissive
Botón:         CylinderGeometry(0.055) @ (0.62, 0.72, 0.37) → 0x888880
OLED:          PlaneGeometry(0.72, 0.22) @ (0.58, 0.16, 0.36) → CanvasTexture
Speaker:       PlaneGeometry(0.34, 0.28) @ (0.72, -0.48, 0.36) → dot-canvas
Cerradura:     CylinderGeometry(0.10) @ (-0.92, -0.68, 0.37) → 0x777770
Antena base:   CylinderGeometry(0.055,0.07,0.18) @ (-0.30, 1.05, 0.05)
Antena rod:    CylinderGeometry(0.030,0.030,0.55) @ (-0.30, 1.42, 0.05)
Tornillos (4): CylinderGeometry(0.04) @ ±1.10, ±0.82, 0.37 → 0x999990
```

## Secciones Cinemáticas V3
1. **Hero** — Fondo degradado Apple (blanco→gris→verde institucional), nodo flotante center-right, mouse drag rotation (OrbitControls), logo marca
2. **Seguridad** — Zoom cósmico, esfera Tierra en fondo, panel flotante CanvasTexture cifrado E2E
3. **Lluvia** — Descenso atmosférico, plano océano con vertex shader (ondas), partículas de lluvia
4. **Desierto** — Teletransportación instantánea, partículas arena horizontal ocre, OLED → "IoT"
5. **Resistencia/Salón** — Pared hormigón procedural (CanvasTexture), rock lanzado en parábola, loop 7s

## Módulos JavaScript
- `buildNode()` → grupo Three.js del hardware exacto
- `buildEarth()` → esfera Earth para sección seguridad  
- `buildOcean()` → ShaderMaterial con vertex shader de ondas
- `buildParticles()` → rain, sand, burst
- `initHero()` → OrbitControls, gradient BG, auto-rotate suave
- `initSecurity()` → ScrollTrigger zoom-out, Earth visible, panel UI
- `initStorm()` → ScrollTrigger descenso, océano activo
- `initDesert()` → ScrollTrigger arena, OLED update
- `initImpact()` → ScrollTrigger classroom wall, rock loop timer
- `initApp()` → teléfono 3-pantallas citación funcional
- `setupScrollTriggers()` → registra todos los triggers y limpia on-destroy
- `tick()` → rAF loop, ShaderMaterial uTime, partículas

## Dependencias CDN (order matters)
1. Three.js r128: `cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js`
2. OrbitControls r128: `cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js`
3. GSAP 3.12.5: `cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js`
4. ScrollTrigger: `cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js`
5. Tailwind CDN: `cdn.tailwindcss.com`

## Reglas de rendimiento
- `renderer.setPixelRatio(Math.min(devicePixelRatio, 2))` siempre
- Geometrías compartidas con `BufferGeometry`
- Limpiar ScrollTrigger.getAll().forEach(t=>t.kill()) en HMR
- No crear objetos nuevos dentro del render loop (tick)
