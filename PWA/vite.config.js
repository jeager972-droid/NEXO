/**
 * WebApp build config / NEXO Institucional
 * Responsabilidad: Configurar Vite para la SPA con basename /app/, code-splitting
 * manual (react, ui) y PWA con Workbox: cache de assets estáticos, API con NetworkFirst
 * y cola backgroundSync para POST /v1.
 * Dependencias: vite, @vitejs/plugin-react, vite-plugin-pwa.
 * Nota: base '/app/' requiere que las reglas de reescritura del servidor apunten a dist/index.html.
 */
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// https://vitejs.dev/config/
export default defineConfig({
  base: '/app/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: 'hidden',
    target: 'es2020',
    cssCodeSplit: true,
    rollupOptions: {
      output: {
        manualChunks: {
          react: ['react', 'react-dom', 'react-router-dom'],
          ui: ['lucide-react']
        }
      }
    }
  },
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.svg', 'apple-touch-icon.svg', 'mask-icon.svg', 'logo/logo_nexo_app.png', 'logo/icon-192.png', 'logo/icon-512.png', 'logo/icon-180.png'],
      workbox: {
        navigateFallback: '/app/index.html',
        // Forzar activacion inmediata del nuevo SW sin esperar a que se cierren
        // todas las pestanas. Esto asegura que los usuarios obtengan la nueva
        // version de assets (logos, JS) sin tener que cerrar manualmente.
        skipWaiting: true,
        clientsClaim: true,
        // Limpiar caches viejas automaticamente al activar un nuevo SW.
        cleanupOutdatedCaches: true,
        runtimeCaching: [
          {
            urlPattern: ({ url, request }) => url.pathname.startsWith('/v1') && request.method === 'POST',
            handler: 'NetworkOnly',
            options: {
              backgroundSync: {
                name: 'nexo-post-queue',
                options: {
                  maxRetentionTime: 24 * 60
                }
              }
            }
          },
          {
            urlPattern: ({ url }) => url.pathname.startsWith('/v1'),
            handler: 'NetworkFirst',
            options: {
              cacheName: 'nexo-api-cache',
              networkTimeoutSeconds: 3,
              cacheableResponse: { statuses: [200] },
              expiration: { maxEntries: 30, maxAgeSeconds: 60 * 2 }
            }
          },
          {
            // Assets con hash (JS, CSS) — CacheFirst porque el hash cambia con cada build.
            urlPattern: ({ request }) => request.destination === 'script' || request.destination === 'style',
            handler: 'CacheFirst',
            options: {
              cacheName: 'nexo-hashed-assets',
              expiration: { maxEntries: 60, maxAgeSeconds: 60 * 60 * 24 * 90 }
            }
          },
          {
            // Imagenes (logos, iconos) — StaleWhileRevalidate para actualizar en background
            urlPattern: ({ request }) => request.destination === 'image',
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'nexo-images',
              expiration: { maxEntries: 50, maxAgeSeconds: 60 * 60 * 24 * 7 }
            }
          }
        ]
      },
      manifest: {
        id: '/app/',
        name: 'NEXO',
        short_name: 'NEXO',
        description: 'Plataforma Institucional de Gestión',
        start_url: '/app/',
        scope: '/app/',
        display: 'standalone',
        orientation: 'portrait',
        theme_color: '#1e40af',
        background_color: '#ffffff',
        lang: 'es-CO',
        shortcuts: [
          {
            name: 'Panel',
            short_name: 'Panel',
            url: '/app/',
            icons: [{ src: '/app/logo/logo_app.jpg', sizes: 'any', type: 'image/jpeg' }]
          },
          {
            name: 'Operación',
            short_name: 'Operación',
            url: '/app/operacion',
            icons: [{ src: '/app/logo/logo_app.jpg', sizes: 'any', type: 'image/jpeg' }]
          }
        ],
        icons: [
          {
            src: '/app/logo/icon-192.png',
            sizes: '192x192',
            type: 'image/png',
            purpose: 'any'
          },
          {
            src: '/app/logo/icon-512.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any'
          },
          {
            src: '/app/logo/icon-180.png',
            sizes: '180x180',
            type: 'image/png',
            purpose: 'any'
          },
          {
            src: '/app/mask-icon.svg',
            sizes: 'any',
            type: 'image/svg+xml',
            purpose: 'maskable'
          }
        ]
      }
    })
  ],
})
