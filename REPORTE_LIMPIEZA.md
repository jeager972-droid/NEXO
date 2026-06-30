# Registro de Limpieza y Archivos Eliminados

Este documento detalla todas las eliminaciones recientes realizadas en el repositorio, la justificación técnica de cada una y si representan alguna pérdida crítica de código (spoiler: el código fuente está 100% a salvo).

## 1. Archivos compilados en `backend/api/` (Antes `alojamiento`)
- **Archivos borrados:** `index.html`, `index.html.br`, `index.html.gz`, `favicon.svg`, y la carpeta `public/` (con el modelo `nodo.glb`).
- **¿Qué eran?** Eran los archivos finales ("compilados") de tu Landing Page. Un agente anterior los compiló y los pegó manualmente dentro de la carpeta PHP para que Railway los sirviera.
- **¿Es algo importante?** **NO se perdió código fuente.** El código original de tu landing sigue a salvo en la carpeta `landing/`. Solo borré el resultado de la compilación manual. Ahora que entiendo tu arquitectura MVP, lo correcto es configurar Railway para que tome tu carpeta `landing/`, genere estos archivos automáticamente en la nube, y los sirva junto a PHP sin que tengas que "ensuciar" tu código en GitHub subiendo archivos compilados.

## 2. Carpeta `.git-backup-old` en `WebApp/`
- **Archivos borrados:** Toda la carpeta oculta `.git-backup-old`.
- **¿Qué era?** Era una copia de seguridad rota de Git. Ocupaba mucho espacio innecesariamente.
- **¿Es algo importante?** **NO.** Era pura basura que solo inflaba el peso del repositorio.

## 3. Carpeta `dashboard/` en `landing/src/`
- **Archivos borrados:** La carpeta `dashboard/` dentro de la landing y su ruta en `App.jsx`.
- **¿Qué era?** Era una vista de interfaz repetida y falsa que un agente había puesto dentro de la landing.
- **¿Es algo importante?** **NO.** Tu verdadero y funcional Dashboard institucional vive seguro y sano en `WebApp/src/pages/`. La landing debe ser solo la carta de presentación.

## 4. Archivos `.br` y `.gz` en `landing/`
- **Archivos borrados:** `package-lock.json.br`, `vite.config.js.gz`, `index.css.br`, etc.
- **¿Qué eran?** Versiones comprimidas (Brotli y Gzip) del código fuente que un agente guardó por error.
- **¿Es algo importante?** **NO.** Estos archivos no sirven de nada en un repositorio de código, son producto de un mal comando de compresión.

## 5. Carpeta `logo/` en la raíz
- **Archivos borrados:** La carpeta `logo/` que estaba suelta en la raíz.
- **¿Qué era?** Contenía el archivo `logo_nexo.png`.
- **¿Es algo importante?** **NO se perdió.** El logo no fue eliminado, fue movido a su lugar correcto: `landing/public/portfolio_media/logo_nexo.png`, para que la landing page pueda consumirlo correctamente.

## 6. Carpeta `supervisor/` y `scratch/`
- **Archivos borrados:** `backend/api/supervisor/` (estaba vacía) y `landing/scratch/inspect_glb.js`.
- **¿Qué eran?** `supervisor` era una carpeta de configuración obsoleta sin archivos. `scratch` contenía un viejo script que un agente usó para analizar tu modelo 3D.
- **¿Es algo importante?** **NO.** Eran restos de desarrollos pasados.

## 7. Documentación vieja (`FRONTEND.md`)
- **Archivos borrados:** `landing/readmes/FRONTEND.md`.
- **¿Qué era?** Un texto que describía cómo estaba hecho el frontend en etapas muy tempranas del proyecto.
- **¿Es algo importante?** **NO.** Estaba totalmente desactualizado respecto a la arquitectura real que documentamos hoy en `DEPLOYMENT_STRATEGY.md`.

---
**Conclusión:** No se ha borrado ni una sola línea de código fuente crítico. Tu lógica PHP, tus módulos de React en WebApp, y el código de tu Landing Page están intactos y más organizados que nunca.
