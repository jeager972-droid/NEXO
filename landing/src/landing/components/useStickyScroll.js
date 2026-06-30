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

    // DESKTOP — simple fade-in, no transforms that clip or distort
    const ctx = gsap.context(() => {
      if (!isFirst) {
        gsap.fromTo(inner,
          { opacity: 0, y: 20 },
          {
            opacity: 1, y: 0,
            duration: 0.6,
            ease: 'power2.out',
            scrollTrigger: {
              trigger: wrapper,
              start: 'top 85%',
              toggleActions: 'play none none none',
            }
          }
        )
      } else {
        gsap.set(inner, { opacity: 1, y: 0 })
      }
    })

    return () => ctx.revert()
  }, [wrapperRef, innerRef, isFirst, isLast])
}
