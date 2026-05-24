import { useEffect } from 'react'
import Lenis from '@studio-freight/lenis'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

gsap.registerPlugin(ScrollTrigger)

// CAMBIO 6: Lenis smooth scroll integrado con GSAP ScrollTrigger
// Lenis → requestAnimationFrame → ScrollTrigger.update() en cada tick
// Referencia: Linear.app, Monfort — scroll con inercia institucional

export function useLenis() {
  useEffect(() => {
    const lenis = new Lenis({
      duration:   1.25,           // duración de la inercia (segundos)
      easing:     t => 1 - Math.pow(1 - t, 4),  // ease-out-quart
      orientation: 'vertical',
      smoothWheel: true,
      wheelMultiplier: 0.9,
    })

    // Sincroniza Lenis con GSAP ScrollTrigger en cada RAF
    lenis.on('scroll', ScrollTrigger.update)

    const rafId = gsap.ticker.add(time => lenis.raf(time * 1000))
    gsap.ticker.lagSmoothing(0)  // evita saltos si el tab pierde foco

    return () => {
      gsap.ticker.remove(rafId)
      lenis.destroy()
    }
  }, [])
}
