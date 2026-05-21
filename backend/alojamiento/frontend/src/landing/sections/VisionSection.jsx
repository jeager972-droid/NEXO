import { useEffect, useRef, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { View } from '@react-three/drei'
import NexoModelViewer from '../../components/canvas/NexoModelViewer'

gsap.registerPlugin(ScrollTrigger)

const STATS = [
  { value: '98%',  label: 'Asistencia registrada en tiempo real' },
  { value: '3x',   label: 'Reducción de carga administrativa'     },
  { value: 'IP66', label: 'Certificación de resistencia ambiental' },
]

export default function VisionSection() {
  const sectionRef  = useRef()
  const eyebrowRef  = useRef()
  const headingRef  = useRef()
  const bodyRef     = useRef()
  const statsRef    = useRef()
  const canvasWrapperRef = useRef()
  const [isInView, setIsInView] = useState(false)

  useEffect(() => {
    const section = sectionRef.current
    if (!section) return

    // ── Scroll entry: fade-up for text elements ────────────────────────────
    const ctx = gsap.context(() => {
      const entryTl = gsap.timeline({
        scrollTrigger: {
          trigger: section,
          start:   'top 75%',
          end:     'top 30%',
          toggleActions: 'play none none reverse',
        }
      })

      entryTl
        .fromTo(eyebrowRef.current,
          { opacity: 0, y: 14 },
          { opacity: 1, y: 0, duration: 0.55, ease: 'power3.out' }
        )
        .fromTo(headingRef.current,
          { opacity: 0, y: 36, filter: 'blur(4px)' },
          { opacity: 1, y: 0, filter: 'blur(0px)', duration: 0.85, ease: 'power4.out' },
          '-=0.25'
        )
        .fromTo(bodyRef.current,
          { opacity: 0, y: 20 },
          { opacity: 1, y: 0, duration: 0.65, ease: 'power2.out' },
          '-=0.4'
        )
        .fromTo(statsRef.current.children,
          { opacity: 0, x: -18 },
          { opacity: 1, x: 0, duration: 0.5, ease: 'power2.out', stagger: 0.12 },
          '-=0.35'
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
      id="nx-section-vision"
      ref={sectionRef}
      data-section="vision"
      className="relative min-h-[100dvh] flex flex-col justify-center py-32 overflow-hidden pointer-events-none"
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
        {isInView && (
          <View track={canvasWrapperRef} style={{ width: '100%', height: '100%' }}>
            <NexoModelViewer type="vision" scale={1.1} />
          </View>
        )}
      </div>
      {/* Left content column */}
      <div className="max-w-[520px] relative z-10 pointer-events-auto">

        {/* Eyebrow */}
        <div ref={eyebrowRef} style={{ opacity: 0, marginBottom: '2rem' }}>
          <span style={{
            fontFamily:    "'JetBrains Mono', monospace",
            fontSize:      '0.63rem',
            fontWeight:    400,
            letterSpacing: '0.22em',
            textTransform: 'uppercase',
            color:         '#00e676',
            border:        '1px solid rgba(0,230,118,0.3)',
            borderRadius:  '999px',
            padding:       '0.32rem 0.8rem',
            background:    'rgba(0,230,118,0.05)',
            display:       'inline-block',
          }}>
            La Visión
          </span>
        </div>

        {/* Heading */}
        <div ref={headingRef} style={{ opacity: 0 }}>
          <h2 style={{
            fontFamily:   "'Plus Jakarta Sans', 'Outfit', sans-serif",
            fontWeight:    800,
            fontSize:     'clamp(2.8rem, 5.5vw, 4.5rem)',
            lineHeight:    1.0,
            letterSpacing: '-0.025em',
            color:         '#ffffff',
            margin:        0,
          }}>
            Sistema<br />Autónomo
          </h2>
        </div>

        {/* Body */}
        <p ref={bodyRef} style={{
          opacity:    0,
          fontFamily: "'Plus Jakarta Sans', sans-serif",
          fontWeight:  400,
          fontSize:   'clamp(0.88rem, 1.3vw, 1rem)',
          lineHeight:  1.7,
          color:      '#8da898',
          marginTop:  '1.5rem',
          maxWidth:   '44ch',
        }}>
          Un ecosistema inteligente que ampara toda la operación educativa
          mediante alertas tempranas, corresponsabilidad familiar y
          automatización de procesos.
        </p>

        {/* Stats */}
        <div
          ref={statsRef}
          style={{ marginTop: '2.8rem', display: 'flex', flexDirection: 'column', gap: 0 }}
        >
          {STATS.map(({ value, label }, i) => (
            <div key={i}>
              {/* Hairline top */}
              <div style={{ height: 1, background: 'rgba(255,255,255,0.07)', marginBottom: '1.2rem' }} />
              <div style={{
                opacity:     0,
                display:    'flex',
                alignItems: 'center',
                gap:        '1.2rem',
                paddingBottom: '1.2rem',
              }}>
                <span style={{
                  fontFamily:   "'Plus Jakarta Sans', 'Outfit', sans-serif",
                  fontWeight:    700,
                  fontSize:     'clamp(2rem, 3.5vw, 2.8rem)',
                  color:        '#00e676',
                  lineHeight:    1,
                  minWidth:     '5rem',
                  letterSpacing: '-0.02em',
                }}>
                  {value}
                </span>
                <span style={{
                  fontFamily: "'Plus Jakarta Sans', sans-serif",
                  fontWeight:  400,
                  fontSize:   '0.82rem',
                  color:      'rgba(255,255,255,0.45)',
                  lineHeight:  1.4,
                  maxWidth:   '20ch',
                }}>
                  {label}
                </span>
              </div>
            </div>
          ))}
          {/* Bottom hairline */}
          <div style={{ height: 1, background: 'rgba(255,255,255,0.07)' }} />
        </div>
      </div>
    </section>
  )
}
