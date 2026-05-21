import { useRef } from 'react'
import LandingPage from './landing/LandingPage'
import Preloader from './landing/sections/Preloader'
import ErrorBoundary from './landing/components/ErrorBoundary'
import GlobalCanvas from './components/canvas/GlobalCanvas'

export default function App() {
  const containerRef = useRef(null)

  return (
    <ErrorBoundary>
      <div ref={containerRef} id="nx-app-container" style={{ position: 'relative', width: '100%' }}>
        <Preloader />
        <LandingPage />
        <GlobalCanvas eventSource={containerRef} />
      </div>
    </ErrorBoundary>
  )
}

