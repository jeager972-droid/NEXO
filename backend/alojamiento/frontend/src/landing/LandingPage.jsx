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

// Module-level scroll state — plain object, no React re-renders.
// Any component can import and read this to drive GSAP targets.
export const scrollState = {
  activeSection: 'hero',
  progress: 0,
}

export default function LandingPage() {
  const containerRef = useRef()

  useEffect(() => {
    const sections = gsap.utils.toArray('[data-section]', containerRef.current)
    if (!sections.length) return

    const ctx = gsap.context(() => {
      sections.forEach((section, i) => {
        const sectionId = section.dataset.section

        ScrollTrigger.create({
          trigger: section,
          start: 'top center',
          end:   'bottom center',

          onEnter: () => {
            scrollState.activeSection = sectionId
            sections.forEach((s, idx) =>
              s.setAttribute('data-active', idx === i ? 'true' : 'false')
            )
          },

          onEnterBack: () => {
            scrollState.activeSection = sectionId
            sections.forEach((s, idx) =>
              s.setAttribute('data-active', idx === i ? 'true' : 'false')
            )
          },

          onUpdate: (self) => {
            scrollState.progress = self.progress
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
