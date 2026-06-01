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

    const isMobile = window.innerWidth <= 768

    if (isMobile) {
      gsap.set(inner, { clearProps: 'all' })
      if (!isFirst) {
        gsap.set(inner, { opacity: 0, y: 24 })
        const observer = new IntersectionObserver(
          ([entry]) => {
            if (entry.isIntersecting) {
              gsap.to(inner, {
                opacity: 1, y: 0,
                duration: 0.55, ease: 'power2.out',
              })
              observer.disconnect()
            }
          },
          { threshold: 0.08 }
        )
        observer.observe(inner)
        return () => observer.disconnect()
      }
      gsap.set(inner, { opacity: 1, y: 0 })
      return
    }

    // DESKTOP — sticky scroll cinematográfico
    const ctx = gsap.context(() => {
      if (!isFirst) {
        gsap.fromTo(inner,
          { yPercent: 6, opacity: 0, scale: 0.98 },
          {
            yPercent: 0, opacity: 1, scale: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: wrapper,
              start: 'top 90%',
              end:   'top 20%',
              scrub: 0.8,
            }
          }
        )
      } else {
        gsap.set(inner, { yPercent: 0, opacity: 1, scale: 1 })
      }

      // Exit animation removed — scroll now flows freely between sections
    })

    return () => ctx.revert()
  }, [wrapperRef, innerRef, isFirst, isLast])
}
