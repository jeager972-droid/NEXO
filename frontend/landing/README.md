# Landing — Sitio público de NEXO

Documentación técnica de la landing page de NEXO (`frontend/landing/`,
paquete `nexo-landing` v1.0.0): el sitio público de marketing — presenta el
producto (custodia educativa en tiempo real para instituciones colombianas) y
captura leads vía formulario de contacto. Visión global y comparación con la
PWA en [`../README.md`](../README.md). Este documento reemplaza y actualiza a
`docs/LANDING.md`.

## Contenido

1. [Stack y scripts](#stack-y-scripts)
2. [Estructura](#estructura)
3. [Secciones de la página](#secciones-de-la-página)
4. [Canvas 3D (React Three Fiber)](#canvas-3d-react-three-fiber)
5. [Animaciones](#animaciones)
6. [Captura de leads — `POST /contacto`](#captura-de-leads--post-contacto)
7. [Cookies y legal](#cookies-y-legal)
8. [Estilos y tokens](#estilos-y-tokens)
9. [SEO](#seo)
10. [Build y despliegue](#build-y-despliegue)
11. [Estado del código y notas](#estado-del-código-y-notas)

## Stack y scripts

| Pieza | Versión / rol |
|---|---|
| React | 18 (JSX) |
| Vite | 5 (`vite.config.js`, `base: '/'`, dev server en puerto 3000) |
| react-router-dom | 7 — una sola ruta (`/` → `LandingPage`) |
| Tailwind CSS | 4 vía `@tailwindcss/vite` (CSS-first: `@import "tailwindcss"` + `@source`) |
| GSAP + ScrollTrigger | Animaciones de scroll y entrada |
| three + @react-three/fiber + @react-three/drei | Escena 3D del nodo (`.glb`) |
| lucide-react | Iconografía |
| vite-plugin-compression | Pre-comprime `dist/` en gzip y brotli |
| terser | Minificación (`drop_console`, `drop_debugger`, 2 pasadas) |
| @studio-freight/lenis | **Declarado en `package.json` pero sin uso en `src/`** |

Scripts (`package.json`):

```bash
npm run dev       # dev server (puerto 3000)
npm run build     # build de producción → dist/ (+ .gz y .br por archivo)
npm run preview   # servir el build localmente
bash build.sh     # atajo documentado = npm run build
```

No hay suite de tests ni lint configurado; la verificación es el build.

## Estructura

```
landing/
├── index.html                  # Entry: SEO/OG/Twitter, fuentes Google, #root, h1 oculto
├── vite.config.js              # React + Tailwind v4 + compresión + chunks por vendor
├── build.sh                    # npm run build
├── package.json
├── public/assets/
│   ├── logo/logo_nexo.png      # Logo/favicon/og:image
│   └── models/nodonuevo.glb    # Modelo 3D del nodo NEXO
└── src/
    ├── main.jsx                # createRoot + <App/> + index.css
    ├── App.jsx                 # BrowserRouter + ErrorBoundary + Suspense + Preloader + Route /
    ├── index.css               # Tokens --nx-* (paleta verde), utilidades de componentes, reveal
    ├── dashboard/
    │   └── DashboardPage.jsx   # Placeholder de /dashboard (NO está enrutado)
    └── landing/
        ├── LandingPage.jsx     # Orquesta secciones, registra ScrollTrigger, cookies
        ├── core/NexoModel.jsx  # Modelo GLB: normalización, autorrotación, drag, escudo
        ├── hooks/useCookieConsent.js
        ├── components/
        │   ├── NexoCanvas.jsx      # <Canvas> R3F: nexo 'solo' o red institucional 'grid'
        │   ├── Navbar.jsx          # Barra flotante (logo + anchors)
        │   ├── ContactModal.jsx    # Formulario → POST /contacto
        │   ├── LegalModal.jsx      # Privacidad / Tratamiento / Términos
        │   ├── CookieBanner.jsx / CookieManager.jsx / CookieFloatingButton.jsx
        │   ├── CustomCursor.jsx    # Cursor dot+ring con lerp (solo desktop)
        │   ├── DownloadButton.jsx  # Botón con rebote GSAP (tarjetas de instalación)
        │   ├── ErrorBoundary.jsx
        │   ├── useReveal.js        # IntersectionObserver → .is-visible
        │   └── useStickyScroll.js  # Animación de entrada por sección (GSAP / IO en mobile)
        └── sections/
            ├── Preloader.jsx       # Overlay de carga ~1.8 s (sale con power4.inOut)
            ├── HeroSection.jsx     # 01 Hero (titular por líneas + CTA + logo)
            ├── CredibilityBar.jsx  # 02 Métricas animadas (actualmente COMENTADA)
            ├── ProblemSection.jsx  # 03 El problema (3 tarjetas)
            ├── HowItWorksSection.jsx# 04 Cómo funciona (timeline 4 pasos, SVG scrub)
            ├── ValuePropSection.jsx# 05 Antes/después (tabla animada)
            ├── NodeSection.jsx     # 06 El nodo (canvas 3D + hotspots)
            ├── RolesSection.jsx    # 07 Roles en pestañas
            ├── SecuritySection.jsx # 09 Seguridad y confianza
            ├── DownloadSection.jsx # 08 Descarga PWA por plataforma
            ├── FinalCTASection.jsx # 10 CTA final → ContactModal + contacto directo
            └── Footer.jsx          # Enlaces legales + copyright (Ley 1581)
```

## Secciones de la página

Orden real de renderizado en `src/landing/LandingPage.jsx` (dentro de
`<main id="nx-landing">`):

| # | Componente | Contenido | Interacción |
|---|---|---|---|
| — | `Preloader` | Logo + barra de progreso ~1.8 s; límite de seguridad 4 s | Se desliza fuera con GSAP |
| — | `Navbar` | Logo + anchors internos (sin CTAs) | Flotante fija |
| — | `CustomCursor` | Cursor de dos capas (dot + ring con lerp) | Solo desktop; desactivado en táctil |
| 01 | `HeroSection` | Titular animado por líneas, subtítulo, CTA, logo del nodo | CTA abre `ContactModal` |
| 02 | `CredibilityBar` | Métricas con contadores al entrar en viewport | **Comentada en LandingPage — no se renderiza** |
| 03 | `ProblemSection` | 3 problemas: registro manual, fugas de trazabilidad, comunicación tardía | Título con stagger por palabra |
| 04 | `HowItWorksSection` | Flujo operativo en 4 pasos | Línea SVG con scroll-scrub (desktop) / timeline vertical (mobile) |
| 05 | `ValuePropSection` | «Sin NEXO» vs «Con NEXO», 4 escenarios | Columnas deslizan + filas stagger |
| 06 | `NodeSection` | Modelo 3D del nodo con hotspots | Click en hotspot → panel de specs (materiales, batería, conectividad, encriptación) |
| 07 | `RolesSection` | 6 roles institucionales en pestañas | Transiciones GSAP entre paneles |
| 09 | `SecuritySection` | Biometría en el nodo, E2E, auditoría, MEN/SIC, Ley 1581 | Escudo SVG + trust cards |
| 08 | `DownloadSection` | Tarjetas PWA: Android, iOS, Windows, Mac, Linux | `DownloadButton` con rebote → `PWA_URL` |
| 10 | `FinalCTASection` | CTA «Quiero que NEXO llegue a mi institución» | Título carácter a carácter; abre `ContactModal`; anchors `#contacto` |
| — | `Footer` | Logo, legales, copyright | Abre `LegalModal` |

## Canvas 3D (React Three Fiber)

- `src/landing/core/NexoModel.jsx` carga `/assets/models/nodonuevo.glb` con
  `useGLTF`, lo clona y normaliza (escala/centro por bounding box), rota en Y
  de forma continua (~0.24°/frame con el clock de three) y responde a drag
  orbital vía `dragDeltaRef` (overlay externo — el canvas tiene
  `pointer-events:none` para no bloquear el scroll). Opción `showShield` con
  `MeshDistortMaterial`.
- `src/landing/components/NexoCanvas.jsx` envuelve el `<Canvas>`: iluminación
  3-point + `Environment`, partículas de fondo, texturas Canvas2D generadas en
  runtime (avatares/puntos), `DragOverlay` para drag sin bloquear scroll y
  optimizaciones mobile.
  - `type="solo"` — un nodo (usado por `NodeSection`).
  - `type="grid"` — `InstitutionalNetwork`: red de nodos/líneas
    (**implementado pero actualmente sin uso**: ninguna sección lo renderiza).
- `LandingPage.jsx` declara un `lazy(() => import('./components/NexoCanvas'))`
  que **no se usa** — `NodeSection` importa el componente directamente. El
  comentario de `App.jsx` («canvases embedded in HeroSection and NodeSection»)
  está desactualizado: el hero muestra el logo PNG estático.

## Animaciones

- **GSAP + ScrollTrigger** se registran **una sola vez** en
  `LandingPage.jsx` (`gsap.registerPlugin(ScrollTrigger)`); las secciones no
  deben volver a registrarlo. Tras el montaje se llama `ScrollTrigger.refresh()`
  a los ~1.2 s (espera a que el DOM y el 3D pinten) y con debounce de 250 ms
  en `resize`.
- `useStickyScroll` — animación de entrada por sección: GSAP+ScrollTrigger en
  desktop, IntersectionObserver en mobile para fluidez; flags `isFirst`/`isLast`.
- `useReveal` — IntersectionObserver que añade `.is-visible` a elementos
  `.nx-reveal` (transiciones CSS en `index.css`).
- Patrones: entrada con `gsap.from()`, reveals fade+translateY, contadores,
  scrub de la línea temporal, materialización carácter a carácter (CTA final),
  tilt/hover en tarjetas de descarga, cursor personalizado con lerp.

## Captura de leads — `POST /contacto`

`src/landing/components/ContactModal.jsx` es el único punto de contacto con el
backend. Se abre desde el hero y el CTA final.

```json
POST {VITE_API_BASE_URL}/contacto
{ "name", "position", "institution", "city", "email", "whatsapp", "message" }
```

- Validación inline antes de enviar: cargo (select), institución, email con
  regex, WhatsApp ≥10 dígitos; nombre/ciudad/mensaje opcionales.
- `fetch` nativo (sin axios): estados `idle`/`loading`/`success`, mensaje de
  servidor en `errors._server` y feedback de conexión.
- Backend (referencia de `docs/LANDING.md`): 200 → lead en `contact_leads` +
  WhatsApp al owner si `NEXO_OWNER_WHATSAPP`; 429 → rate limit (fail-closed).
- Variable: `VITE_API_BASE_URL` (default `''` — sin env el POST va al mismo
  origen).

Las tarjetas de `DownloadSection` enlazan a la PWA con la URL hardcodeada
`https://nexo-eight-xi.vercel.app/app/instalar/<plataforma>` (`PWA_URL` en
`DownloadSection.jsx`) — ver [notas](#estado-del-código-y-notas).

## Cookies y legal

- `hooks/useCookieConsent.js` — consentimiento en localStorage
  (`nexo_cookie_consent`, versión `1.0`), categorías (necesarias, analíticas,
  marketing, preferencias), acciones `acceptAll`/`rejectAll`/`saveCustom`/reset.
- `CookieBanner` (primer uso), `CookieManager` (toggles por categoría, bloquea
  scroll) y `CookieFloatingButton` (reabre el gestor tras consentir).
- `LegalModal` — Privacidad / Tratamiento de datos / Términos; parsea contenido
  markdown-like a JSX, cierra con Escape o click en el overlay. Invocado desde
  `Footer` y `FinalCTASection` (cumplimiento Ley 1581, Colombia).
- `ErrorBoundary` envuelve toda la app; `Preloader` es global en `App.jsx`.

## Estilos y tokens

`src/index.css` (Tailwind v4 CSS-first: `@import "tailwindcss" source(none)` +
`@source` para el scanner):

- Paleta verde corporativa en tokens `--nx-*`: `--nx-green` `#2d6e30`,
  `--nx-green-dark/mid/light`, fondos `--nx-void/deep/surface`, bordes
  `--nx-border[-dim]`, textos `--nx-white/-text/-muted`. Los alias heredados
  `--nx-blue*` apuntan a la misma paleta verde (compatibilidad). **Sin modo
  oscuro** — a diferencia de la PWA.
- Tipografía: `--nx-font` = Plus Jakarta Sans; Google Fonts precarga también
  Outfit y JetBrains Mono.
- Easing propio: `--nx-ease` `cubic-bezier(0.16,1,0.3,1)` (distinto al
  `0.22,1,0.36,1` de la PWA).
- Utilidades de componentes: eyebrow, títulos, botones, tarjetas, navbar,
  timeline, tabs, hotspots, security grid y overrides responsive mobile.

## SEO

`index.html` (`lang="es-CO"`): description/keywords/robots, Open Graph y
Twitter Cards (`og:url`/`canonical` → `https://nexo.edu.co/`,
`og:image` → `/assets/logo/logo_nexo.png`), `h1` oculto para lectores de
pantalla/SEO, preconnect+preload de Google Fonts.

## Build y despliegue

`vite.config.js`:

- `target es2020`, `cssCodeSplit`, `minify: 'terser'` (elimina `console` y
  `debugger`, 2 pasadas), `reportCompressedSize: false`.
- Chunks manuales por vendor: `three`, `three-fiber` (fiber+drei), `gsap`
  (gsap+lenis), `react-vendor`.
- `vite-plugin-compression` emite `.gz` y `.br` junto a cada asset de `dist/`.

Salida: estático en `dist/` — servible en cualquier hosting/CDN (el job
`landing` de `.github/workflows/nexo-ci-cd.yml` lo despliega a Vercel según
`docs/LANDING.md`). A diferencia de la PWA no hay `vercel.json` propio ni
service worker.

## Estado del código y notas

- **`PWA_URL` hardcodeada** en `DownloadSection.jsx` apunta a un deployment de
  Vercel concreto; si cambia el dominio de la app hay que editar el código.
- **`CredibilityBar` está comentada** en `LandingPage.jsx` — el componente
  existe y funciona, pero no se renderiza.
- **`InstitutionalNetwork` (type="grid") y el `lazy` de NexoCanvas** en
  `LandingPage` son código sin uso actual.
- **`DashboardPage.jsx`** (`src/dashboard/`) es un placeholder no enrutado; el
  hero enlaza `/dashboard` en mobile, que cae en el mismo SPA (solo existe
  la ruta `/`).
- **Lenis** (`@studio-freight/lenis`) está en dependencias pero no se importa
  en `src/` — scroll-smooth se hace con `scroll-behavior: smooth` en CSS.
- **Sin tests ni lint**: el CI solo exige que `npm run build` no falle.
