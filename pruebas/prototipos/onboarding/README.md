# Preview desechable del onboarding — no es la implementación

Todo lo listado aquí es **solo para verificación visual** del onboarding.
Nada de esto es producción y **debe borrarse** una vez validado el flujo real.

## Implementación real (esto SÍ queda)

- `PWA/src/pages/onboarding/OnboardingFlow.jsx` — flujo completo por rol.
- `PWA/src/components/patterns/NexusGuide.jsx` — guía/bot reutilizable.
- `PWA/src/api/teacher.js` — cliente `/teacher/*`.
- Orquestación en `PWA/src/layout/Layout.jsx` (el onboarding es la
  pantalla completa, no un modal sobre el shell).

## Archivos desechables (borrar tras validar)

| Archivo | Qué es |
|---|---|
| `pruebas/prototipos/onboarding/index.html` | Mock estático original (sin React, sin API). |
| `pruebas/prototipos/onboarding/imagenbot.png` | Asset del mock. |
| `PWA/onboarding-preview.html` | Página standalone que monta `OnboardingFlow` aislado (sin auth/providers/API). Solo dev. |
| `PWA/src/preview/onboarding-preview.jsx` | Entry del preview. Monta el componente con `simulate`. |

## Cómo ver el preview (dev server de la PWA corriendo)

```
http://127.0.0.1:5173/app/onboarding-preview.html?role=rector
http://127.0.0.1:5173/app/onboarding-preview.html?role=coordinator
http://127.0.0.1:5173/app/onboarding-preview.html?role=teacher
```

`OnboardingFlow` tiene una prop `simulate` que **solo** usan estos
previews: omite las llamadas reales al backend y simula latencia.
En producción nunca se pasa.
