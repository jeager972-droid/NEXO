# LANDING.md — Landing page de NEXO

Documentación técnica de la landing page, basada en el código de `frontend/landing/`.

## Responsabilidades

- Página de presentación pública de NEXO.
- Captura de leads vía formulario de contacto (`POST /contacto`).
- Animaciones con GSAP (scroll-triggered, hero, secciones).
- SEO básico y responsive.

## Stack

- React (Vite, SPA).
- Three.js para modelos 3D y escenas WebGL.
- GSAP para animaciones (ScrollTrigger).
- TailwindCSS para estilos.
- Axios para el POST de contacto.

## Estructura

```
landing/
├── src/
│   ├── main.jsx                # Bootstrap React
│   ├── App.jsx                 # Composición de secciones
│   ├── index.css               # Tailwind + estilos globales
│   ├── components/
│   │   ├── Hero.jsx            # Sección hero con animación GSAP
│   │   ├── Features.jsx        # Features grid
│   │   ├── HowItWorks.jsx      # Pasos / flujo
│   │   ├── Pricing.jsx         # Planes
│   │   ├── FAQ.jsx             # Preguntas frecuentes
│   │   ├── Footer.jsx
│   │   ├── Navbar.jsx
│   │   └── ContactModal.jsx    # Modal de contacto → POST /contacto
│   ├── hooks/
│   └── assets/
├── public/
├── index.html
├── vite.config.js
├── tailwind.config.js
├── .env                        # VITE_API_BASE_URL
├── .env.example
├── package.json
└── README.md
```

## Formulario de contacto

`ContactModal.jsx` envía un POST al backend:

```json
POST {VITE_API_BASE_URL}/contacto
{
  "name": "string",
  "whatsapp": "string",
  "email": "string",
  "institution": "string",
  "message": "string"
}
```

- **200**: lead registrado en `contact_leads` + WhatsApp al owner si `NEXO_OWNER_WHATSAPP` está seteado.
- **429**: rate limit (Redis, fail-closed → 503 si Redis caído).
- El modal muestra feedback de éxito/error según la respuesta.

## Animaciones

- **Three.js**: modelos 3D y escenas WebGL en el hero/secciones.
- **GSAP**: entrada con `gsap.from()` (título, subtítulo, CTA).
- ScrollTrigger para reveal de secciones (fade + translateY).
- Navbar: cambio de estilo al hacer scroll.
- `useGSAP` hook con cleanup automático (`gsap.context()` + revert).

## Configuración

`.env`:

```
VITE_API_BASE_URL=https://nexo-80go.onrender.com
```

## Build y despliegue

```bash
cd landing
npm install
npm run dev      # dev server
npm run build    # build a dist/
npm run preview  # servir build local
```

Despliegue en Vercel configurado por `.github/workflows/nexo-ci-cd.yml` (job `landing`).

## Tests

No hay suite de tests dedicada para la landing. El CI verifica el build (`npm run build`) sin errores.
