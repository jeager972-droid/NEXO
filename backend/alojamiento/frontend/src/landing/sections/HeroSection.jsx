import { useEffect, useRef, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { View } from '@react-three/drei'
import NexoModelViewer from '../../components/canvas/NexoModelViewer'

gsap.registerPlugin(ScrollTrigger)

export default function HeroSection() {
  const eyebrowRef  = useRef()
  const titleRef    = useRef()
  const subtitleRef = useRef()
  const bodyRef     = useRef()
  const ctaRef      = useRef()
  const buildRef    = useRef()
  const canvasWrapperRef = useRef()
  const [isInView, setIsInView] = useState(false)

  useEffect(() => {
    const ctx = gsap.context(() => {
      const tl = gsap.timeline({ delay: 0.2 })

      tl.fromTo(eyebrowRef.current,
        { opacity: 0, y: 18 },
        { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out' }
      )
      .fromTo(titleRef.current,
        { opacity: 0, y: 48, filter: 'blur(6px)' },
        { opacity: 1, y: 0, filter: 'blur(0px)', duration: 1.0, ease: 'power4.out' },
        '-=0.3'
      )
      .fromTo(subtitleRef.current,
        { opacity: 0, y: 32 },
        { opacity: 1, y: 0, duration: 0.8, ease: 'power3.out' },
        '-=0.5'
      )
      .fromTo(bodyRef.current,
        { opacity: 0, y: 20 },
        { opacity: 1, y: 0, duration: 0.7, ease: 'power2.out' },
        '-=0.4'
      )
      .fromTo(ctaRef.current,
        { opacity: 0, y: 16 },
        { opacity: 1, y: 0, duration: 0.6, ease: 'power2.out' },
        '-=0.3'
      )
      .fromTo(buildRef.current,
        { opacity: 0 },
        { opacity: 1, duration: 0.8, ease: 'power1.out' },
        '-=0.2'
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
            trigger: '#nx-section-hero',
            start: 'top 80%',
            end: 'bottom 20%',
            toggleActions: 'play reverse play reverse',
            onToggle: (self) => setIsInView(self.isActive)
          }
        }
      )
    })
    return () => ctx.revert()
  }, [])

  const scrollToNext = () => {
    const next = document.getElementById('nx-section-vision')
    next?.scrollIntoView({ behavior: 'smooth' })
  }

  return (
    <section
      id="nx-section-hero"
      data-section="hero"
      className="relative min-h-[100dvh] flex flex-col justify-center pt-24 pb-16 overflow-hidden pointer-events-none"
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
            <NexoModelViewer type="hero" scale={1.2} />
          </View>
        )}
      </div>
      {/* ── Ambient radial glow (right side, where 3D lives) ── */}
      <div
        aria-hidden="true"
        className="pointer-events-none fixed inset-0"
        style={{
          background: 'radial-gradient(ellipse 55% 65% at 75% 50%, rgba(0,198,100,0.12) 0%, transparent 70%)',
          zIndex: 0,
        }}
      />

      {/* ── Left column content (max 45% width on desktop) ── */}
      <div className="relative z-10 max-w-[600px] pointer-events-auto">

        {/* Eyebrow pill */}
        <div ref={eyebrowRef} className="mb-8" style={{ opacity: 0 }}>
          <span style={{
            display:       'inline-flex',
            alignItems:    'center',
            gap:           '0.4rem',
            fontFamily:    "'JetBrains Mono', monospace",
            fontSize:      '0.65rem',
            fontWeight:    400,
            letterSpacing: '0.22em',
            textTransform: 'uppercase',
            color:         '#00e676',
            border:        '1px solid rgba(0,230,118,0.35)',
            borderRadius:  '999px',
            padding:       '0.35rem 0.85rem',
            background:    'rgba(0,230,118,0.06)',
          }}>
            <span style={{ width: 5, height: 5, borderRadius: '50%', background: '#00e676', display: 'inline-block' }} />
            EdTech Antioqueña
          </span>

          {/* Horizontal rule */}
          <div style={{
            marginTop: '1rem',
            height:    '1px',
            width:     '100%',
            background:'linear-gradient(90deg, rgba(0,230,118,0.2) 0%, transparent 100%)',
          }} />
        </div>

        {/* Main headline */}
        <div ref={titleRef} style={{ opacity: 0 }}>
          <h1 style={{
            fontFamily:  "'Plus Jakarta Sans', 'Outfit', sans-serif",
            fontWeight:   900,
            fontSize:     'clamp(6rem, 14vw, 11rem)',
            lineHeight:   0.88,
            letterSpacing:'-0.03em',
            color:        '#ffffff',
            margin:       0,
          }}>
            NEXO
          </h1>
        </div>

        {/* Sub-headline */}
        <div ref={subtitleRef} style={{ opacity: 0 }}>
          <p style={{
            fontFamily:  "'Plus Jakarta Sans', 'Outfit', sans-serif",
            fontWeight:   800,
            fontSize:     'clamp(2rem, 5vw, 4rem)',
            lineHeight:   1.05,
            letterSpacing:'-0.02em',
            color:        'rgba(255,255,255,0.82)',
            margin:       '0.15em 0 0',
          }}>
            Soberanía Digital
          </p>
        </div>

        {/* Body copy */}
        <p ref={bodyRef} style={{
          opacity:     0,
          fontFamily: "'Plus Jakarta Sans', sans-serif",
          fontWeight:  400,
          fontSize:    'clamp(0.9rem, 1.4vw, 1.05rem)',
          lineHeight:  1.65,
          color:       '#8da898',
          marginTop:   '1.6rem',
          maxWidth:    '42ch',
        }}>
          Infraestructura híbrida IoT para la gestión educativa autónoma,
          segura y transparente en tiempo real.
        </p>

        {/* CTA group */}
        <div ref={ctaRef} style={{ opacity: 0, display: 'flex', gap: '0.85rem', marginTop: '2.5rem', alignItems: 'center', flexWrap: 'wrap' }}>
          {/* Primary */}
          <button
            id="hero-cta-primary"
            onClick={scrollToNext}
            className="group"
            style={{
              display:        'inline-flex',
              alignItems:     'center',
              gap:            '0.5rem',
              background:     '#00c05a',
              color:          '#fff',
              fontFamily:     "'Plus Jakarta Sans', sans-serif",
              fontWeight:     700,
              fontSize:       '0.875rem',
              letterSpacing:  '0.01em',
              border:         'none',
              borderRadius:   '999px',
              padding:        '0.75rem 1.4rem 0.75rem 1.6rem',
              cursor:         'pointer',
              transition:     'transform 0.25s cubic-bezier(0.32,0.72,0,1), background 0.25s ease',
            }}
            onMouseEnter={e => { e.currentTarget.style.background = '#00d966'; e.currentTarget.style.transform = 'scale(1.03)' }}
            onMouseLeave={e => { e.currentTarget.style.background = '#00c05a'; e.currentTarget.style.transform = 'scale(1)' }}
            onMouseDown={e => { e.currentTarget.style.transform = 'scale(0.97)' }}
            onMouseUp={e => { e.currentTarget.style.transform = 'scale(1.03)' }}
          >
            Explorar Sistema
            {/* Button-in-button icon */}
            <span style={{
              display:        'inline-flex',
              alignItems:     'center',
              justifyContent: 'center',
              width:          28,
              height:         28,
              borderRadius:   '50%',
              background:     'rgba(0,0,0,0.18)',
              transition:     'transform 0.25s cubic-bezier(0.32,0.72,0,1)',
              fontSize:       '0.85rem',
            }}>
              ↓
            </span>
          </button>

          {/* Ghost secondary */}
          <button
            id="hero-cta-secondary"
            style={{
              display:       'inline-flex',
              alignItems:    'center',
              gap:           '0.4rem',
              background:    'transparent',
              color:         'rgba(255,255,255,0.65)',
              fontFamily:    "'Plus Jakarta Sans', sans-serif",
              fontWeight:    500,
              fontSize:      '0.875rem',
              border:        '1px solid rgba(255,255,255,0.18)',
              borderRadius:  '999px',
              padding:       '0.73rem 1.4rem',
              cursor:        'pointer',
              transition:    'border-color 0.25s ease, color 0.25s ease',
            }}
            onMouseEnter={e => { e.currentTarget.style.borderColor = 'rgba(255,255,255,0.4)'; e.currentTarget.style.color = '#fff' }}
            onMouseLeave={e => { e.currentTarget.style.borderColor = 'rgba(255,255,255,0.18)'; e.currentTarget.style.color = 'rgba(255,255,255,0.65)' }}
          >
            Ver Demo
          </button>
        </div>
      </div>

      {/* ── Build tag + Scroll indicator ── */}
      <div
        ref={buildRef}
        style={{
          opacity:        0,
          position:       'absolute',
          bottom:         '2rem',
          left:           '2.5rem',
          right:          '2.5rem',
          display:        'flex',
          alignItems:     'center',
          justifyContent: 'space-between',
        }}
      >
        <span style={{
          fontFamily:    "'JetBrains Mono', monospace",
          fontSize:      '0.6rem',
          letterSpacing: '0.12em',
          color:         'rgba(255,255,255,0.2)',
          textTransform: 'uppercase',
        }}>
          v2.1 · Build NEXO-HW-12
        </span>

        {/* Animated scroll chevron */}
        <button
          onClick={scrollToNext}
          aria-label="Scroll hacia abajo"
          style={{
            background:  'none',
            border:      'none',
            cursor:      'pointer',
            color:       'rgba(255,255,255,0.3)',
            fontSize:    '1.2rem',
            animation:   'nx-bounce 2s ease-in-out infinite',
            padding:     '0.25rem',
          }}
        >
          ›
        </button>
      </div>

      <style>{`
        @keyframes nx-bounce {
          0%, 100% { transform: translateY(0);    opacity: 0.3; }
          50%       { transform: translateY(6px);  opacity: 0.7; }
        }
      `}</style>
    </section>
  )
}
