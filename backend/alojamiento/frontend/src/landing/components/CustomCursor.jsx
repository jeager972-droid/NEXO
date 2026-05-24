import { useEffect, useRef } from 'react'
import gsap from 'gsap'

// CAMBIO 6: Cursor personalizado global
// — Punto sólido 6px: sigue al cursor con lerp rápido (lerp 1 = inmediato)
// — Círculo exterior 32px borde 1px: sigue con más inercia (lerp 0.10)
// — Hover sobre [data-cursor="pointer"]: círculo escala a 48px + color acento
// Implementado con requestAnimationFrame puro, sin librerías adicionales

export default function CustomCursor() {
  const dotRef    = useRef()
  const ringRef   = useRef()

  useEffect(() => {
    const dot  = dotRef.current
    const ring = ringRef.current
    if (!dot || !ring) return

    let mouseX = window.innerWidth / 2
    let mouseY = window.innerHeight / 2
    let ringX  = mouseX
    let ringY  = mouseY
    let rafId

    // Lerp helper
    const lerp = (a, b, t) => a + (b - a) * t

    // RAF loop — dot inmediato, ring con inercia 0.10
    const tick = () => {
      ringX = lerp(ringX, mouseX, 0.10)
      ringY = lerp(ringY, mouseY, 0.10)

      // Dot — posición directa (transform para GPU)
      dot.style.transform  = `translate(${mouseX - 3}px, ${mouseY - 3}px)`
      // Ring — posición con inercia
      ring.style.transform = `translate(${ringX - 16}px, ${ringY - 16}px)`

      rafId = requestAnimationFrame(tick)
    }
    rafId = requestAnimationFrame(tick)

    // Rastrear posición del mouse
    const onMove = (e) => {
      mouseX = e.clientX
      mouseY = e.clientY
    }

    // Hover sobre elementos interactivos — ring escala + cambia color
    const INTERACTIVE = 'a, button, [role="button"], [data-cursor="pointer"], .nx-hotspot, .nx-tab, .nx-platform-card'

    const onEnterInteractive = () => {
      gsap.to(ring, { scale: 1.5, borderColor: 'var(--nx-blue)', opacity: 0.8, duration: 0.3, ease: 'power2.out' })
      gsap.to(dot,  { scale: 0,   duration: 0.2, ease: 'power2.out' })
    }
    const onLeaveInteractive = () => {
      gsap.to(ring, { scale: 1,   borderColor: 'rgba(255,255,255,0.35)', opacity: 0.6, duration: 0.3, ease: 'power2.inOut' })
      gsap.to(dot,  { scale: 1,   duration: 0.2, ease: 'power2.inOut' })
    }

    // Delegación de eventos para todos los interactivos
    const handleOver = (e) => { if (e.target.closest(INTERACTIVE)) onEnterInteractive() }
    const handleOut  = (e) => { if (e.target.closest(INTERACTIVE)) onLeaveInteractive() }

    window.addEventListener('mousemove', onMove, { passive: true })
    document.addEventListener('mouseover', handleOver)
    document.addEventListener('mouseout',  handleOut)

    // Ocultar en dispositivos táctiles
    const isTouchDevice = window.matchMedia('(hover: none)').matches
    if (isTouchDevice) {
      dot.style.display  = 'none'
      ring.style.display = 'none'
    }

    return () => {
      cancelAnimationFrame(rafId)
      window.removeEventListener('mousemove', onMove)
      document.removeEventListener('mouseover', handleOver)
      document.removeEventListener('mouseout',  handleOut)
    }
  }, [])

  return (
    <>
      {/* Punto sólido — 6px */}
      <div
        ref={dotRef}
        aria-hidden="true"
        style={{
          position:      'fixed',
          top:            0,
          left:           0,
          width:         '6px',
          height:        '6px',
          borderRadius:  '50%',
          background:    'white',
          pointerEvents: 'none',
          zIndex:         99999,
          willChange:    'transform',
        }}
      />
      {/* Círculo exterior — 32px con inercia */}
      <div
        ref={ringRef}
        aria-hidden="true"
        style={{
          position:      'fixed',
          top:            0,
          left:           0,
          width:         '32px',
          height:        '32px',
          borderRadius:  '50%',
          border:        '1px solid rgba(255,255,255,0.35)',
          pointerEvents: 'none',
          zIndex:         99998,
          opacity:        0.6,
          willChange:    'transform',
        }}
      />
    </>
  )
}
