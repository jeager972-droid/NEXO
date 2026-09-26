/**
 * =============================================================================
 * FinalCTASection.jsx — Sección final de llamado a la acción de la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Cierra la landing con el CTA principal "Quiero que NEXO llegue a mi
 *   institución", abriendo el ContactModal. Incluye animación de
 *   materialización carácter a carácter del título, enlaces legales y datos de
 *   contacto directo.
 *
 * DEPENDENCIAS:
 *   - react hooks, gsap / ScrollTrigger
 *   - ContactModal, LegalModal, useStickyScroll
 * =============================================================================
 */

import { useRef, useEffect, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import ContactModal from '../components/ContactModal'
import LegalModal from '../components/LegalModal'
import { useStickyScroll } from '../components/useStickyScroll'
// gsap.registerPlugin called once globally in LandingPage.jsx

// MODULE 10 — FINAL CTA
// CAMBIO 5: CTA → "Quiero que NEXO llegue a mi institución" + modal
// CAMBIO 6: Sticky scroll, materialización carácter a carácter (scale:0.8 + opacity:0 -> natural) y stagger de botones

export default function FinalCTASection() {
  const wrapperRef  = useRef()
  const innerRef    = useRef()
  const titleRef    = useRef()
  const subtitleRef = useRef()
  const btnsRef     = useRef()
  const microRef    = useRef()
  const contactRef  = useRef()

  const [modalOpen, setModalOpen] = useState(false)
  const [legalModal, setLegalModal] = useState(null)

  // Aplicar arquitectura sticky scroll
  useStickyScroll(wrapperRef, innerRef, { isLast: true })

  useEffect(() => {
    const wrapper = wrapperRef.current
    if (!wrapper) return

    // CAMBIO 6: El título entra carácter a carácter (materialización)
    // Descomponemos el título en spans de carácter para el stagger
    const titleEl = titleRef.current
    const isMobile = window.innerWidth <= 768
    if (isMobile && titleEl) {
      gsap.set(titleEl, { opacity: 1 })
      gsap.set([subtitleRef.current, btnsRef.current?.querySelectorAll('button, a') || [], microRef.current, contactRef.current], { opacity: 1, y: 0 })
      return
    }

    if (titleEl) {
      const segments = titleEl.innerHTML.split(/<br\s*\/?>/i)
      titleEl.innerHTML = segments
        .map(seg => seg.trim().split('').map(char =>
          char === ' '
            ? '<span style="display:inline-block;width:0.28em">&nbsp;</span>'
            : `<span style="display:inline-block;opacity:0;transform:scale(0.8)">${char}</span>`
        ).join(''))
        .join('<br/>')
    }

    const chars = titleRef.current?.querySelectorAll('span') || []

    // Timeline por ScrollTrigger con toggleActions
    const tl = gsap.timeline({
      scrollTrigger: {
        trigger:  wrapper,
        start:    'top 75%', // Bug 4: content trigger at 75%
        toggleActions: 'play none none none',
      },
    })

    // Título: stagger 0.025s, scale:0.8 + opacity:0 -> natural, ease: "expo.out"
    tl.to(chars, {
      opacity:   1,
      scale:     1,
      duration:  0.8,
      ease:      'expo.out',
      stagger:   0.025,
    })

    // Subtítulo
    .fromTo(subtitleRef.current,
      { opacity: 0, y: 18 },
      { opacity: 1, y: 0, duration: 0.75, ease: 'power3.out' },
      '-=0.4'
    )

    // Botones: scale:0.94 + opacity:0 -> natural, stagger: 0.15s, delay después del texto: 0.6s
    .fromTo(
      btnsRef.current?.querySelectorAll('button, a') || [],
      { opacity: 0, scale: 0.94 },
      { opacity: 1, scale: 1, duration: 0.6, ease: 'power3.out', stagger: 0.15 },
      0.6 // Delay absoluto desde el inicio de la materialización
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
      {legalModal && <LegalModal type={legalModal} onClose={() => setLegalModal(null)} />}

      <div ref={wrapperRef} className="section-wrapper" id="contacto">
        <section
          ref={innerRef}
          className="section-inner"
          style={{
            paddingLeft:   'var(--nx-section-px)',
            paddingRight:  'var(--nx-section-px)',
            background:    'linear-gradient(180deg, var(--nx-void) 0%, var(--nx-deep) 100%)',
            display:       'flex',
            alignItems:    'center',
          }}
        >
          <div style={{ maxWidth: '760px', margin: '0 auto', textAlign: 'center', width: '100%' }}>
            {/* Eyebrow */}
            <div className="nx-eyebrow" style={{ display: 'flex', justifyContent: 'center', marginBottom: '1.25rem' }}>
              El próximo paso
            </div>

            {/* Título — materialización carácter a carácter */}
            <h3
              ref={titleRef}
              style={{
                fontSize:      'clamp(1.6rem, 3.2vw, 2.4rem)',
                fontWeight:     800,
                letterSpacing: '-0.03em',
                lineHeight:     1.35,
                color:         'var(--nx-white)',
                marginBottom:  '1.25rem',
                overflow:      'visible',
                paddingBottom: '0.25em',
                wordBreak:     'break-word',
              }}
              aria-label="El próximo semestre puede empezar diferente."
            >
              El próximo semestre puede empezar<br />diferente.
            </h3>

            {/* Subtítulo */}
            <p
              ref={subtitleRef}
              className="nx-body"
              style={{ maxWidth: '520px', margin: '0 auto 3rem', opacity: 0 }}
            >
              La implementación de NEXO es más rápida de lo que se imagina.
              Una conversación es suficiente para saber si la institución está lista para empezar el proceso.
            </p>

            {/* Botones */}
            <div
              ref={btnsRef}
              className="nx-cta-buttons"
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
                href="mailto:jhonedisonalvarez21@gmail.com"
                style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', fontSize: '0.875rem', color: 'var(--nx-muted)', transition: 'color 0.25s' }}
                onMouseEnter={e => e.currentTarget.style.color = 'var(--nx-text)'}
                onMouseLeave={e => e.currentTarget.style.color = 'var(--nx-muted)'}
              >
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                  <rect x="1" y="3" width="14" height="10" rx="1.5"/>
                  <polyline points="1,3 8,9 15,3"/>
                </svg>
                jhonedisonalvarez21@gmail.com
              </a>

              <div style={{ width: '1px', height: '16px', background: 'var(--nx-border)' }} aria-hidden />

              <a
                href="https://wa.me/573148622367"
                target="_blank"
                rel="noopener noreferrer"
                style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', fontSize: '0.875rem', color: 'var(--nx-muted)', transition: 'color 0.25s' }}
                onMouseEnter={e => e.currentTarget.style.color = 'var(--nx-text)'}
                onMouseLeave={e => e.currentTarget.style.color = 'var(--nx-muted)'}
              >
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                  <path d="M14 10.67c0 .23-.05.45-.16.66a2.74 2.74 0 01-.42.6c-.27.3-.56.45-.88.46-.23 0-.47-.05-.73-.16L8 9.7 3.2 12.23a1.8 1.8 0 01-.73.16 1.4 1.4 0 01-.88-.46 2.74 2.74 0 01-.42-.6A1.6 1.6 0 011 10.67V3.4c0-.62.22-1.15.67-1.6A2.17 2.17 0 013.27 1.1h9.46c.62 0 1.15.23 1.6.7.45.45.67.98.67 1.6v7.27z"/>
                </svg>
                +57 314 862 2367 (WhatsApp)
              </a>
            </div>

            {/* Legal links */}
            <div style={{
              marginTop: '2.5rem',
              display: 'flex',
              justifyContent: 'center',
              gap: '0.5rem',
              flexWrap: 'wrap',
              fontSize: '0.75rem',
              color: 'var(--nx-muted)',
            }}>
              <button
                type="button"
                onClick={() => setLegalModal('privacy')}
                style={{ background: 'none', border: 'none', color: 'var(--nx-muted)', cursor: 'pointer', fontSize: '0.75rem', padding: '0.25rem 0.4rem', transition: 'color 0.2s' }}
                onMouseEnter={e => e.currentTarget.style.color = 'var(--nx-text)'}
                onMouseLeave={e => e.currentTarget.style.color = 'var(--nx-muted)'}
              >
                Política de privacidad
              </button>
              <span style={{ color: 'var(--nx-border)' }}>|</span>
              <button
                type="button"
                onClick={() => setLegalModal('treatment')}
                style={{ background: 'none', border: 'none', color: 'var(--nx-muted)', cursor: 'pointer', fontSize: '0.75rem', padding: '0.25rem 0.4rem', transition: 'color 0.2s' }}
                onMouseEnter={e => e.currentTarget.style.color = 'var(--nx-text)'}
                onMouseLeave={e => e.currentTarget.style.color = 'var(--nx-muted)'}
              >
                Tratamiento de datos
              </button>
              <span style={{ color: 'var(--nx-border)' }}>|</span>
              <button
                type="button"
                onClick={() => setLegalModal('terms')}
                style={{ background: 'none', border: 'none', color: 'var(--nx-muted)', cursor: 'pointer', fontSize: '0.75rem', padding: '0.25rem 0.4rem', transition: 'color 0.2s' }}
                onMouseEnter={e => e.currentTarget.style.color = 'var(--nx-text)'}
                onMouseLeave={e => e.currentTarget.style.color = 'var(--nx-muted)'}
              >
                Términos de uso
              </button>
            </div>
          </div>
        </section>

        <style>{`
          @media (max-width: 768px) {
            #contacto .section-inner {
              text-align: left !important;
            }
            #contacto h2 {
              font-size: clamp(1.5rem, 6vw, 1.85rem) !important;
              overflow: visible !important;
              padding-bottom: 0.3em !important;
            }
            .nx-cta-buttons {
              flex-direction: column !important;
              align-items:    stretch !important;
              gap:            0.875rem !important;
            }
            .nx-cta-buttons button,
            .nx-cta-buttons a {
              width:            100% !important;
              justify-content:  center !important;
              text-align:       center !important;
            }
            #contacto [style*="flexWrap"] {
              justify-content: flex-start !important;
              gap: 1rem !important;
            }
          }
        `}</style>
      </div>
    </>
  )
}
