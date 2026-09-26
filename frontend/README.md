# Frontend de NEXO

Documentación global del frontend de NEXO, la plataforma institucional de gestión
escolar (custodia educativa en tiempo real: presencia biométrica, trazabilidad
estudiantil, alertas automáticas y comunicación con familias por WhatsApp).

> **NEXO no muestra datos. NEXO detecta situaciones y propone acciones.**
> El usuario no abre software; entra a su jornada de trabajo.
> — `frontend/design-philosophy/UX_DESIGN.md`

El directorio `frontend/` contiene **dos aplicaciones desplegables**, una
biblioteca de especificación de diseño y una colección de prototipos HTML
desechables:

```
frontend/
├── pwa/                  # Aplicación principal (SPA instalable, todos los roles)
├── landing/              # Sitio público / marketing (captura de leads)
├── design-philosophy/    # 15 documentos de especificación UX/UI (fuente de verdad)
└── prototipos/           # 4 mocks HTML estáticos para validación visual
```

## Los dos deployables

| | `frontend/pwa/` | `frontend/landing/` |
|---|---|---|
| **Qué es** | La aplicación: donde trabajan los 7 roles institucionales | El sitio público: presentación del producto y captura de interesados |
| **Producto** | `webapp-institucional` v1.0.0-rc.1 | `nexo-landing` v1.0.0 |
| **Stack** | React 18 · Vite 5 · Tailwind CSS 3 · Framer Motion · Axios · vite-plugin-pwa (Workbox) | React 18 · Vite 5 · Tailwind CSS 4 (vía `@tailwindcss/vite`) · GSAP + ScrollTrigger · React Three Fiber + drei + three.js (Lenis declarado pero sin uso) |
| **Base URL** | `/app/` (basename del router y `base` de Vite) | `/` |
| **Autenticación** | Sí (JWT en cookie HttpOnly + fallback Bearer) | No (SPA pública de una sola ruta) |
| **Offline/PWA** | Sí: manifest, service worker Workbox, cola de POSTs | No |
| **Tests** | Vitest + Testing Library (~560 pruebas en `src/__tests__`) | Sin suite; el CI solo verifica el build |
| **Despliegue** | Vercel (`frontend/pwa/vercel.json`) | Build estático (`frontend/landing/build.sh` → `dist/`) |
| **Documentación** | [pwa/README.md](pwa/README.md) | [landing/README.md](landing/README.md) |

Ambas consumen el mismo backend PHP (`VITE_API_BASE_URL`, definido por variable
de entorno): la PWA contra los endpoints `/v1`-like (`/auth/*`, `/operations/*`,
`/chat/*`, …) y la landing solo contra `POST /contacto`.

## Filosofía de diseño — `frontend/design-philosophy/`

`design-philosophy/` es la **fuente de verdad de producto y diseño**. No es
documentación aspiracional: el código de `frontend/pwa/src/` la cita
explícitamente en las cabeceras de casi cada archivo (`Autoridad: …`,
`CMP-###`, `SCR-###`, `DEC-###`, `FLOW-###`). La jerarquía es:

```
UX_DESIGN.md  (visión, prevalece ante cualquier contradicción)
      │
      ├── 01–09  especificación: filosofía → principios → tokens → IA → flujos → pantallas → motion → a11y
      ├── 10–11  plan de producción: wireframes y mockups
      ├── 12     guía de implementación (arquitectura por capas del frontend)
      ├── 13     auditoría T7 del código contra la spec
      └── nexo_moodboard.html  referencia visual ejecutable (tokens CSS reales)
```

| Archivo | Contenido | Cómo se relaciona con el código |
|---|---|---|
| `UX_DESIGN.md` | Visión del frontend: «Sistema Inteligente de Gestión Escolar», filosofía, principios (un clic, cero fricción, belleza sin exceso), arquitectura por rol. | Citada como *Autoridad* por `pwa/src/config/roles.js` y por los comentarios de cabecera de componentes y páginas. |
| `01_PRODUCT_DESIGN_PHILOSOPHY.md` | North Star («señales → siguiente acción comprensible»), promesa de experiencia, cierre memorable (Peak-End, DEC-017). | `OperationResult.jsx` (CMP-105) y `utils/messages.js` («nunca Error 500») implementan sus leyes de tono. |
| `02_DESIGN_PRINCIPLES.md` | Leyes verificables DEC-010…: una pantalla–una pregunta, una acción primaria, prevención antes que error, contexto persistente. | Los IDs `DEC-*` aparecen en headers de páginas (`SCR-OPS-*`, `SCR-HOME-01`) y en el diseño de `ProtectedRoute`/`Sidebar`. |
| `03_DESIGN_SYSTEM.md` | Sistema «NEXO Quiet Operations»: tokens de color OKLCH, tipografía, radios (8/12/16 px), sombras, motion, focus ring. | Implementado literalmente en `pwa/src/index.css` (variables `--nx-*`) y `pwa/tailwind.config.js`. |
| `04_VISUAL_LANGUAGE.md` | Lenguaje visual: claro como base diurna, oscuro para baja luz, «línea de situación» (`nx-sig`) como firma visual, pruebas de jerarquía. | `components/patterns/SituationLine.jsx` (CMP-028) es la firma; `Surface.jsx` implementa la prueba «solo títulos». |
| `05_INFORMATION_ARCHITECTURE.md` | Arquitectura organizada por preguntas de trabajo (Inicio, Operaciones, Notificaciones, Casos, Consultas, Enrolamiento…), filtrada por rol. | `SIDEBAR_ITEMS` en `config/roles.js` es su implementación directa. |
| `06_USER_FLOWS.md` | Catálogo `FLOW-*` (auth, operaciones, seguimiento…) con contrato narrativo: estados de carga, vacío, error, offline. | `OPERATION_COMMANDS` mapea a flujos `FLOW-OPS-*`; cada página documenta su `SCR-*`/`FLOW-*` en la cabecera. |
| `07_SCREEN_SPECIFICATIONS.md` | Catálogo maestro `SCR-*` de pantallas con pregunta que responde, roles, flujo y estado (N1–N4). | Los comentarios de `pages/*.jsx` empiezan por su ID (`SCR-CHAT-01`, `SCR-ENR-01`, …). |
| `08_MICRO_INTERACTIONS.md` | Tokens de motion (150/200/250/300 ms), curva `cubic-bezier(.22,1,.36,1)`, solo `transform`/`opacity`, confirmación ≤300 ms. | `--nx-fast/standard/deliberate/max` y `--nx-ease-out` en `index.css`; `animate-seal`/`halo` en Tailwind; Framer Motion lo usa en `Chat.jsx` (`EASE`). |
| `09_ACCESSIBILITY.md` | Objetivo WCAG 2.2 AA (AAA selectivo): contraste, foco ≥2 px, targets, reflow 400%, estado nunca solo por color. | `:focus-visible` global, `useOverlay` (focus trap + Escape), `aria-*` en `DataCard`, skip link en `index.html`. |
| `10_WIREFRAME_PLAN.md` | Plan de wireframes por lotes (WF-A/B/…), viewports de prueba (360×800, 1280×800, reflow 400%). | Guía de validación; no produce código, define qué se probó antes de maquetar. |
| `11_MOCKUP_MASTERPLAN.md` | Orden de producción de mockups: foundations → primitives → shell → páginas patrón → patrones NEXO → flujos P0. | Espejo de la estructura `components/ui` (CMP-001–041) → `components/patterns` (CMP-100+). |
| `12_FRONTEND_IMPLEMENTATION_GUIDE.md` | Arquitectura por capas: foundations → primitives → components → patterns → shells → features → pages. | La estructura de carpetas de `pwa/src/` (ui/patterns/pages/api) sigue este mapa de dependencias. |
| `13_FRONTEND_AUDIT_T7.md` | Auditoría del código contra la spec: diagnóstico y brechas detectadas en su momento (firma NEXO ausente, jerarquía plana). | Histórico: documenta por qué se crearon `SituationLine`, `Surface` y la nueva jerarquía. |
| `nexo_moodboard.html` | Moodboard ejecutable: paleta OKLCH, tipografía Inter, componentes de referencia (`.nx-stat`, `.nx-sig`, `.nx-empty`, skeleton). | Los mismos tokens `--nx-*` que usa `pwa/src/index.css`; base visual de `StatCard`, `EmptyState`, `Skeleton`. |

**Regla práctica:** cuando el código y estos documentos difieran, `UX_DESIGN.md`
prevalece; si un componente cita `CMP-*`/`SCR-*`/`DEC-*`, ese identificador se
puede buscar aquí para saber *por qué* existe.

## Prototipos — `frontend/prototipos/`

Mocks HTML estáticos (sin React, sin API) creados para validación visual antes
de implementar. **Son desechables**: su README propio (`prototipos/onboarding/README.md`)
los marca para borrado tras validar el flujo real.

| Prototipo | Archivo | Qué valida | Implementación real |
|---|---|---|---|
| Onboarding guiado por Nexus | `prototipos/onboarding/index.html` | Flujo de configuración inicial (jornadas, grupos, riesgo) con el bot-guía | `pwa/src/pages/onboarding/OnboardingFlow.jsx` + `components/patterns/NexusGuide.jsx` |
| Frontend pendiente | `prototipos/frontend/index.html` | Notificaciones con acciones, operación, dispositivos/OTA, configuración, vista docente | `pages/Notifications.jsx`, `Operation.jsx`, `Devices.jsx`, `Profile.jsx` |
| Pantallas pendientes | `prototipos/pendientes/index.html` | Preview de implementación de pantallas por construir | páginas de `pwa/src/pages/` |
| Lectores de huella | `prototipos/sensores/index.html` | Gestión de sensores biométricos | `pages/Devices.jsx` (SCR-DEV-01) |

> ⚠️ El README de `prototipos/onboarding/` referencia un preview standalone
> (`pwa/onboarding-preview.html`, `pwa/src/preview/`) que **ya no existe** en el
> repo: el modo `simulate` sigue disponible en `OnboardingFlow` pero solo se usa
> internamente para desarrollo.

## Stack compartido y convenciones

- **React 18 + Vite 5** en ambos proyectos; todo JSX, sin TypeScript.
- **Tailwind**: v3 con `tailwind.config.js` en la PWA; v4 CSS-first (`@import "tailwindcss"`, `@source`) en la landing.
- **Tokens `--nx-*`**: ambos definen variables CSS propias, pero con paletas
  distintas — la PWA usa la paleta azul institucional «Quiet Operations» de
  `03_DESIGN_SYSTEM.md` (con modo oscuro por clase `.dark`); la landing usa una
  paleta verde corporativa propia (sin modo oscuro).
- **Comentarios de cabecera**: cada archivo declara responsabilidad,
  autoridad (documento de spec), IDs de componente/pantalla y dependencias.
  Mantener esa convención al crear archivos nuevos.
- **Idioma**: UI, documentación y commits en español (`es-CO`/`es`).

## Documentación relacionada

| Documento | Contenido |
|---|---|
| [pwa/README.md](pwa/README.md) | Documentación exhaustiva de la aplicación (arquitectura, roles, API, chat Nexus, diseño, PWA, tests, deploy). Absorbe `docs/WEBAPP.md`. |
| [landing/README.md](landing/README.md) | Documentación de la landing (secciones, canvas 3D, GSAP, contacto, build). Absorbe `docs/LANDING.md`. |
| `docs/WEBAPP.md` / `docs/LANDING.md` | Versiones anteriores en `docs/`; los README de cada subproyecto son ahora la referencia actualizada. |
| `../AGENTS.md` | Reglas de trabajo del repo (verificación local, restricciones). |
