import { useRef } from 'react'
import { useReveal } from '../components/useReveal'

// MODULE 03 — PROBLEM STATEMENT
// Psychological trigger: cognitive mirror — describing the pain precisely = authority over solution

const PROBLEMS = [
  {
    id: 'lista',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <rect x="4" y="3" width="20" height="22" rx="2"/>
        <line x1="9" y1="9" x2="19" y2="9"/>
        <line x1="9" y1="14" x2="19" y2="14"/>
        <line x1="9" y1="19" x2="15" y2="19"/>
      </svg>
    ),
    title: 'La lista de asistencia manual',
    body: '30 minutos por profesor, por día. Multiplicado por cada docente de tu institución. Ese tiempo no vuelve — y nunca fue tiempo administrativo. Era tiempo de cátedra.',
  },
  {
    id: 'salida',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="M18 14H4M4 14l4-4M4 14l4 4"/>
        <path d="M12 5h9a2 2 0 012 2v14a2 2 0 01-2 2h-9"/>
      </svg>
    ),
    title: 'El estudiante que nadie vio salir',
    body: 'Entre cambio de clase y cambio de clase, entre un baño y el siguiente, hay un espacio donde la institución pierde visibilidad. Cuando algo ocurre en ese espacio, la responsabilidad recae sobre todos.',
  },
  {
    id: 'padre',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="M20 4H8a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2z"/>
        <line x1="14" y1="10" x2="14" y2="16"/>
        <circle cx="14" cy="19" r="0.5" fill="currentColor"/>
      </svg>
    ),
    title: 'El padre que se enteró tarde',
    body: 'La inasistencia registrada en papel, archivada en una carpeta, comunicada tres días después — o nunca. La familia no estaba en el circuito. La institución tampoco.',
  },
]

export default function ProblemSection() {
  const sectionRef = useRef()
  useReveal(sectionRef)

  return (
    <section
      ref={sectionRef}
      id="el-problema"
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
        {/* Eyebrow */}
        <div className="nx-eyebrow nx-reveal" style={{ marginBottom: '1rem' }}>
          El diagnóstico
        </div>

        {/* Title */}
        <h2 className="nx-h2 nx-reveal nx-reveal-delay-1" style={{ maxWidth: '680px', marginBottom: '5rem' }}>
          El problema no era la voluntad.<br />Era la infraestructura.
        </h2>

        {/* 3-column problem grid */}
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(3, 1fr)',
            gap: '2.5rem',
          }}
        >
          {PROBLEMS.map(({ id, icon, title, body }, i) => (
            <div
              key={id}
              className={`nx-reveal nx-reveal-delay-${i + 2}`}
              style={{
                borderTop: '1px solid var(--nx-border)',
                paddingTop: '2rem',
              }}
            >
              {/* Icon */}
              <div className="nx-icon" style={{ marginBottom: '1.5rem' }}>
                {icon}
              </div>

              {/* Title */}
              <h3 className="nx-h3" style={{ marginBottom: '0.85rem' }}>{title}</h3>

              {/* Body */}
              <p className="nx-body" style={{ fontSize: '0.9rem' }}>{body}</p>
            </div>
          ))}
        </div>

        {/* Closing statement */}
        <div
          className="nx-reveal nx-reveal-delay-5"
          style={{
            marginTop: '4.5rem',
            paddingTop: '2.5rem',
            borderTop: '1px solid var(--nx-border)',
            display: 'flex',
            justifyContent: 'center',
          }}
        >
          <p
            style={{
              maxWidth: '640px',
              textAlign: 'center',
              fontSize: '1rem',
              lineHeight: 1.7,
              color: 'var(--nx-text)',
              fontStyle: 'italic',
            }}
          >
            NEXO no es una aplicación más. Es la infraestructura que cierra estos tres vacíos simultáneamente, en tiempo real, sin depender de la conexión a internet de la institución.
          </p>
        </div>
      </div>

      <style>{`
        @media (max-width: 768px) {
          #el-problema [style*="repeat(3, 1fr)"] {
            grid-template-columns: 1fr !important;
          }
        }
      `}</style>
    </section>
  )
}
