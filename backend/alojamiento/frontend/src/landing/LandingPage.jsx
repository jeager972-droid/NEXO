import { useEffect, useRef } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

import HeroSection          from './sections/HeroSection'
import VisionSection        from './sections/VisionSection'
import ExplodedSection      from './sections/ExplodedSection'
import CifradoSection       from './sections/CifradoSection'
import EcosistemaSection    from './sections/EcosistemaSection'
import EscalabilidadSection from './sections/EscalabilidadSection'
import DescargasSection     from './sections/DescargasSection'

gsap.registerPlugin(ScrollTrigger)

export default function LandingPage() {
  const containerRef = useRef()

  useEffect(() => {
    const sections = gsap.utils.toArray('[data-section]', containerRef.current)
    if (!sections.length) return

    const ctx = gsap.context(() => {
      // Toggle active state based on active section
      sections.forEach((section, i) => {
        ScrollTrigger.create({
          trigger: section,
          start:  'top center',
          end:    'bottom center',
          onEnter: () => {
            sections.forEach((s, idx) => {
              s.setAttribute('data-active', idx === i ? 'true' : 'false')
            })
          },
          onEnterBack: () => {
            sections.forEach((s, idx) => {
              s.setAttribute('data-active', idx === i ? 'true' : 'false')
            })
          },
        })
      })
    }, containerRef)

    return () => ctx.revert()
  }, [])

  return (
    <div
      id="nx-landing"
      ref={containerRef}
      style={{ position: 'relative', zIndex: 1 }}
    >
      <HeroSection />
      <VisionSection />
      <ExplodedSection />
      <CifradoSection />
      <EcosistemaSection />
      <EscalabilidadSection />
      <DescargasSection />
    </div>
  )
}
