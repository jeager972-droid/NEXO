import { useRef, useEffect, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import NexoCanvas from '../components/NexoCanvas'
import ContactModal from '../components/ContactModal'
import { useStickyScroll } from '../components/useStickyScroll'
// NOTE: gsap.registerPlugin called once globally in LandingPage.jsx

// MODULE 01 — HERO
// CAMBIO 5: CTA → "Quiero que NEXO llegue a mi institución" + modal
// CAMBIO 6: GSAP entrada cinematográfica y Scroll sticky

export default function HeroSection() {
  const wrapperRef  = useRef()
  const innerRef    = useRef()
  const eyebrowRef  = useRef()
  const line1Ref    = useRef()
  const line2Ref    = useRef()
  const subtitleRef = useRef()
  const ctaRef      = useRef()
  const microRef    = useRef()
  const canvasRef   = useRef()

  const [modalOpen, setModalOpen] = useState(false)
  const [isMobile, setIsMobile] = useState(false)

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth <= 768)
    check()
    window.addEventListener('resize', check)
    return () => window.removeEventListener('resize', check)
  }, [])

  // Aplicar arquitectura sticky scroll (isFirst: true = no entrance anim, has exit anim)
  useStickyScroll(wrapperRef, innerRef, { isFirst: true })

  useEffect(() => {
    const isMobile = window.innerWidth <= 768
    const wrapper = wrapperRef.current
    if (!wrapper) return

    const tl = gsap.timeline({ delay: isMobile ? 0.2 : 0.1 })

    tl.fromTo(eyebrowRef.current,
      { opacity: 0, y: isMobile ? -8 : -12 },
      { opacity: 1, y: 0, duration: 0.6, ease: 'power3.out' }
    )
    .fromTo([line1Ref.current, line2Ref.current],
      { opacity: 0, y: isMobile ? 30 : 60 },
      { opacity: 1, y: 0, duration: isMobile ? 0.7 : 1.0,
        ease: 'expo.out', stagger: 0.12 },
      '-=0.35'
    )
    .fromTo(subtitleRef.current,
      { opacity: 0, y: isMobile ? 15 : 24 },
      { opacity: 1, y: 0, duration: 0.75, ease: 'power3.out' },
      '-=0.5'
    )
    .fromTo(ctaRef.current?.children || [],
      { opacity: 0, y: 12 },
      { opacity: 1, y: 0, duration: 0.65, ease: 'power3.out',
        stagger: 0.1 },
      '-=0.4'
    )
    .fromTo(microRef.current,
      { opacity: 0 },
      { opacity: 1, duration: 0.5, ease: 'power2.out' },
      '-=0.2'
    )
    .fromTo(canvasRef.current,
      { opacity: 0, scale: isMobile ? 0.98 : 0.96 },
      { opacity: 1, scale: 1,
        duration: isMobile ? 1.0 : 1.5, ease: 'expo.out' },
      0.3
    )

    return () => tl.kill()
  }, [])

  return (
    <>
      {/* CAMBIO 5: Modal de contacto */}
      {modalOpen && <ContactModal onClose={() => setModalOpen(false)} />}

      <div ref={wrapperRef} className="section-wrapper" id="hero">
        <section
          ref={innerRef}
          className="section-inner"
          style={{
            background:    'var(--nx-hero-gradient)',
            position:      'relative',
            overflow:      isMobile ? 'visible' : 'hidden',
            paddingLeft:   'var(--nx-section-px)',
            paddingRight:  'var(--nx-section-px)',
            display:       'flex',
            alignItems:    'center',
          }}
        >
          {/* Glow ambiental verde menta detrás del nodo */}
          <div aria-hidden="true" className="nx-hero-glow-ambient" style={{
            position:        'absolute',
            right:           '5%',
            top:             '50%',
            transform:       'translateY(-50%)',
            width:           '580px',
            height:          '580px',
            background:      'radial-gradient(circle, rgba(45, 110, 48, 0.12) 0%, transparent 65%)',
            borderRadius:    '50%',
            pointerEvents:   'none',
            zIndex:           0,
          }} />

          <div className="nx-hero-grid" style={{
            position:            'relative',
            zIndex:               1,
            display:             'grid',
            gridTemplateColumns: '1fr 1fr',
            gap:                 '3rem',
            alignItems:          'center',
            maxWidth:            '1280px',
            margin:              '0 auto',
            width:               '100%',
          }}>
            {/* ── LEFT: Copy ── */}
            <div className="nx-hero-copy">
              {/* Eyebrow */}
              <div ref={eyebrowRef} className="nx-eyebrow" style={{ opacity: 0 }}>
                Sistema de Custodia Educativa en Tiempo Real — Colombia
              </div>

              {/* H1 — dos líneas semánticas para el stagger */}
              <h1
                className="nx-h1"
                style={{ marginBottom: '1.75rem', overflow: 'visible' }}
              >
                {/* Línea 1 */}
                <span ref={line1Ref} style={{ display: 'block', opacity: 0, shortcut: 'none', willChange: 'transform, opacity' }}>
                  La presencia estudiantil
                </span>
                {/* Línea 2 — stagger 0.15s */}
                <span ref={line2Ref} style={{ display: 'block', opacity: 0, shortcut: 'none', willChange: 'transform, opacity' }}>
                  ya no puede ser un punto ciego.
                </span>
              </h1>

              {/* Subtítulo */}
              <p ref={subtitleRef} className="nx-body" style={{ maxWidth: '500px', marginBottom: '2.5rem', opacity: 0 }}>
                NEXO cierra ese vacío. Control de presencia, trazabilidad completa, comunicación
                institucional y procesos automatizados con análisis inteligente en tiempo real.
                En una infraestructura que opera con conexión autónoma y batería de respaldo ante cortes de luz.
              </p>

              {/* Mobile-only: 3D node inline between subtitle and CTA */}
              {isMobile && (
                <div
                  ref={canvasRef}
                  className="nx-hero-canvas nx-hero-canvas--inline"
                  aria-label="Modelo 3D del nodo NEXO"
                  style={{
                    height:       '340px',
                    borderRadius: '1rem',
                    overflow:     'hidden',
                    position:     'relative',
                    marginBottom: '1.5rem',
                    width:        '100%',
                  }}
                >
                  <NexoCanvas type="solo" scale={1.1} coldLight />
                </div>
              )}

              {/* CTAs */}
              <div ref={ctaRef} style={{ display: 'flex', alignItems: 'center', gap: '1.25rem', flexWrap: 'wrap', marginBottom: '1.25rem' }}>
                {/* CAMBIO 5: Nuevo CTA — abre modal */}
                <button
                  id="hero-cta-primary"
                  className="nx-btn-primary"
                  onClick={() => setModalOpen(true)}
                  type="button"
                  style={{ opacity: 0 }}
                >
                  Quiero que NEXO llegue a mi institución
                </button>
                <a href="#como-funciona" className="nx-link-arrow" id="hero-cta-how" style={{ opacity: 0 }}>
                  ¿Eres rector o directivo? Ve cómo funciona
                  <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden>
                    <path d="M2 7h10M8 3l4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
                  </svg>
                </a>
              </div>

              {/* Micro-copy */}
              <p ref={microRef} className="nx-micro" style={{ opacity: 0 }}>
                NEXO desea amparar la necesidad de corresponsabilidad familia-escuela, alerta temprana y trazabilidad de eventos en el sistema educativo colombiano.
              </p>
            </div>

            {/* ── RIGHT: 3D Node (desktop only — mobile renders inline above) ── */}
            {!isMobile && (
              <div
                ref={canvasRef}
                className="nx-hero-canvas"
                aria-label="Modelo 3D del nodo NEXO"
                style={{
                  height:       '520px',
                  borderRadius: '1.5rem',
                  overflow:     'hidden',
                  position:     'relative',
                  opacity:       0,
                  willChange:   'transform, opacity',
                }}
              >
                <NexoCanvas type="solo" scale={1.1} coldLight />

                {/* Label de hardware */}
                <div aria-hidden="true" style={{
                  position:      'absolute',
                  bottom:        '1.25rem',
                  left:          '50%',
                  transform:     'translateX(-50%)',
                  fontSize:      '0.65rem',
                  fontWeight:     600,
                  letterSpacing: '0.14em',
                  textTransform: 'uppercase',
                  color:         'var(--nx-muted-2)',
                  whiteSpace:    'nowrap',
                }}>
                  Nodo NEXO — Hardware biométrico
                </div>
              </div>
            )}
          </div>

          {/* Scroll indicator — hidden on mobile */}
          <div aria-hidden="true" className="nx-hero-scroll-indicator" style={{
            position:   'absolute',
            bottom:     '2.5rem',
            left:       '50%',
            transform:  'translateX(-50%)',
            display:    'flex',
            flexDirection: 'column',
            alignItems: 'center',
            gap:        '0.5rem',
            opacity:     0,
            animation:  'heroScrollIn 0.6s ease 2s forwards',
          }}>
            <span className="nx-micro">Desliza</span>
            <div style={{
              width:      '1px',
              height:     '48px',
              background: 'linear-gradient(to bottom, rgba(107,127,163,0.6), transparent)',
              animation:  'scrollBlink 2.2s ease-in-out infinite',
            }} />
          </div>

          <style>{`
            @keyframes heroScrollIn { to { opacity: 1; } }
            @keyframes scrollBlink {
              0%,100% { opacity: .2; }
              50%      { opacity: 1;  }
            }

            @media (max-width: 768px) {
              #hero .section-inner {
                justify-content: flex-start !important;
                padding-top: 5.5rem !important;
              }
              /* Mobile: single column, natural flow */
              #hero .nx-hero-grid {
                grid-template-columns: 1fr !important;
                gap: 0 !important;
                display: flex !important;
                flex-direction: column !important;
              }
              /* Copy first, canvas second — inner reorder via child flex order */
              #hero .nx-hero-copy {
                order: 0 !important;
                display: flex !important;
                flex-direction: column !important;
              }
              /* Canvas sits between subtitle and CTA */
              #hero .nx-hero-canvas {
                order: 1 !important;
                height: 340px !important;
                border-radius: 1rem !important;
                overflow: hidden !important;
                margin-bottom: 1.5rem !important;
              }
              /* Glow: full-width behind canvas on mobile */
              #hero .nx-hero-glow-ambient {
                width: 100% !important;
                height: 300px !important;
                right: 0 !important;
                top: 0 !important;
                transform: none !important;
                border-radius: 0 !important;
              }
              #hero .nx-link-arrow {
                display: none;
              }
              #hero .nx-hero-scroll-indicator {
                display: none !important;
              }
            }
          `}</style>
        </section>
      </div>
    </>
  )
}
