import { useRef, useEffect, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import ContactModal from '../components/ContactModal'

gsap.registerPlugin(ScrollTrigger)

// MODULE 10 — FINAL CTA
// CAMBIO 5: CTA → "Quiero que NEXO llegue a mi institución" + modal
// CAMBIO 6: SplitText manual por caracteres → efecto de materialización

export default function FinalCTASection() {
  const sectionRef  = useRef()
  const titleRef    = useRef()
  const subtitleRef = useRef()
  const btnsRef     = useRef()
  const microRef    = useRef()
  const contactRef  = useRef()

  const [modalOpen, setModalOpen] = useState(false)

  useEffect(() => {
    const section = sectionRef.current
    if (!section) return

    // CAMBIO 6: El título entra carácter a carácter (materialización)
    // Descomponemos el título en spans de carácter para el stagger 0.03s
    const titleEl = titleRef.current
    if (titleEl) {
      const original = titleEl.textContent
      titleEl.innerHTML = original
        .split('')
        .map(char =>
          char === ' '
            ? '<span style="display:inline-block;width:0.28em">&nbsp;</span>'
            : `<span style="display:inline-block;opacity:0;transform:translateY(20px)">${char}</span>`
        )
        .join('')
    }

    // Timeline por ScrollTrigger — se activa cuando la sección entra al viewport
    const tl = gsap.timeline({
      scrollTrigger: {
        trigger:  section,
        start:    'top 65%',
        once:     true,
      },
    })

    // Materialización de caracteres — stagger 0.03s
    tl.to(titleRef.current?.querySelectorAll('span') || [], {
      opacity:   1,
      y:         0,
      duration:  0.6,
      ease:      'power3.out',
      stagger:   0.03,
    })

    // Subtítulo
    .fromTo(subtitleRef.current,
      { opacity: 0, y: 18 },
      { opacity: 1, y: 0, duration: 0.75, ease: 'power3.out' },
      '-=0.2'
    )

    // Botones — escala 0.94 + opacity, stagger 0.15s entre ellos
    .fromTo(
      btnsRef.current?.querySelectorAll('button, a') || [],
      { opacity: 0, scale: 0.94, y: 10 },
      { opacity: 1, scale: 1,    y: 0, duration: 0.6, ease: 'power3.out', stagger: 0.15 },
      '-=0.3'
    )

    .fromTo(microRef.current,
      { opacity: 0 },
      { opacity: 1, duration: 0.5, ease: 'power2.out' },
      '-=0.1'
    )

    .fromTo(contactRef.current,
      { opacity: 0, y: 12 },
      { opacity: 1, y: 0, duration: 0.5, ease: 'power3.out' },
      '-=0.2'
    )

    return () => tl.kill()
  }, [])

  return (
    <>
      {/* CAMBIO 5: Modal */}
      {modalOpen && <ContactModal onClose={() => setModalOpen(false)} />}

      <section
        ref={sectionRef}
        id="contacto"
        className="nx-section nx-cta-final"
        style={{
          paddingTop:    '9rem',
          paddingBottom: '9rem',
          paddingLeft:   'var(--nx-section-px)',
          paddingRight:  'var(--nx-section-px)',
        }}
      >
        <div style={{ maxWidth: '760px', margin: '0 auto', textAlign: 'center' }}>
          {/* Eyebrow */}
          <div className="nx-eyebrow" style={{ display: 'flex', justifyContent: 'center', marginBottom: '1.25rem' }}>
            El próximo paso
          </div>

          {/* CAMBIO 6: Título — materialización carácter a carácter */}
          <h2
            ref={titleRef}
            style={{
              fontSize:      'clamp(1.9rem, 4vw, 2.75rem)',
              fontWeight:     800,
              letterSpacing: '-0.03em',
              lineHeight:     1.12,
              color:         'var(--nx-white)',
              marginBottom:  '1.25rem',
            }}
            aria-label="El próximo semestre puede empezar diferente."
          >
            El próximo semestre puede empezar diferente.
          </h2>

          {/* Subtítulo */}
          <p
            ref={subtitleRef}
            className="nx-body"
            style={{ maxWidth: '520px', margin: '0 auto 3rem', opacity: 0 }}
          >
            La implementación de NEXO es más rápida de lo que se imagina.
            Una conversación es suficiente para saber si la institución está lista.
          </p>

          {/* CAMBIO 5: CTAs — botón principal abre modal */}
          <div
            ref={btnsRef}
            style={{
              display:        'flex',
              gap:            '1rem',
              justifyContent: 'center',
              flexWrap:       'wrap',
              marginBottom:   '1.5rem',
            }}
          >
            <button
              id="final-cta-primary"
              className="nx-btn-primary"
              onClick={() => setModalOpen(true)}
              type="button"
              style={{ fontSize: '0.95rem', padding: '1rem 2rem', opacity: 0 }}
            >
              Quiero que NEXO llegue a mi institución
            </button>
            <a
              href="/assets/downloads/nexo.apk"
              id="final-cta-proposal"
              className="nx-btn-ghost"
              style={{ fontSize: '0.95rem', padding: '1rem 2rem', opacity: 0 }}
              download
            >
              Descargar propuesta técnica
            </a>
          </div>

          {/* Micro-copy */}
          <p ref={microRef} className="nx-micro" style={{ marginBottom: '4rem', opacity: 0 }}>
            Sin costos de evaluación · Sin compromisos previos al contrato · Con acompañamiento desde el primer contacto
          </p>

          {/* Divider */}
          <div className="nx-divider" style={{ marginBottom: '2.5rem' }} />

          {/* Contacto directo */}
          <div
            ref={contactRef}
            style={{
              display:        'flex',
              gap:            '2.5rem',
              justifyContent: 'center',
              flexWrap:       'wrap',
              alignItems:     'center',
              opacity:         0,
            }}
          >
            <a
              href="mailto:contacto@nexo.edu.co"
              style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', fontSize: '0.875rem', color: 'var(--nx-muted)', transition: 'color 0.25s' }}
              onMouseEnter={e => e.currentTarget.style.color = 'var(--nx-text)'}
              onMouseLeave={e => e.currentTarget.style.color = 'var(--nx-muted)'}
            >
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <rect x="1" y="3" width="14" height="10" rx="1.5"/>
                <polyline points="1,3 8,9 15,3"/>
              </svg>
              contacto@nexo.edu.co
            </a>

            <div style={{ width: '1px', height: '16px', background: 'var(--nx-border)' }} aria-hidden />

            <a
              href="https://wa.me/573100000000"
              target="_blank"
              rel="noopener noreferrer"
              style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', fontSize: '0.875rem', color: 'var(--nx-muted)', transition: 'color 0.25s' }}
              onMouseEnter={e => e.currentTarget.style.color = 'var(--nx-text)'}
              onMouseLeave={e => e.currentTarget.style.color = 'var(--nx-muted)'}
            >
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M14 10.67c0 .23-.05.45-.16.66a2.74 2.74 0 01-.42.6c-.27.3-.56.45-.88.46-.23 0-.47-.05-.73-.16L8 9.7 3.2 12.23a1.8 1.8 0 01-.73.16 1.4 1.4 0 01-.88-.46 2.74 2.74 0 01-.42-.6A1.6 1.6 0 011 10.67V3.4c0-.62.22-1.15.67-1.6A2.17 2.17 0 013.27 1.1h9.46c.62 0 1.15.23 1.6.7.45.45.67.98.67 1.6v7.27z"/>
              </svg>
              +57 310 000 0000 (WhatsApp)
            </a>
          </div>
        </div>
      </section>
    </>
  )
}
