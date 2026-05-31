import { Suspense } from 'react'
import { BrowserRouter, Routes, Route } from 'react-router-dom'
import LandingPage   from './landing/LandingPage'
import DashboardPage from './dashboard/DashboardPage'
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
            <Route path="/dashboard" element={<DashboardPage />} />
          </Routes>
        </Suspense>
      </ErrorBoundary>
    </BrowserRouter>
  )
}
