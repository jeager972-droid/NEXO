import { useRef } from 'react'
import { useReveal } from '../components/useReveal'

// MODULE 09 — SECURITY & TRUST
// Psychological trigger: fear elimination + regulatory authority (Colombian MEN / SIC)

const TRUST_POINTS = [
  {
    id: 'biometric',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M6 7a5 5 0 0110 0"/>
        <path d="M8 11a3 3 0 016 0"/>
        <path d="M11 14v3"/>
        <circle cx="11" cy="19" r="1" fill="currentColor" stroke="none"/>
      </svg>
    ),
    title: 'Biometría en el nodo',
    body: 'Los registros biométricos nunca salen del nodo en formato legible.',
  },
  {
    id: 'encrypt',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round">
        <rect x="5" y="10" width="12" height="10" rx="2"/>
        <path d="M8 10V7a3 3 0 016 0v3"/>
        <circle cx="11" cy="15" r="1.5" fill="currentColor" stroke="none"/>
      </svg>
    ),
    title: 'Encriptación E2E',
    body: 'Encriptación de extremo a extremo en cada transmisión de datos.',
  },
  {
    id: 'audit',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M9 5H7a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
        <rect x="9" y="3" width="4" height="4" rx="1"/>
        <line x1="9" y1="12" x2="13" y2="12"/>
        <line x1="9" y1="16" x2="11" y2="16"/>
      </svg>
    ),
    title: 'Auditoría total',
    body: 'Sabes exactamente quién tocó qué dato y cuándo. Cada acción registrada.',
  },
  {
    id: 'compliance',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M11 2L3 6v6c0 4.4 3.4 8.5 8 9.5 4.6-1 8-5.1 8-9.5V6l-8-4z"/>
        <polyline points="8 11 10 13 14 9"/>
      </svg>
    ),
    title: 'MEN + SIC',
    body: 'Cumplimiento con lineamientos de protección de datos del MEN y la SIC.',
  },
  {
    id: 'nothirdparty',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.2" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="11" cy="11" r="9"/>
        <line x1="4.9" y1="4.9" x2="17.1" y2="17.1"/>
      </svg>
    ),
    title: 'Sin terceros',
    body: 'Sin venta de datos. Sin terceros con acceso. Sin publicidad de ningún tipo.',
  },
]

// Animated shield SVG
function Shield() {
  return (
    <svg
      className="nx-shield"
      width="120"
      height="140"
      viewBox="0 0 120 140"
      fill="none"
      aria-hidden="true"
    >
      {/* Outer shield */}
      <path
        d="M60 8L12 28v38c0 30 20 56 48 64 28-8 48-34 48-64V28L60 8z"
        stroke="rgba(10,132,255,0.4)"
        strokeWidth="1.5"
        fill="none"
      />
      {/* Inner shield */}
      <path
        d="M60 20L24 36v28c0 22 15 42 36 48 21-6 36-26 36-48V36L60 20z"
        stroke="rgba(10,132,255,0.6)"
        strokeWidth="1"
        fill="rgba(10,132,255,0.04)"
      />
      {/* Check */}
      <path
        d="M44 68l12 12 20-20"
        stroke="var(--nx-blue)"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
      {/* Glow ring */}
      <circle cx="60" cy="68" r="24" stroke="rgba(10,132,255,0.15)" strokeWidth="1" fill="none"/>
    </svg>
  )
}

export default function SecuritySection() {
  const sectionRef = useRef()
  useReveal(sectionRef)

  return (
    <section
      ref={sectionRef}
      id="seguridad"
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
        {/* Layout: shield left + content right */}
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: '1fr 1.4fr',
            gap: '5rem',
            alignItems: 'center',
          }}
        >
          {/* Left — shield + title */}
          <div className="nx-reveal">
            <div className="nx-eyebrow" style={{ marginBottom: '1rem' }}>Seguridad</div>
            <h2 className="nx-h2" style={{ marginBottom: '1.25rem' }}>
              Los datos de tus estudiantes no son un activo de nadie más.
            </h2>
            <p className="nx-body" style={{ marginBottom: '2.5rem' }}>
              NEXO fue diseñado desde cero con protección de datos como principio de arquitectura, no como característica adicional.
            </p>

            <Shield />

            <p
              style={{
                marginTop: '2rem',
                fontSize: '0.875rem',
                fontStyle: 'italic',
                color: 'var(--nx-muted)',
                lineHeight: 1.6,
                maxWidth: '380px',
              }}
            >
              La confianza de una institución pública no se gana con palabras. Se demuestra con arquitectura.
            </p>
          </div>

          {/* Right — trust points grid */}
          <div
            className="nx-security-grid nx-reveal nx-reveal-delay-2"
          >
            {TRUST_POINTS.map(({ id, icon, title, body }, i) => (
              <div
                key={id}
                className={`nx-card nx-reveal nx-reveal-delay-${i + 1}`}
                style={{ padding: '1.5rem' }}
              >
                <div className="nx-icon" style={{ marginBottom: '1rem' }}>
                  {icon}
                </div>
                <h3 style={{ fontSize: '0.9rem', fontWeight: 700, color: 'var(--nx-white)', marginBottom: '0.4rem' }}>
                  {title}
                </h3>
                <p style={{ fontSize: '0.8rem', color: 'var(--nx-muted)', lineHeight: 1.6 }}>
                  {body}
                </p>
              </div>
            ))}

            {/* 6th cell — compliance badge */}
            <div
              className="nx-card nx-reveal nx-reveal-delay-5"
              style={{
                padding: '1.5rem',
                background: 'rgba(10,132,255,0.05)',
                borderColor: 'rgba(10,132,255,0.2)',
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'center',
                alignItems: 'center',
                textAlign: 'center',
                gap: '0.5rem',
              }}
            >
              <div style={{ fontSize: '1.5rem', fontWeight: 800, color: 'var(--nx-blue)' }}>Ley 1581</div>
              <div style={{ fontSize: '0.72rem', color: 'var(--nx-muted)', letterSpacing: '0.06em', textTransform: 'uppercase' }}>
                Protección de Datos Colombia
              </div>
            </div>
          </div>
        </div>
      </div>

      <style>{`
        @media (max-width: 768px) {
          #seguridad [style*="repeat(2, 1fr)"] {
            grid-template-columns: 1fr !important;
          }
          #seguridad > div > div {
            grid-template-columns: 1fr !important;
          }
        }
      `}</style>
    </section>
  )
}
