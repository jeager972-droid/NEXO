import { useEffect } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

gsap.registerPlugin(ScrollTrigger)

// Custom hook to apply the sticky scroll architecture transition animations.
// It handles entrance and exit animations reactively with GSAP ScrollTrigger.
export function useStickyScroll(wrapperRef, innerRef, { isFirst = false, isLast = false } = {}) {
  useEffect(() => {
    const wrapper = wrapperRef.current
    const inner = innerRef.current
    if (!wrapper || !inner) return

    const ctx = gsap.context(() => {
      // PASO 3: ANIMACIÓN DE ENTRADA (la sección emerge desde abajo)
      if (!isFirst) {
        gsap.fromTo(inner, 
          { yPercent: 8, opacity: 0, scale: 0.98 },
          {
            yPercent: 0,
            opacity: 1,
            scale: 1,
            ease: "power3.out",
            scrollTrigger: {
              trigger: wrapper,
              start: "top 90%",
              end: "top 20%",
              scrub: 1,
            }
          }
        )
      } else {
        // En la primera sección, nos aseguramos que esté totalmente visible al cargar
        gsap.set(inner, { yPercent: 0, opacity: 1, scale: 1 })
      }

      // PASO 3: ANIMACIÓN DE SALIDA (la sección actual sube y desaparece)
      if (!isLast) {
        gsap.to(inner, {
          yPercent: -15,
          opacity: 0,
          scale: 0.96,
          ease: "power2.inOut",
          scrollTrigger: {
            trigger: wrapper,
            start: "bottom 85%",
            end: "bottom 20%",
            scrub: 1.2,
          }
        })
      }
    })

    return () => ctx.revert()
  }, [wrapperRef, innerRef, isFirst, isLast])
}
