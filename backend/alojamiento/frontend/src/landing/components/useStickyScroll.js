import { useEffect } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

export function useStickyScroll(
  wrapperRef,
  innerRef,
  { isFirst = false, isLast = false } = {}
) {
  useEffect(() => {
    const wrapper = wrapperRef.current
    const inner   = innerRef.current
    if (!wrapper || !inner) return

    // En móvil: sin sticky, sin GSAP scroll.
    // Las secciones fluyen normalmente con CSS.
    // Solo aplica un fade-in simple via IntersectionObserver.
    const isMobile = window.innerWidth <= 768

    if (isMobile) {
      // Asegura que todo sea visible en móvil sin animaciones de scroll
      gsap.set(inner, { clearProps: 'all' })

      // Fade-in suave al entrar en viewport
      if (!isFirst) {
        gsap.set(inner, { opacity: 0, y: 20 })
        const observer = new IntersectionObserver(
          ([entry]) => {
            if (entry.isIntersecting) {
              gsap.to(inner, {
                opacity: 1,
                y: 0,
                duration: 0.6,
                ease: 'power2.out',
              })
              observer.disconnect()
            }
          },
          { threshold: 0.1 }
        )
        observer.observe(inner)
        return () => observer.disconnect()
      } else {
        gsap.set(inner, { opacity: 1, y: 0 })
      }
      return
    }

    // DESKTOP: comportamiento sticky original intacto
    const ctx = gsap.context(() => {
      if (!isFirst) {
        gsap.fromTo(inner,
          { yPercent: 6, opacity: 0, scale: 0.98 },
          {
            yPercent: 0,
            opacity: 1,
            scale: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: wrapper,
              start: 'top 90%',
              end: 'top 20%',
              scrub: 0.8,
            }
          }
        )
      } else {
        gsap.set(inner, { yPercent: 0, opacity: 1, scale: 1 })
      }

      if (!isLast) {
        gsap.fromTo(inner,
          { yPercent: 0, opacity: 1, scale: 1 },
          {
            yPercent: -6,
            opacity: 0,
            scale: 0.98,
            ease: 'none',
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
