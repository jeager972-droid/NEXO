import { useEffect } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

export function useStickyScroll(wrapperRef, innerRef, { isFirst = false, isLast = false } = {}) {
  useEffect(() => {
    const wrapper = wrapperRef.current
    const inner = innerRef.current
    if (!wrapper || !inner) return

    const ctx = gsap.context(() => {

      // ENTRADA: la sección emerge desde abajo al hacer scroll down
      // scrub bidireccional — al subir regresa suavemente a su estado "from"
      if (!isFirst) {
        gsap.fromTo(inner,
          { yPercent: 6, opacity: 0, scale: 0.98 },
          {
            yPercent: 0,
            opacity: 1,
            scale: 1,
            ease: 'none',        // ease:none es OBLIGATORIO para scrub bidireccional
            scrollTrigger: {
              trigger: wrapper,
              start: 'top 90%',
              end: 'top 20%',
              scrub: 0.8,
            }
          }
        )
      } else {
        // Primera sección: siempre completamente visible
        gsap.set(inner, { yPercent: 0, opacity: 1, scale: 1 })
      }

      // SALIDA: la sección sube y desaparece al hacer scroll down
      // scrub bidireccional — al subir regresa visiblemente
      if (!isLast) {
        gsap.fromTo(inner,
          { yPercent: 0, opacity: 1, scale: 1 },
          {
            yPercent: -6,
            opacity: 0,
            scale: 0.98,
            ease: 'none',        // ease:none es OBLIGATORIO para scrub bidireccional
            scrollTrigger: {
              trigger: wrapper,
              start: 'bottom 30%',
              end: 'bottom top',
              scrub: 0.8,
            }
          }
        )
      }

    })

    return () => ctx.revert()
  }, [wrapperRef, innerRef, isFirst, isLast])
}
