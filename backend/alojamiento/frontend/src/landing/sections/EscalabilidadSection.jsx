import { useRef, useEffect, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import NexoCanvas from '../components/NexoCanvas'

gsap.registerPlugin(ScrollTrigger)

export default function EscalabilidadSection() {
  const sectionRef = useRef()
  const contentRef = useRef()
  const canvasWrapperRef = useRef()
  const [isInView, setIsInView] = useState(false)

  useEffect(() => {
    const section = sectionRef.current
    if (!section) return

    const ctx = gsap.context(() => {
      // Text entry
      gsap.timeline({
        scrollTrigger: {
          trigger: section,
          start:   'top 70%',
          end:     'top 30%',
          toggleActions: 'play none none reverse',
        }
      }).fromTo(contentRef.current.children,
        { opacity: 0, y: 28 },
        { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out', stagger: 0.13 }
      )

      // ── Animate local Canvas entry/exit ──
      gsap.fromTo(canvasWrapperRef.current,
        { opacity: 0, scale: 0.8 },
        {
          opacity: 1,
          scale: 1,
          duration: 0.8,
          ease: 'power2.out',
          scrollTrigger: {
            trigger: section,
            start: 'top 80%',
            end: 'bottom 20%',
            toggleActions: 'play reverse play reverse',
            onToggle: (self) => setIsInView(self.isActive)
          }
        }
      )
    }, section)

    return () => ctx.revert()
  }, [])

  return (
    <section
      id="nx-section-escalabilidad"
      ref={sectionRef}
      data-section="escalabilidad"
      className="relative min-h-[100dvh] flex items-center overflow-hidden py-28 pointer-events-none"
    >
      {/* ── Local WebGL Canvas ── */}
      <div 
        ref={canvasWrapperRef}
        style={{
          position: 'absolute',
          right: '5%',
          top: '12%',
          width: '45%',
          height: '75%',
          zIndex: 1,
          pointerEvents: 'auto',
          opacity: 0,
        }}
      >
        {isInView && <NexoCanvas type="grid" />}
      </div>
      {/* Dot grid background */}
      <div aria-hidden="true" style={{
        position: 'absolute', inset: 0,
        backgroundImage: 'radial-gradient(circle, rgba(0,230,118,0.12) 1px, transparent 1px)',
        backgroundSize: '36px 36px',
        zIndex: 0,
      }} />

      <div ref={contentRef} style={{ position: 'relative', zIndex: 10, maxWidth: 480 }}>
        <div style={{ opacity: 0, marginBottom: '1.8rem' }}>
          <span style={{
            fontFamily: "'JetBrains Mono', monospace",
            fontSize: '0.63rem', fontWeight: 400, letterSpacing: '0.2em',
            textTransform: 'uppercase', color: '#00e676',
            border: '1px solid rgba(0,230,118,0.28)', borderRadius: '999px',
            padding: '0.3rem 0.85rem', background: 'rgba(0,230,118,0.05)',
            display: 'inline-block',
          }}>Escalabilidad</span>
        </div>
        <div style={{ opacity: 0 }}>
          <h2 style={{
            fontFamily: "'Plus Jakarta Sans', 'Outfit', sans-serif",
            fontWeight: 800, fontSize: 'clamp(2.4rem, 4.5vw, 3.8rem)',
            lineHeight: 1.05, letterSpacing: '-0.025em', color: '#fff',
            margin: '0 0 1.2rem',
          }}>Una red para<br />toda la institución.</h2>
        </div>
        <div style={{ opacity: 0 }}>
          <p style={{
            fontFamily: "'Plus Jakarta Sans', sans-serif",
            fontWeight: 400, fontSize: 'clamp(0.88rem, 1.3vw, 1rem)',
            lineHeight: 1.7, color: '#8da898', margin: 0, maxWidth: '40ch',
          }}>
            Desde un aula hasta toda la institución. El sistema NEXO escala
            sin fricciones, con gestión centralizada de decenas de nodos.
          </p>
        </div>
        <div style={{ opacity: 0, marginTop: '2rem' }}>
          <span style={{
            fontFamily: "'JetBrains Mono', monospace",
            fontSize: '0.78rem', color: '#00e676', letterSpacing: '0.08em',
          }}>Hasta 48 nodos por institución</span>
        </div>
      </div>
    </section>
  )
}
