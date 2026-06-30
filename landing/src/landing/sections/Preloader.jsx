import { useEffect, useRef, useState } from 'react'
import gsap from 'gsap'

// Preloader — timer-based (no R3F dependency needed since canvases are inline now)
// Slides up with power4.inOut after 1.8s or when dismissed

export default function Preloader() {
  const overlayRef = useRef()
  const [progress, setProgress] = useState(0)
  const dismissed = useRef(false)

  const dismiss = () => {
    if (dismissed.current) return
    dismissed.current = true
    if (overlayRef.current) {
      gsap.to(overlayRef.current, {
        yPercent: -100,
        duration: 1.1,
        ease: 'power4.inOut',
        delay: 0.15,
        onComplete: () => {
          if (overlayRef.current) overlayRef.current.style.display = 'none'
        },
      })
    }
  }

  useEffect(() => {
    // Animate progress bar from 0 → 100 over ~1.6s
    let start = null
    const duration = 1600

    const tick = (ts) => {
      if (!start) start = ts
      const p = Math.min(((ts - start) / duration) * 100, 100)
      setProgress(Math.round(p))
      if (p < 100) requestAnimationFrame(tick)
      else dismiss()
    }
    requestAnimationFrame(tick)

    // Hard safety cap
    const t = setTimeout(dismiss, 4000)
    return () => clearTimeout(t)
  }, [])

  return (
    <div
      ref={overlayRef}
      style={{
        position: 'fixed', inset: 0, zIndex: 9999,
        background: '#f7fcf7',
        display: 'flex', flexDirection: 'column',
        alignItems: 'center', justifyContent: 'center',
        gap: '1.5rem',
      }}
    >
      {/* NEXO logo */}
      <img
        src="/assets/logo/logo_nexo.png"
        alt="NEXO"
        width="52"
        height="52"
        style={{ animation: 'nxPulse 1.8s ease-in-out infinite', objectFit: 'contain' }}
      />

      {/* Progress bar */}
      <div style={{ width: '96px', height: '1px', background: 'rgba(200, 230, 200, 0.6)', borderRadius: '1px', overflow: 'hidden' }}>
        <div
          style={{
            height: '100%',
            background: 'linear-gradient(90deg, #2d6e30, rgba(45, 110, 48, 0.45))',
            borderRadius: '1px',
            width: `${progress}%`,
            transition: 'width 0.1s linear',
            boxShadow: '0 0 8px rgba(45, 110, 48, 0.5)',
          }}
        />
      </div>

      <span style={{
        fontFamily: "'Plus Jakarta Sans', sans-serif",
        fontSize: '0.62rem',
        letterSpacing: '0.18em',
        color: '#4a6e4c',
        textTransform: 'uppercase',
      }}>
        {progress}%
      </span>

      <style>{`
        @keyframes nxPulse {
          0%, 100% { opacity: 1;   transform: scale(1); }
          50%       { opacity: 0.45; transform: scale(0.92); }
        }
      `}</style>
    </div>
  )
}
