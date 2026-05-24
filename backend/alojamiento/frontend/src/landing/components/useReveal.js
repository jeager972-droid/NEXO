import { useEffect, useRef } from 'react'

// Shared IntersectionObserver hook for scroll reveal
export function useReveal(ref, options = {}) {
  useEffect(() => {
    if (!ref.current) return
    const els = ref.current.querySelectorAll('.nx-reveal')
    if (!els.length) return

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible')
          }
        })
      },
      { threshold: 0.15, rootMargin: '0px 0px -40px 0px', ...options }
    )
    els.forEach(el => observer.observe(el))
    return () => observer.disconnect()
  }, [])
}
