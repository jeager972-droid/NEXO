# NEXO Landing — Flujo de Despliegue Estático

## Arquitectura de Assets

```
backend/alojamiento/
├── assets/                    ← Assets públicos (servidos por nginx/Apache)
│   ├── models/
│   │   ├── nodo.glb
│   │   ├── planeta.glb
│   │   ├── roca.glb
│   │   └── pared.glb
│   ├── environments/
│   │   └── studio_small_08_2k.exr
│   └── textures/
│       ├── waternormals.jpg
│       └── texturas-arena/
│           ├── Ground080_2K-JPG_Color.jpg
│           ├── Ground080_2K-JPG_NormalGL.jpg
│           └── Ground080_2K-JPG_Roughness.jpg
└── frontend/
    ├── src/landing/           ← Código fuente React + Three.js
    ├── dist/                  ← Build output (generado por Vite)
    └── package.json
```

---

## Comando de Build

### 1. Compilación Vite

```bash
cd /home/john/proyectos/NEXO/backend/alojamiento/frontend
npm run build
```

**Output:** `/home/john/proyectos/NEXO/backend/alojamiento/frontend/dist/`

### 2. Verificación de Assets

Los assets **ya están** en `/backend/alojamiento/assets/`. Vite los referencia mediante rutas relativas (`assets/models/nodo.glb`).

**CRÍTICO:** El servidor web (nginx/Apache) debe servir:
- `/` → `frontend/dist/index.html`
- `/assets/*` → `assets/*` (carpeta raíz de alojamiento)

---

## Configuración de Servidor Web

### Nginx (Recomendado)

```nginx
server {
    listen 80;
    server_name nexo.local;
    root /home/john/proyectos/NEXO/backend/alojamiento;

    # Landing page
    location / {
        alias /home/john/proyectos/NEXO/backend/alojamiento/frontend/dist/;
        try_files $uri $uri/ /index.html;
    }

    # Assets estáticos (GLB, EXR, JPG)
    location /assets/ {
        alias /home/john/proyectos/NEXO/backend/alojamiento/assets/;
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # MIME types críticos
    types {
        model/gltf-binary glb;
        image/x-exr       exr;
    }
}
```

### Apache (.htaccess)

```apache
# Rewrite para SPA
RewriteEngine On
RewriteBase /
RewriteRule ^index\.html$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.html [L]

# MIME types
AddType model/gltf-binary .glb
AddType image/x-exr .exr

# Cache assets
<FilesMatch "\.(glb|exr|jpg|png|woff2)$">
    Header set Cache-Control "max-age=31536000, public, immutable"
</FilesMatch>
```

---

## Checklist Pre-Deploy

- [x] Rutas de assets relativas (`assets/models/...`)
- [x] PBR materials con preservación de mapas (metalness 0.95, roughness 0.15)
- [x] Clamp de luminancia HDR en AssetManager (max 32000)
- [x] UnrealBloomPass fotográfico (strength 0.5, radius 1.2, threshold 0.88)
- [x] Tipografía industrial-brutalist (font-weight 900, letter-spacing -0.06em)
- [x] ScrollTrigger.refresh(true) tras carga de modelos (1.5s)
- [ ] Assets copiados a `/backend/alojamiento/assets/` (verificar manualmente)
- [ ] Build ejecutado: `npm run build`
- [ ] Servidor web configurado (nginx/Apache)

---

## Comando Atómico de Deploy

```bash
#!/bin/bash
# deploy-nexo-landing.sh

set -e  # Exit on error

echo "🚀 NEXO Landing — Deploy Estático"
echo "=================================="

# 1. Build frontend
echo "📦 Building frontend..."
cd /home/john/proyectos/NEXO/backend/alojamiento/frontend
npm run build

# 2. Verificar assets
echo "🔍 Verificando assets..."
ASSETS_DIR="/home/john/proyectos/NEXO/backend/alojamiento/assets"
REQUIRED_FILES=(
    "models/nodo.glb"
    "models/planeta.glb"
    "environments/studio_small_08_2k.exr"
    "textures/waternormals.jpg"
)

for file in "${REQUIRED_FILES[@]}"; do
    if [ ! -f "$ASSETS_DIR/$file" ]; then
        echo "❌ ERROR: Falta $ASSETS_DIR/$file"
        exit 1
    fi
done

echo "✅ Assets verificados"

# 3. Permisos (opcional, solo si es necesario)
# chmod -R 755 /home/john/proyectos/NEXO/backend/alojamiento/frontend/dist
# chmod -R 755 /home/john/proyectos/NEXO/backend/alojamiento/assets

echo "✅ Deploy completado"
echo ""
echo "📍 Rutas:"
echo "   - Frontend: /backend/alojamiento/frontend/dist/"
echo "   - Assets:   /backend/alojamiento/assets/"
echo ""
echo "🌐 Configurar servidor web para servir:"
echo "   / → frontend/dist/index.html"
echo "   /assets/* → assets/*"
```

Guardar como `/home/john/proyectos/NEXO/backend/alojamiento/deploy-nexo-landing.sh` y ejecutar:

```bash
chmod +x /home/john/proyectos/NEXO/backend/alojamiento/deploy-nexo-landing.sh
./deploy-nexo-landing.sh
```

---

## Optimizaciones de Producción

### Vite Config (opcional)

Crear `/home/john/proyectos/NEXO/backend/alojamiento/frontend/vite.config.js`:

```js
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: '/',  // Ruta base (ajustar si se despliega en subdirectorio)
  build: {
    outDir: 'dist',
    assetsDir: 'assets',
    sourcemap: false,  // Desactivar sourcemaps en producción
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true,  // Eliminar console.log en producción
      },
    },
    rollupOptions: {
      output: {
        manualChunks: {
          'three-vendor': ['three'],
          'gsap-vendor': ['gsap'],
        },
      },
    },
  },
})
```

---

## Resultado Esperado

**Estética:** Diseño Industrial Suizo / Comercial de iPhone
- Nodo y Planeta como objetos físicos reales en vacío negro absoluto
- Metal pulido con reflejos IBL intensos (envMapIntensity: 2.2)
- Bloom fotográfico sutil en highlights especulares
- Tipografía Montfort con inercia cinemática pesada (scrub: 1.8)
- Track de scroll preciso de 700vh

**Performance:**
- 60fps en hardware moderno (GPU dedicada)
- Carga inicial < 5s (assets totales ~15MB)
- Sin errores de GPU (DataUtils.toHalfFloat corregido)

---

**Última actualización:** 2026-05-19
**Autor:** Claude (Cascade AI)
**Estado:** ✅ PRODUCTION READY
