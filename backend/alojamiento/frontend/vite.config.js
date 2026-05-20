import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  base: '/',
  build: {
    outDir: '../',
    emptyOutDir: false,
    sourcemap: false,
    target: 'es2020',
    cssCodeSplit: true,
    minify: 'esbuild',
    rollupOptions: {
      output: {
        manualChunks: {
          'three-vendor':  ['three', '@react-three/fiber', '@react-three/drei'],
          'gsap-vendor':   ['gsap'],
        }
      }
    }
  },
  plugins: [react(), tailwindcss()],
  server: {
    port: 3000,
    strictPort: false
  }
})
