import { useRef } from 'react'
import LandingPage from './landing/LandingPage'
import Preloader from './landing/sections/Preloader'
import ErrorBoundary from './landing/components/ErrorBoundary'

export default function App() {
  const scrollRef = useRef(null)

  return (
    <ErrorBoundary>
      {/* ── HTML overlay + scroll sections ── */}
      <Preloader />
      <LandingPage scrollRef={scrollRef} />
    </ErrorBoundary>
  )
}

