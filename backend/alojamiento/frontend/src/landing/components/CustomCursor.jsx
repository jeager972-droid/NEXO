import { useEffect, useRef } from 'react'
import gsap from 'gsap'

// PASO 5 — CURSOR PERSONALIZADO
// Implementa un cursor de dos capas sin librerías externas

export default function CustomCursor() {
  const dotRef = useRef()
  const ringRef = useRef()

  useEffect(() => {
    // Solo en mobile/touch: no instancies el cursor personalizado
    if (window.matchMedia('(pointer: coarse)').matches) return

    document.body.classList.add('custom-cursor-active')

    const dot = dotRef.current
    const ring = ringRef.current
    if (!dot || !ring) return

    let mouseX = 0, mouseY = 0
    let ringX = 0, ringY = 0
    const lerpFactor = 0.12

    const onMouseMove = (e) => {
      mouseX = e.clientX
      mouseY = e.clientY
      gsap.set(dot, { x: mouseX, y: mouseY })
    }

    document.addEventListener('mousemove', onMouseMove)

    const animateRing = () => {
      ringX += (mouseX - ringX) * lerpFactor
      ringY += (mouseY - ringY) * lerpFactor
      gsap.set(ring, { x: ringX, y: ringY })
      requestAnimationFrame(animateRing)
    }
    animateRing()

    // Hover state en elementos interactivos
    const interactives = document.querySelectorAll(
      'button, a, [data-cursor-expand], [role="button"], .nx-hotspot, .nx-tab'
    )
    
    const handleMouseEnter = () => {
      ring.style.width = '48px'
      ring.style.height = '48px'
      ring.style.borderColor = 'rgba(99, 179, 237, 0.8)'
    }

    const handleMouseLeave = () => {
      ring.style.width = '32px'
      ring.style.height = '32px'
      ring.style.borderColor = 'rgba(255,255,255,0.5)'
    }

    interactives.forEach(el => {
      el.addEventListener('mouseenter', handleMouseEnter)
      el.addEventListener('mouseleave', handleMouseLeave)
    })

    return () => {
      document.body.classList.remove('custom-cursor-active')
      document.removeEventListener('mousemove', onMouseMove)
      interactives.forEach(el => {
        el.removeEventListener('mouseenter', handleMouseEnter)
        el.removeEventListener('mouseleave', handleMouseLeave)
      })
    }
  }, [])

  // Si es pantalla táctil/mobile, no renderiza nada
  if (typeof window !== 'undefined' && window.matchMedia('(pointer: coarse)').matches) {
    return null
  }

  return (
    <>
      <div id="cursor-dot" ref={dotRef} />
      <div id="cursor-ring" ref={ringRef} />
    </>
  )
}
