import { useRef, useEffect, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import NexoCanvas from '../components/NexoCanvas'
import ContactModal from '../components/ContactModal'

gsap.registerPlugin(ScrollTrigger)

// MODULE 01 — HERO
// CAMBIO 5: CTA → "Quiero que NEXO llegue a mi institución" + modal
// CAMBIO 6: GSAP entrada cinematográfica por líneas de texto

export default function HeroSection() {
  const sectionRef  = useRef()
  const eyebrowRef  = useRef()
  const line1Ref    = useRef()
  const line2Ref    = useRef()
  const subtitleRef = useRef()
  const ctaRef      = useRef()
  const microRef    = useRef()
  const canvasRef   = useRef()

  const [modalOpen, setModalOpen] = useState(false)

  useEffect(() => {
    // CAMBIO 6: Timeline cinematográfico de entrada al Hero
    // Referencia de diseño: Linear.app, Monfort — ease expo.out, durations 0.9-1.1s
    const tl = gsap.timeline({ delay: 0.15 })

    // Eyebrow desliza desde arriba
    tl.fromTo(eyebrowRef.current,
      { opacity: 0, y: -12 },
      { opacity: 1, y: 0, duration: 0.6, ease: 'power3.out' }
    )

    // H1 línea 1 — emerge desde translateY(60px)
    .fromTo(line1Ref.current,
      { opacity: 0, y: 60 },
      { opacity: 1, y: 0, duration: 1.0, ease: 'expo.out' },
      '-=0.35'
    )

    // H1 línea 2 — con stagger de 0.15s respecto a línea 1
    .fromTo(line2Ref.current,
      { opacity: 0, y: 60 },
      { opacity: 1, y: 0, duration: 1.0, ease: 'expo.out' },
      '-=0.85'
    )

    // Subtítulo — delay 0.4s desde inicio del título
    .fromTo(subtitleRef.current,
      { opacity: 0, y: 20 },
      { opacity: 1, y: 0, duration: 0.85, ease: 'power3.out' },
      '-=0.5'
    )

    // CTAs — delay 0.7s
    .fromTo(ctaRef.current,
      { opacity: 0, y: 16 },
      { opacity: 1, y: 0, duration: 0.75, ease: 'power3.out' },
      '-=0.4'
    )

    // Microcopy
    .fromTo(microRef.current,
      { opacity: 0 },
      { opacity: 1, duration: 0.6, ease: 'power2.out' },
      '-=0.3'
    )

    // Canvas del nodo — aparece con leve rotación inicial corregida en 1.5s
    .fromTo(canvasRef.current,
      { opacity: 0, scale: 0.96 },
      { opacity: 1, scale: 1, duration: 1.5, ease: 'expo.out' },
      0.35   // Simultáneo al inicio del H1
    )

    // CAMBIO 6: Parallax 3D del nodo durante el scroll del hero
    // El nodo rota suavemente en Y conforme el usuario scrollea (0 → 0.5turn = 180°)
    ScrollTrigger.create({
      trigger: sectionRef.current,
      start:   'top top',
      end:     'bottom top',
      scrub:   1.8,        // inercia suave
      onUpdate: (self) => {
        // Comunica la progresión al canvas interno si el DOM lo expone
        // La rotación automática de NexoCanvas más el parallax se suman
        if (canvasRef.current) {
          gsap.set(canvasRef.current, {
            rotateY: self.progress * 25,  // máx 25° de parallax
          })
        }
      },
    })

    return () => { tl.kill(); ScrollTrigger.getAll().forEach(t => t.kill()) }
  }, [])

  return (
    <>
      {/* CAMBIO 5: Modal de contacto */}
      {modalOpen && <ContactModal onClose={() => setModalOpen(false)} />}

      <section
        ref={sectionRef}
        id="hero"
        className="nx-section"
        style={{
          minHeight: '100vh',
          display:   'flex',
          alignItems:'center',
          paddingTop:    '7rem',
          paddingBottom: '5rem',
          paddingLeft:   'var(--nx-section-px)',
          paddingRight:  'var(--nx-section-px)',
          background:    'var(--nx-void)',
          position:      'relative',
          overflow:      'hidden',
        }}
      >
        {/* Glow ambiental azul detrás del nodo */}
        <div aria-hidden="true" style={{
          position:        'absolute',
          right:           '5%',
          top:             '50%',
          transform:       'translateY(-50%)',
          width:           '580px',
          height:          '580px',
          background:      'radial-gradient(circle, rgba(10,132,255,0.09) 0%, transparent 65%)',
          borderRadius:    '50%',
          pointerEvents:   'none',
          zIndex:           0,
        }} />

        <div style={{
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
          <div>
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
              <span ref={line1Ref} style={{ display: 'block', opacity: 0, willChange: 'transform, opacity' }}>
                Cada minuto que un estudiante
              </span>
              {/* Línea 2 — stagger 0.15s */}
              <span ref={line2Ref} style={{ display: 'block', opacity: 0, willChange: 'transform, opacity' }}>
                desaparece, la institución responde.
              </span>
            </h1>

            {/* Subtítulo */}
            <p ref={subtitleRef} className="nx-body" style={{ maxWidth: '500px', marginBottom: '2.5rem', opacity: 0 }}>
              NEXO cierra ese vacío. Control de presencia, trazabilidad completa y comunicación
              institucional automatizada — en una infraestructura que opera sin internet,
              sin excusas y sin puntos de falla.
            </p>

            {/* CTAs */}
            <div ref={ctaRef} style={{ display: 'flex', alignItems: 'center', gap: '1.25rem', flexWrap: 'wrap', marginBottom: '1.25rem', opacity: 0 }}>
              {/* CAMBIO 5: Nuevo CTA — abre modal */}
              <button
                id="hero-cta-primary"
                className="nx-btn-primary"
                onClick={() => setModalOpen(true)}
                type="button"
              >
                Quiero que NEXO llegue a mi institución
              </button>
              <a href="#como-funciona" className="nx-link-arrow" id="hero-cta-how">
                ¿Eres rector o directivo? Ve cómo funciona
                <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden>
                  <path d="M2 7h10M8 3l4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
                </svg>
              </a>
            </div>

            {/* Micro-copy */}
            <p ref={microRef} className="nx-micro" style={{ opacity: 0 }}>
              Sin compromisos · Presentación adaptada al contexto de la institución.
            </p>
          </div>

          {/* ── RIGHT: 3D Node — parallax scroll en Y ── */}
          <div
            ref={canvasRef}
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
            <NexoCanvas type="solo" scale={1.1} coldLight interactive={false} />

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
        </div>

        {/* Scroll indicator */}
        <div aria-hidden="true" style={{
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
          @keyframes scrollBlink { 0%,100%{opacity:.25} 50%{opacity:1} }
          @media (max-width: 768px) {
            #hero > div > div { grid-template-columns: 1fr !important; }
            #hero [aria-label="Modelo 3D del nodo NEXO"] { height: 300px !important; }
          }
        `}</style>
      </section>
    </>
  )
}
