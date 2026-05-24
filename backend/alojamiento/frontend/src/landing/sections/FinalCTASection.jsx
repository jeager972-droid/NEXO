import { useRef } from 'react'
import { useReveal } from '../components/useReveal'

// MODULE 10 — FINAL CTA
// Psychological trigger: soft real urgency + two clear conversion paths

export default function FinalCTASection() {
  const sectionRef = useRef()
  useReveal(sectionRef)

  return (
    <section
      ref={sectionRef}
      id="contacto"
      className="nx-section nx-cta-final"
      style={{
        paddingTop: '9rem',
        paddingBottom: '9rem',
        paddingLeft: 'var(--nx-section-px)',
        paddingRight: 'var(--nx-section-px)',
      }}
    >
      <div style={{ maxWidth: '760px', margin: '0 auto', textAlign: 'center' }}>
        {/* Eyebrow */}
        <div className="nx-eyebrow nx-reveal" style={{ display: 'flex', justifyContent: 'center', marginBottom: '1.25rem' }}>
          El próximo paso
        </div>

        {/* Title */}
        <h2
          className="nx-h1 nx-reveal nx-reveal-delay-1"
          style={{ marginBottom: '1.25rem', letterSpacing: '-0.03em' }}
        >
          El próximo semestre puede empezar diferente.
        </h2>

        {/* Subtitle */}
        <p
          className="nx-body nx-reveal nx-reveal-delay-2"
          style={{ maxWidth: '520px', margin: '0 auto 3rem' }}
        >
          La implementación de NEXO es más rápida de lo que imaginas.
          Una conversación es suficiente para saber si tu institución está lista.
        </p>

        {/* CTA buttons */}
        <div
          className="nx-reveal nx-reveal-delay-3"
          style={{
            display: 'flex',
            gap: '1rem',
            justifyContent: 'center',
            flexWrap: 'wrap',
            marginBottom: '1.5rem',
          }}
        >
          <a
            href="#demo"
            className="nx-btn-primary"
            id="final-cta-demo"
            style={{ fontSize: '0.95rem', padding: '1rem 2rem' }}
          >
            Solicitar demostración institucional
          </a>
          <a
            href="#propuesta-tecnica"
            className="nx-btn-ghost"
            id="final-cta-proposal"
            style={{ fontSize: '0.95rem', padding: '1rem 2rem' }}
          >
            Descargar propuesta técnica
          </a>
        </div>

        {/* Micro-copy */}
        <p
          className="nx-micro nx-reveal nx-reveal-delay-4"
          style={{ marginBottom: '4rem' }}
        >
          Sin costos de evaluación · Sin compromisos previos al contrato · Con acompañamiento desde el primer contacto
        </p>

        {/* Divider */}
        <div className="nx-divider nx-reveal nx-reveal-delay-4" style={{ marginBottom: '2.5rem' }} />

        {/* Direct contact */}
        <div
          className="nx-reveal nx-reveal-delay-5"
          style={{
            display: 'flex',
            gap: '2.5rem',
            justifyContent: 'center',
            flexWrap: 'wrap',
            alignItems: 'center',
          }}
        >
          {/* Email */}
          <a
            href="mailto:contacto@nexo.edu.co"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '0.6rem',
              fontSize: '0.875rem',
              color: 'var(--nx-muted)',
              transition: 'color 0.25s',
            }}
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

          {/* WhatsApp */}
          <a
            href="https://wa.me/573100000000"
            target="_blank"
            rel="noopener noreferrer"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '0.6rem',
              fontSize: '0.875rem',
              color: 'var(--nx-muted)',
              transition: 'color 0.25s',
            }}
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
  )
}
