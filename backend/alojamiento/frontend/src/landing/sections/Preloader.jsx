import { useEffect, useRef, useState } from 'react'
import { useProgress } from '@react-three/drei'
import gsap from 'gsap'

export default function Preloader() {
  const overlayRef = useRef()
  const { progress, active } = useProgress()
  const [dismissed, setDismissed] = useState(false)

  const dismiss = () => {
    if (dismissed) return
    setDismissed(true)
    if (overlayRef.current) {
      gsap.to(overlayRef.current, {
        yPercent: -100,
        duration: 1.1,
        ease: 'power4.inOut',
        delay: 0.2,
        onComplete: () => {
          if (overlayRef.current) overlayRef.current.style.display = 'none'
        },
      })
    }
  }

  useEffect(() => {
    // Dismiss when loading is done
    if (!active && progress >= 99) {
      dismiss()
    }
  }, [active, progress])

  useEffect(() => {
    // Safety timeout: never block the page more than 4 seconds
    const t = setTimeout(dismiss, 4000)
    return () => clearTimeout(t)
  }, [])

  return (
    <div
      ref={overlayRef}
      style={{
        position: 'fixed', inset: 0, zIndex: 9999,
        background: '#111614',
        display: 'flex', flexDirection: 'column',
        alignItems: 'center', justifyContent: 'center',
        gap: '1.5rem',
      }}
    >
      {/* NEXO isotipo SVG */}
      <svg width="64" height="64" viewBox="0 0 64 64" fill="none"
        style={{ animation: 'nx-pulse 1.8s ease-in-out infinite' }}
      >
        <rect x="2" y="2" width="60" height="60" rx="12" stroke="#00e676" strokeWidth="2.5" />
        <path d="M14 50L32 14L50 50" stroke="#00e676" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M20 38H44" stroke="#00e676" strokeWidth="2.5" strokeLinecap="round" />
      </svg>

      {/* Progress bar */}
      <div style={{ width: '120px', height: '2px', background: '#1e2a24', borderRadius: '1px', overflow: 'hidden' }}>
        <div style={{
          height: '100%', background: '#00e676', borderRadius: '1px',
          width: `${progress}%`, transition: 'width 0.3s ease',
        }} />
      </div>

      <span style={{
        fontFamily: "'JetBrains Mono', monospace",
        fontSize: '0.7rem', letterSpacing: '0.2em',
        color: '#6b7f74', textTransform: 'uppercase',
      }}>
        {Math.round(progress)}%
      </span>

      <style>{`
        @keyframes nx-pulse {
          0%, 100% { opacity: 1; transform: scale(1); }
          50%       { opacity: 0.6; transform: scale(0.95); }
        }
      `}</style>
    </div>
  )
}
