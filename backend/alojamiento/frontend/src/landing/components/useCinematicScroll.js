import { useEffect } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

/**
 * Cinematic section transitions using GSAP ScrollTrigger pin.
 *
 * Desktop:
 *   Each section inner is pinned to the viewport while its wrapper scrolls.
 *   Timeline phases (0%-100% of pinned scroll distance):
 *     0%-30%  → entrance: section rises from below, starts blurred / scaled-up / transparent
 *     30%-70% → hold: section fully visible and idle
 *     70%-100%→ exit: section shrinks, fades, blurs, and drifts upward
 *
 * Mobile:
 *   IntersectionObserver reveal (no pin, no sticky) to avoid scroll hijacking.
 */
export function useCinematicScroll(
  wrapperRef,
  innerRef,
  { isFirst = false, isLast = false } = {}
) {
  useEffect(() => {
    const wrapper = wrapperRef.current
    const inner   = innerRef.current
    if (!wrapper || !inner) return

    const isMobile = window.innerWidth <= 768

    /* ── MOBILE ── */
    if (isMobile) {
      gsap.set(inner, { clearProps: 'all' })
      if (!isFirst) {
        gsap.set(inner, { opacity: 0, y: 30, scale: 0.96 })
        const observer = new IntersectionObserver(
          ([entry]) => {
            if (entry.isIntersecting) {
              gsap.to(inner, {
                opacity: 1, y: 0, scale: 1,
                duration: 0.8, ease: 'power3.out',
              })
              observer.disconnect()
            }
          },
          { threshold: 0.08 }
        )
        observer.observe(inner)
        return () => observer.disconnect()
      }
      gsap.set(inner, { opacity: 1, y: 0, scale: 1 })
      return
    }

    /* ── DESKTOP ──
       Pin the section-inner to viewport. The wrapper provides the scroll
       distance (+=120% of viewport height). During that distance we run
       a scrubbed timeline for entrance → hold → exit.
    */
    const ctx = gsap.context(() => {
      const tl = gsap.timeline({
        scrollTrigger: {
          trigger: wrapper,
          start: 'top top',
          end: '+=120%',
          pin: inner,
          scrub: 1.2,
          anticipatePin: 1,
          invalidateOnRefresh: true,
        },
      })

      /* Phase 1 — ENTRANCE (0% → 30%)
         Section arrives from below, slightly oversized, blurred and transparent.
         First section skips this (already visible on load). */
      if (!isFirst) {
        tl.fromTo(
          inner,
          {
            yPercent: 30,
            opacity: 0,
            scale: 1.06,
            filter: 'blur(10px)',
          },
          {
            yPercent: 0,
            opacity: 1,
            scale: 1,
            filter: 'blur(0px)',
            ease: 'none',
            duration: 0.30,
          },
          0
        )
      }

      /* Phase 2 — EXIT (70% → 100%)
         Section drifts upward, shrinks, fades, and blurs out.
         Last section skips this (stays visible until footer). */
      if (!isLast) {
        tl.fromTo(
          inner,
          {
            yPercent: 0,
            opacity: 1,
            scale: 1,
            filter: 'blur(0px)',
          },
          {
            yPercent: -22,
            opacity: 0,
            scale: 0.92,
            filter: 'blur(8px)',
            ease: 'none',
            duration: 0.30,
          },
          0.70
        )
      }
    })

    return () => ctx.revert()
  }, [wrapperRef, innerRef, isFirst, isLast])
}
