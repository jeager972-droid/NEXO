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
        background: '#05070F',
        display: 'flex', flexDirection: 'column',
        alignItems: 'center', justifyContent: 'center',
        gap: '1.5rem',
      }}
    >
      {/* NEXO isotipo */}
      <svg
        width="52"
        height="52"
        viewBox="0 0 64 64"
        fill="none"
        style={{ animation: 'nxPulse 1.8s ease-in-out infinite' }}
        aria-label="NEXO"
      >
        <rect x="2" y="2" width="60" height="60" rx="12" stroke="#0A84FF" strokeWidth="2" />
        <path d="M14 50L32 14L50 50" stroke="#0A84FF" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
        <path d="M20 38H44" stroke="#0A84FF" strokeWidth="2" strokeLinecap="round" />
      </svg>

      {/* Progress bar */}
      <div style={{ width: '96px', height: '1px', background: '#1C2B4A', borderRadius: '1px', overflow: 'hidden' }}>
        <div
          style={{
            height: '100%',
            background: 'linear-gradient(90deg, #0A84FF, rgba(10,132,255,0.45))',
            borderRadius: '1px',
            width: `${progress}%`,
            transition: 'width 0.1s linear',
            boxShadow: '0 0 8px rgba(10,132,255,0.5)',
          }}
        />
      </div>

      <span style={{
        fontFamily: "'Plus Jakarta Sans', sans-serif",
        fontSize: '0.62rem',
        letterSpacing: '0.18em',
        color: '#4A5878',
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
