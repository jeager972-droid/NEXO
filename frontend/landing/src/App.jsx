/**
 * =============================================================================
 * App.jsx — Router raíz de la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Envuelve la aplicación en BrowserRouter, ErrorBoundary y Suspense. Define
 *   la ruta raíz `/` que renderiza <LandingPage />. El preloader se muestra
 *   globalmente mientras carga.
 *
 * RUTAS:
 *   - / → LandingPage (marketing site)
 *
 * DEPENDENCIAS:
 *   - react-router-dom
 *   - LandingPage, Preloader, ErrorBoundary
 * =============================================================================
 */

import { Suspense } from 'react'
import { BrowserRouter, Routes, Route } from 'react-router-dom'
import LandingPage   from './landing/LandingPage'

import Preloader     from './landing/sections/Preloader'
import ErrorBoundary from './landing/components/ErrorBoundary'

// NexoCanvas instances are embedded directly inside HeroSection and NodeSection
// as standalone R3F <Canvas> elements — no global View.Port canvas needed.

export default function App() {
  return (
    <BrowserRouter>
      <ErrorBoundary>
        <Suspense fallback={null}>
          <Preloader />
          <Routes>
            <Route path="/" element={<LandingPage />} />

          </Routes>
        </Suspense>
      </ErrorBoundary>
    </BrowserRouter>
  )
}
