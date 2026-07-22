/**
 * =============================================================================
 * vite.config.js — Configuración de build para la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Configura Vite para desarrollo y producción: plugins de React, Tailwind v4,
 *   compresión gzip/brotli, code-splitting manual por vendor, optimizaciones
 *   Terser (drop_console, drop_debugger) y salida a `dist/`.
 *
 * DEPENDENCIAS:
 *   - @vitejs/plugin-react
 *   - @tailwindcss/vite
 *   - vite-plugin-compression
 *   - terser
 * =============================================================================
 */

import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import viteCompression from 'vite-plugin-compression'

export default defineConfig({
  base: '/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    target: 'es2020',
    cssCodeSplit: true,
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true,
        drop_debugger: true,
        passes: 2
      }
    },
    cssMinify: true,
    reportCompressedSize: false,
    rollupOptions: {
      output: {
        manualChunks: {
          'three': ['three'],
          'three-fiber': ['@react-three/fiber', '@react-three/drei'],
          'gsap': ['gsap', '@studio-freight/lenis'],
          'react-vendor': ['react', 'react-dom']
        }
      }
    }
  },
  plugins: [
    react(), 
    tailwindcss(),
    viteCompression({ algorithm: 'gzip', ext: '.gz' }),
    viteCompression({ algorithm: 'brotliCompress', ext: '.br' })
  ],
  server: {
    port: 3000,
    strictPort: false
  }
})
