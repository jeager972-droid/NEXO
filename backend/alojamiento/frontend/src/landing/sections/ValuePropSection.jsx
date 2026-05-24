import { useRef, useEffect } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { useStickyScroll } from '../components/useStickyScroll'
// gsap.registerPlugin called once globally in LandingPage.jsx

// MODULE 05 — PROPUESTA DE VALOR
// CAMBIO 6: Sticky scroll, columna izquierda desde x:-60px, columna derecha desde x:+60px, con ease expo.out, stagger de filas.

const ROWS = [
  {
    before: 'Lista de asistencia manual — tiempo de cátedra que no vuelve',
    after:  'Registro automático en menos de 1 segundo por estudiante',
  },
  {
    before: 'Los acudientes se enteran de la inasistencia días después',
    after:  'Notificación vía WhatsApp en tiempo real, el mismo momento',
  },
  {
    before: 'Los coordinadores no saben quién salió ni cuántas veces',
    after:  'Panel de alertas con patrones detectados automáticamente',
  },
  {
    before: 'Los registros existen en papel, vulnerables y dispersos',
    after:  'Auditoría digital inalterable, descargable en Word o Excel',
  },
  {
    before: 'Si se va la luz o el internet, el sistema colapsa',
    after:  'Operación autónoma: batería 12h + conectividad M2M propia',
  },
]

export default function ValuePropSection() {
  const wrapperRef  = useRef()
  const innerRef    = useRef()
  const eyebrowRef  = useRef()
  const titleRef    = useRef()
  const subtitleRef = useRef()
  const beforeRef   = useRef()
  const afterRef    = useRef()
  const ctaRef      = useRef()

  // Aplicar arquitectura sticky scroll
  useStickyScroll(wrapperRef, innerRef)

  useEffect(() => {
    const wrapper = wrapperRef.current
    const inner = innerRef.current
    if (!wrapper || !inner) return

    // Set initial hidden states
    gsap.set(beforeRef.current, { opacity: 0, x: -60 })
    gsap.set(afterRef.current, { opacity: 0, x: 60 })
    gsap.set(inner.querySelectorAll('.nx-ba-row'), { opacity: 0, y: 16 })

    // Timeline disparado por ScrollTrigger con toggleActions
    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: wrapper,
        start:   'top 75%', // Bug 4: content trigger at 75%
        toggleActions: 'play none none none',
      },
    })

    // Eyebrow + título + subtítulo
    tl.fromTo(eyebrowRef.current,
      { opacity: 0, y: -10 },
      { opacity: 1, y: 0, duration: 0.55, ease: 'power3.out' }
    )
    .fromTo(titleRef.current,
      { opacity: 0, y: 40 },
      { opacity: 1, y: 0, duration: 0.9, ease: 'expo.out' },
      '-=0.3'
    )
    .fromTo(subtitleRef.current,
      { opacity: 0, y: 18 },
      { opacity: 1, y: 0, duration: 0.75, ease: 'power3.out' },
      '-=0.5'
    )

    // PASO 4: Columna izquierda y derecha simultáneas, ease "expo.out", duration 1s
    .to(beforeRef.current, {
      opacity: 1,
      x: 0,
      duration: 1,
      ease: 'expo.out'
    }, '-=0.25')
    .to(afterRef.current, {
      opacity: 1,
      x: 0,
      duration: 1,
      ease: 'expo.out'
    }, '-=1.0') // -=1.0 hace que sea simultáneo a beforeRef

    // PASO 4: Filas internas con stagger vertical de 0.08s
    .to(inner.querySelectorAll('.nx-ba-row'), {
      opacity: 1,
      y: 0,
      duration: 0.55,
      ease: 'power3.out',
      stagger: 0.08,
    }, '-=0.6')

    .fromTo(ctaRef.current,
      { opacity: 0, y: 12 },
      { opacity: 1, y: 0, duration: 0.5, ease: 'power3.out' },
      '-=0.1'
    )

    return () => tl.kill()
  }, [])

  return (
    <div ref={wrapperRef} className="section-wrapper" id="propuesta-de-valor">
      <section
        ref={innerRef}
        className="section-inner"
        style={{
          background:    'var(--nx-void)',
          paddingLeft:   'var(--nx-section-px)',
          paddingRight:  'var(--nx-section-px)',
          display:       'flex',
          alignItems:    'center',
        }}
      >
        <div style={{ maxWidth: '1280px', margin: '0 auto', width: '100%' }}>
          {/* Header */}
          <div ref={eyebrowRef} className="nx-eyebrow" style={{ marginBottom: '1rem', opacity: 0 }}>
            Transformación
          </div>
          <h2 ref={titleRef} className="nx-h2" style={{ maxWidth: '720px', marginBottom: '1rem', opacity: 0 }}>
            De la operación reactiva<br />a la custodia proactiva.
          </h2>
          <p ref={subtitleRef} className="nx-body" style={{ maxWidth: '580px', marginBottom: '3.5rem', opacity: 0 }}>
            Las instituciones que operan con NEXO no esperan que algo ocurra para actuar.
            Saben qué ocurre, cuándo ocurre y quién es responsable — antes de que escale.
          </p>

          {/* Before / After table */}
          <div className="nx-ba-table">
            {/* SIN NEXO */}
            <div ref={beforeRef} className="nx-ba-col nx-ba-col--before" style={{ opacity: 0 }}>
              <div className="nx-ba-header nx-ba-header--before">Sin NEXO</div>
              {ROWS.map(({ before }) => (
                <div key={before} className="nx-ba-row">
                  <span className="nx-ba-dot nx-ba-dot--before" />
                  {before}
                </div>
              ))}
            </div>

            {/* Arrow divider */}
            <div className="nx-ba-divider" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                <path d="M4 9h10M10 5l4 4-4 4" stroke="var(--nx-blue)" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </div>

            {/* CON NEXO */}
            <div ref={afterRef} className="nx-ba-col nx-ba-col--after" style={{ opacity: 0 }}>
              <div className="nx-ba-header nx-ba-header--after">Con NEXO</div>
              {ROWS.map(({ after }) => (
                <div key={after} className="nx-ba-row nx-ba-row--after">
                  <span className="nx-ba-dot nx-ba-dot--after" />
                  {after}
                </div>
              ))}
            </div>
          </div>

          {/* Secondary CTA */}
          <div ref={ctaRef} style={{ marginTop: '2.5rem', display: 'flex', justifyContent: 'center', opacity: 0 }}>
            <a href="#descarga-resumen" className="nx-link-arrow" style={{ fontSize: '0.875rem' }}>
              Descarga el resumen ejecutivo para secretarías de educación
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden>
                <path d="M2 7h10M8 3l4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </a>
          </div>
        </div>
      </section>

      <style>{`
        @media (max-width: 768px) {
          .nx-ba-table { grid-template-columns: 1fr !important; }
          .nx-ba-divider { display: none !important; }
          .nx-ba-col--after { border-top: 1px solid var(--nx-border); }
        }
      `}</style>
    </div>
  )
}
