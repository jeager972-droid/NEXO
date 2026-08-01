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
      includeAssets: ['favicon.svg', 'apple-touch-icon.svg', 'mask-icon.svg', 'logo/logo_nexo_app.png'],
      workbox: {
        navigateFallback: '/app/index.html',
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
              networkTimeoutSeconds: 5,
              // BUGFIX: Status 0 = opaque/CORS failure. Cachearlo sirve datos corruptos.
              cacheableResponse: { statuses: [200] },
              expiration: { maxEntries: 50, maxAgeSeconds: 60 * 5 }
            }
          },
          {
            urlPattern: ({ request }) => request.destination === 'script' || request.destination === 'style' || request.destination === 'image',
            handler: 'StaleWhileRevalidate',
            options: {
              cacheName: 'nexo-static-assets',
              expiration: { maxEntries: 120, maxAgeSeconds: 60 * 60 * 24 * 30 }
            }
          }
        ]
      },
      manifest: {
        id: '/app/',
        name: 'NEXO Institucional',
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
            icons: [{ src: '/logo/logo_nexo_app.png', sizes: 'any', type: 'image/png' }]
          },
          {
            name: 'Operación',
            short_name: 'Operación',
            url: '/app/operacion',
            icons: [{ src: '/logo/logo_nexo_app.png', sizes: 'any', type: 'image/png' }]
          }
        ],
        icons: [
          {
            src: '/logo/logo_nexo_app.png',
            sizes: 'any',
            type: 'image/png'
          },
          {
            src: '/mask-icon.svg',
            sizes: 'any',
            type: 'image/svg+xml',
            purpose: 'any maskable'
          }
        ]
      }
    })
  ],
})
