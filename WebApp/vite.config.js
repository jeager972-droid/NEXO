import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// https://vitejs.dev/config/
export default defineConfig({
  base: '/app/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: true,
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
      includeAssets: ['favicon.svg', 'apple-touch-icon.svg', 'mask-icon.svg'],
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
              cacheableResponse: { statuses: [0, 200] },
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
            icons: [{ src: '/pwa-icon.svg', sizes: 'any', type: 'image/svg+xml' }]
          },
          {
            name: 'Operación',
            short_name: 'Operación',
            url: '/app/operacion',
            icons: [{ src: '/pwa-icon.svg', sizes: 'any', type: 'image/svg+xml' }]
          }
        ],
        icons: [
          {
            src: '/pwa-icon.svg',
            sizes: 'any',
            type: 'image/svg+xml'
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
