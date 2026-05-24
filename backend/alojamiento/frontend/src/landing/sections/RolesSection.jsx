import { useRef, useState } from 'react'
import { useReveal } from '../components/useReveal'

// MODULE 07 — ROLES & USE CASES
// Psychological trigger: role identification + personalised relevance

const ROLES = [
  {
    id: 'rector',
    label: 'Rector',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="11" cy="7" r="3.5"/>
        <path d="M3 20c0-4.4 3.6-8 8-8s8 3.6 8 8"/>
        <path d="M15 3l2 2-2 2"/>
      </svg>
    ),
    headline: 'Tu firma institucional está protegida.',
    body: 'Accede a la auditoría completa de tu institución. Cada acción de cada rol queda registrada — incluyendo quién borró un registro y a qué hora.',
    features: [
      'Auditoría completa con marca de tiempo por acción',
      'Registro de quién eliminó o modificó datos',
      'Descarga de informes listos para entes de control',
    ],
  },
  {
    id: 'coordinador',
    label: 'Coordinador',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <rect x="2" y="3" width="18" height="15" rx="2"/>
        <line x1="8" y1="21" x2="14" y2="21"/>
        <line x1="11" y1="18" x2="11" y2="21"/>
        <line x1="6" y1="8" x2="16" y2="8"/>
        <line x1="6" y1="12" x2="12" y2="12"/>
      </svg>
    ),
    headline: 'Detecta problemas antes de que escalen.',
    body: 'Evasiones entre clases, salidas frecuentes, llegadas tarde recurrentes — todo visible en un panel, con alertas automáticas antes de que el problema escale.',
    features: [
      'Panel de patrones y anomalías en tiempo real',
      'Alertas automáticas configurables por umbral',
      'Historial por estudiante exportable',
    ],
  },
  {
    id: 'profesor',
    label: 'Profesor',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
        <path d="M2 17l10 5 10-5"/>
        <path d="M2 12l10 5 10-5"/>
      </svg>
    ),
    headline: 'Recupera 30 minutos diarios de cátedra.',
    body: 'Cita acudientes con un botón. Reporta daños desde tu teléfono. Tu carga administrativa se reduce a cero — sin cambiar tus rutinas de clase.',
    features: [
      'Asistencia automática — sin intervención manual',
      'Citar acudientes desde el móvil en un toque',
      'Reportes de daños o incidentes desde la app',
    ],
  },
  {
    id: 'secretaria',
    label: 'Secretaría',
    icon: (
      <svg width="22" height="22" viewBox="0 0 22 22" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <path d="M3 3h16a1 1 0 011 1v14a1 1 0 01-1 1H3a1 1 0 01-1-1V4a1 1 0 011-1z"/>
        <polyline points="16 3 16 10 13 8 10 10 10 3"/>
      </svg>
    ),
    headline: 'Trazabilidad a escala municipal o departamental.',
    body: 'Implementa custodia estudiantil en todas las instituciones de tu jurisdicción. Accede a reportes consolidados. Justifica la inversión con datos reales descargables.',
    features: [
      'Reportes consolidados por institución y sede',
      'Panel de supervisión multi-institución',
      'Exportación de datos para rendición de cuentas',
    ],
  },
]

export default function RolesSection() {
  const sectionRef = useRef()
  const [activeRole, setActiveRole] = useState(0)
  useReveal(sectionRef)

  const role = ROLES[activeRole]

  return (
    <section
      ref={sectionRef}
      id="roles"
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
        <div className="nx-eyebrow nx-reveal">Por rol</div>
        <h2
          className="nx-h2 nx-reveal nx-reveal-delay-1"
          style={{ maxWidth: '700px', marginBottom: '1rem' }}
        >
          NEXO opera diferente para cada rol.
        </h2>
        <p
          className="nx-body nx-reveal nx-reveal-delay-2"
          style={{ maxWidth: '500px', marginBottom: '3.5rem' }}
        >
          Pero todos ven lo mismo: control total.
        </p>

        {/* Tabs */}
        <div className="nx-tabs nx-reveal nx-reveal-delay-3">
          {ROLES.map((r, i) => (
            <button
              key={r.id}
              id={`tab-${r.id}`}
              className={`nx-tab${activeRole === i ? ' active' : ''}`}
              onClick={() => setActiveRole(i)}
              aria-selected={activeRole === i}
              role="tab"
            >
              {r.label}
            </button>
          ))}
        </div>

        {/* Tab panel */}
        <div
          key={role.id}
          className="nx-reveal"
          role="tabpanel"
          aria-labelledby={`tab-${role.id}`}
          style={{
            display: 'grid',
            gridTemplateColumns: 'auto 1fr',
            gap: '3.5rem',
            alignItems: 'start',
            animation: 'tabFade 0.35s var(--nx-ease)',
          }}
        >
          {/* Icon */}
          <div
            className="nx-icon"
            style={{ width: '3.5rem', height: '3.5rem', borderRadius: '1rem', marginTop: '0.25rem' }}
          >
            {role.icon}
          </div>

          <div>
            {/* Role label */}
            <div
              style={{
                fontSize: '0.7rem',
                fontWeight: 700,
                letterSpacing: '0.12em',
                textTransform: 'uppercase',
                color: 'var(--nx-blue)',
                marginBottom: '0.75rem',
              }}
            >
              {role.label}
            </div>

            {/* Headline */}
            <h3
              className="nx-h3"
              style={{ fontSize: '1.4rem', fontWeight: 800, marginBottom: '0.85rem' }}
            >
              {role.headline}
            </h3>

            {/* Body */}
            <p className="nx-body" style={{ marginBottom: '2rem', maxWidth: '560px' }}>
              {role.body}
            </p>

            {/* Feature list */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              {role.features.map(f => (
                <div
                  key={f}
                  style={{ display: 'flex', alignItems: 'center', gap: '0.85rem', fontSize: '0.875rem', color: 'var(--nx-text)' }}
                >
                  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden>
                    <circle cx="8" cy="8" r="7" stroke="var(--nx-blue)" strokeWidth="1"/>
                    <path d="M5 8l2 2 4-4" stroke="var(--nx-blue)" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round"/>
                  </svg>
                  {f}
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>

      <style>{`
        @keyframes tabFade {
          from { opacity: 0; transform: translateY(12px); }
          to   { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 640px) {
          #roles [style*="grid-template-columns: auto 1fr"] {
            grid-template-columns: 1fr !important;
          }
          .nx-tabs { overflow-x: auto; }
          .nx-tab  { font-size: 0.78rem; padding: 0.65rem 1rem; }
        }
      `}</style>
    </section>
  )
}
