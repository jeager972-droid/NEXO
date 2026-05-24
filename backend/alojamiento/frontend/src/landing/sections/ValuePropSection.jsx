import { useRef } from 'react'
import { useReveal } from '../components/useReveal'

// MODULE 05 — VALUE PROPOSITION
// Psychological trigger: before/after value anchor — highest persuasive density

const ROWS = [
  {
    before: 'Lista de asistencia manual, 30 min/día por docente',
    after:  'Registro automático en menos de 1 segundo por estudiante',
  },
  {
    before: 'El padre se entera de la inasistencia días después',
    after:  'Notificación WhatsApp en tiempo real, el mismo momento',
  },
  {
    before: 'El coordinador no sabe quién salió al baño ni cuántas veces',
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
  const sectionRef = useRef()
  useReveal(sectionRef)

  return (
    <section
      ref={sectionRef}
      id="propuesta-de-valor"
      className="nx-section"
      style={{
        background: 'var(--nx-void)',
        paddingTop: '7rem',
        paddingBottom: '7rem',
        paddingLeft: 'var(--nx-section-px)',
        paddingRight: 'var(--nx-section-px)',
      }}
    >
      <div style={{ maxWidth: '1280px', margin: '0 auto' }}>
        {/* Header */}
        <div className="nx-eyebrow nx-reveal">Transformación</div>
        <h2
          className="nx-h2 nx-reveal nx-reveal-delay-1"
          style={{ maxWidth: '720px', marginBottom: '1rem' }}
        >
          De la operación reactiva<br />a la custodia proactiva.
        </h2>
        <p
          className="nx-body nx-reveal nx-reveal-delay-2"
          style={{ maxWidth: '580px', marginBottom: '3.5rem' }}
        >
          Las instituciones que operan con NEXO no esperan que algo ocurra para actuar.
          Saben qué ocurre, cuándo ocurre y quién es responsable — antes de que escale.
        </p>

        {/* Before / After table */}
        <div className="nx-ba-table nx-reveal nx-reveal-delay-3">
          {/* SIN NEXO column */}
          <div className="nx-ba-col nx-ba-col--before">
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

          {/* CON NEXO column */}
          <div className="nx-ba-col nx-ba-col--after">
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
        <div
          className="nx-reveal nx-reveal-delay-4"
          style={{ marginTop: '2.5rem', display: 'flex', justifyContent: 'center' }}
        >
          <a href="#descarga-resumen" className="nx-link-arrow" style={{ fontSize: '0.875rem' }}>
            Descarga el resumen ejecutivo para secretarías de educación
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden>
              <path d="M2 7h10M8 3l4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
          </a>
        </div>
      </div>

      <style>{`
        @media (max-width: 768px) {
          .nx-ba-table {
            grid-template-columns: 1fr !important;
          }
          .nx-ba-divider { display: none !important; }
          .nx-ba-col--after {
            border-top: 1px solid var(--nx-border);
          }
        }
      `}</style>
    </section>
  )
}
