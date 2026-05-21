import { useEffect, useRef, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { View } from '@react-three/drei'
import NexoModelViewer from '../../components/canvas/NexoModelViewer'

gsap.registerPlugin(ScrollTrigger)

export default function CifradoSection() {
  const sectionRef = useRef()
  const eyebrowRef = useRef()
  const headingRef = useRef()
  const bodyRef    = useRef()
  const cardRef    = useRef()
  const canvasWrapperRef = useRef()
  const [isInView, setIsInView] = useState(false)

  useEffect(() => {
    const section = sectionRef.current
    if (!section) return

    const ctx = gsap.context(() => {
      // ── Entry text animations ──────────────────────────────────────────────
      const entryTl = gsap.timeline({
        scrollTrigger: {
          trigger: section,
          start:   'top 70%',
          end:     'top 30%',
          toggleActions: 'play none none reverse',
        }
      })

      entryTl
        .fromTo(eyebrowRef.current,
          { opacity: 0, y: 15 },
          { opacity: 1, y: 0, duration: 0.6, ease: 'power3.out' }
        )
        .fromTo(headingRef.current,
          { opacity: 0, y: 30, filter: 'blur(3px)' },
          { opacity: 1, y: 0, filter: 'blur(0px)', duration: 0.8, ease: 'power4.out' },
          '-=0.3'
        )
        .fromTo(bodyRef.current,
          { opacity: 0, y: 20 },
          { opacity: 1, y: 0, duration: 0.7, ease: 'power2.out' },
          '-=0.4'
        )
        .fromTo(cardRef.current,
          { opacity: 0, y: 25 },
          { opacity: 1, y: 0, duration: 0.8, ease: 'power3.out' },
          '-=0.4'
        )

      // ── Background and Canvas transition ScrollTrigger ──
      ScrollTrigger.create({
        trigger: section,
        start:   'top center',
        end:     'bottom center',
        onEnter: () => {
          if (document.body) gsap.to(document.body, { backgroundColor: '#121212', duration: 0.8 })
        },
        onEnterBack: () => {
          if (document.body) gsap.to(document.body, { backgroundColor: '#121212', duration: 0.8 })
        },
        onLeaveBack: () => {
          if (document.body) gsap.to(document.body, { backgroundColor: '#0a0f0d', duration: 0.8 })
        },
        onLeave: () => {
          // No action
        },
      })

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
      id="nx-section-cifrado"
      ref={sectionRef}
      data-section="cifrado"
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
            <NexoModelViewer type="cifrado" scale={1.2} showShield={true} />
          </View>
        )}
      </div>
      {/* ── Left content column ── */}
      <div className="max-w-[540px] relative z-10 pointer-events-auto">
        {/* Eyebrow */}
        <div ref={eyebrowRef} style={{ opacity: 0, marginBottom: '1.8rem' }}>
          <span style={{
            fontFamily:    "'JetBrains Mono', monospace",
            fontSize:      '0.63rem',
            fontWeight:    400,
            letterSpacing: '0.2em',
            textTransform: 'uppercase',
            color:         '#00e676',
            border:        '1px solid rgba(0,230,118,0.3)',
            borderRadius:  '999px',
            padding:       '0.32rem 0.85rem',
            background:    'rgba(0,230,118,0.05)',
            display:       'inline-block',
          }}>
            Seguridad Descentralizada
          </span>
        </div>

        {/* Heading */}
        <div ref={headingRef} style={{ opacity: 0 }}>
          <h2 style={{
            fontFamily:   "'Plus Jakarta Sans', 'Outfit', sans-serif",
            fontWeight:    800,
            fontSize:     'clamp(2.5rem, 5vw, 4rem)',
            lineHeight:    1.05,
            letterSpacing: '-0.02em',
            color:         '#ffffff',
            margin:        0,
          }}>
            Cifrado Extremo
          </h2>
        </div>

        {/* Body */}
        <p ref={bodyRef} style={{
          opacity:    0,
          fontFamily: "'Plus Jakarta Sans', sans-serif",
          fontWeight:  400,
          fontSize:   'clamp(0.88rem, 1.3vw, 1.02rem)',
          lineHeight:  1.7,
          color:      '#8da898',
          marginTop:  '1.5rem',
          maxWidth:   '44ch',
        }}>
          La información de los estudiantes y de la institución siempre estará blindada bajo protocolos criptográficos avanzados, garantizando soberanía de datos y privacidad inquebrantable.
        </p>

        {/* Double-bezel technical details card */}
        <div ref={cardRef} style={{ opacity: 0, marginTop: '2.5rem' }}>
          {/* Outer enclosure (bezel 1) */}
          <div className="p-1 rounded-[1.2rem] bg-white/5 border border-white/10 max-w-[420px]">
            {/* Inner Core (bezel 2) */}
            <div className="bg-[#161a18] rounded-[calc(1.2rem-0.25rem)] p-5 shadow-[inset_0_1px_1px_rgba(255,255,255,0.08)]">
              <div className="flex flex-col gap-3 font-mono text-[0.72rem] tracking-wide text-white/70">
                <div className="flex justify-between items-center border-b border-white/5 pb-2">
                  <span>AUDIT CHAIN:</span>
                  <span className="text-[#00e676] font-semibold">INMUTABLE (SHA-256)</span>
                </div>
                <div className="flex justify-between items-center">
                  <span>ENCRYPT ENGINE:</span>
                  <span className="text-white">ZERO KNOWLEDGE PROOF</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}
