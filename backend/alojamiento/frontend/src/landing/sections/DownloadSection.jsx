import { useRef } from 'react'
import { useReveal } from '../components/useReveal'

// MODULE 08 — APP DOWNLOAD
// Psychological trigger: low-commitment action — first small "yes"

const PLATFORMS = [
  {
    id: 'android',
    name: 'Android',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <path d="M7 10h14v12a2 2 0 01-2 2H9a2 2 0 01-2-2V10z"/>
        <path d="M10 10V7a4 4 0 018 0v3"/>
        <line x1="10" y1="17" x2="10" y2="17.01"/>
        <line x1="14" y1="17" x2="14" y2="17.01"/>
        <line x1="18" y1="17" x2="18" y2="17.01"/>
      </svg>
    ),
    href: '#download-android',
  },
  {
    id: 'ios',
    name: 'iOS',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <path d="M18.5 2C17 2 15.5 3 14 3s-3-1-4.5-1C6 2 3 5 3 9.5 3 16 7 24 10 24c1.5 0 2-1 4-1s2.5 1 4 1c3 0 7-8 7-14.5C25 5 22 2 18.5 2z"/>
        <path d="M14 3V1"/>
      </svg>
    ),
    href: '#download-ios',
  },
  {
    id: 'windows',
    name: 'Windows',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <rect x="2" y="2" width="12" height="12" rx="1"/>
        <rect x="16" y="2" width="10" height="12" rx="1"/>
        <rect x="2" y="16" width="12" height="10" rx="1"/>
        <rect x="16" y="16" width="10" height="10" rx="1"/>
      </svg>
    ),
    href: '#download-windows',
  },
  {
    id: 'mac',
    name: 'Mac',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <rect x="4" y="4" width="20" height="16" rx="2"/>
        <line x1="2" y1="24" x2="26" y2="24"/>
        <line x1="10" y1="20" x2="18" y2="20"/>
        <line x1="14" y1="20" x2="14" y2="24"/>
      </svg>
    ),
    href: '#download-mac',
  },
  {
    id: 'linux',
    name: 'Linux',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor" strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <path d="M14 2c-5.5 0-8 4-8 9v2c0 1.5-.5 3-1.5 4.5C3.5 19 3 20 3 21c0 1.5 2 3 5.5 3 1.5 0 3-.5 4-1.5.5.5 1 .5 1.5.5s1 0 1.5-.5c1 1 2.5 1.5 4 1.5C23 24 25 22.5 25 21c0-1-0.5-2-1.5-3.5C22.5 16 22 14.5 22 13v-2c0-5-2.5-9-8-9z"/>
        <circle cx="10.5" cy="12" r="1"/>
        <circle cx="17.5" cy="12" r="1"/>
      </svg>
    ),
    href: '#download-linux',
  },
]

export default function DownloadSection() {
  const sectionRef = useRef()
  useReveal(sectionRef)

  return (
    <section
      ref={sectionRef}
      id="descarga"
      className="nx-section"
      style={{
        background: 'var(--nx-deep)',
        paddingTop: '7rem',
        paddingBottom: '7rem',
        paddingLeft: 'var(--nx-section-px)',
        paddingRight: 'var(--nx-section-px)',
      }}
    >
      <div style={{ maxWidth: '1280px', margin: '0 auto' }}>
        {/* Header — centered */}
        <div style={{ textAlign: 'center', marginBottom: '4rem' }}>
          <div className="nx-eyebrow nx-reveal" style={{ justifyContent: 'center', display: 'flex' }}>
            La aplicación
          </div>
          <h2
            className="nx-h2 nx-reveal nx-reveal-delay-1"
            style={{ marginBottom: '1rem' }}
          >
            Tu panel de control institucional.
          </h2>
          <p
            className="nx-body nx-reveal nx-reveal-delay-2"
            style={{ maxWidth: '480px', margin: '0 auto 2rem' }}
          >
            Disponible para Android, iOS, Windows, Mac y Linux.
            La misma información, en tiempo real, donde estés.
          </p>
        </div>

        {/* App mockup placeholder */}
        <div
          className="nx-reveal nx-reveal-delay-3"
          style={{
            background: 'var(--nx-surface)',
            border: '1px solid var(--nx-border)',
            borderRadius: '1.5rem',
            padding: '2.5rem',
            marginBottom: '3rem',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            minHeight: '240px',
            position: 'relative',
            overflow: 'hidden',
          }}
        >
          {/* Simulated app UI */}
          <div style={{ width: '100%', maxWidth: '800px', display: 'grid', gridTemplateColumns: '220px 1fr', gap: '1.5rem' }}>
            {/* Sidebar */}
            <div style={{
              background: 'var(--nx-void)',
              borderRadius: '1rem',
              padding: '1.25rem',
              border: '1px solid var(--nx-border)',
            }}>
              <div style={{ fontSize: '0.65rem', fontWeight: 700, color: 'var(--nx-blue)', letterSpacing: '0.1em', textTransform: 'uppercase', marginBottom: '1rem' }}>NEXO</div>
              {['Dashboard', 'Asistencia', 'Alertas', 'Reportes', 'Configuración'].map((item, i) => (
                <div key={item} style={{
                  fontSize: '0.78rem',
                  color: i === 0 ? 'var(--nx-white)' : 'var(--nx-muted)',
                  padding: '0.5rem 0.75rem',
                  borderRadius: '0.5rem',
                  background: i === 0 ? 'rgba(10,132,255,0.1)' : 'transparent',
                  marginBottom: '0.25rem',
                  fontWeight: i === 0 ? 600 : 400,
                }}>
                  {item}
                </div>
              ))}
            </div>
            {/* Main panel */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: '0.75rem' }}>
                {[{ n: '847', l: 'Estudiantes activos' }, { n: '12', l: 'Instituciones' }, { n: '0', l: 'Alertas críticas' }].map(({ n, l }) => (
                  <div key={l} style={{
                    background: 'var(--nx-void)',
                    border: '1px solid var(--nx-border)',
                    borderRadius: '0.75rem',
                    padding: '1rem',
                  }}>
                    <div style={{ fontSize: '1.4rem', fontWeight: 800, color: 'var(--nx-white)' }}>{n}</div>
                    <div style={{ fontSize: '0.7rem', color: 'var(--nx-muted)', marginTop: '0.2rem' }}>{l}</div>
                  </div>
                ))}
              </div>
              <div style={{
                background: 'var(--nx-void)',
                border: '1px solid var(--nx-border)',
                borderRadius: '0.75rem',
                padding: '1rem',
                flex: 1,
              }}>
                <div style={{ fontSize: '0.7rem', fontWeight: 600, color: 'var(--nx-muted)', marginBottom: '0.75rem', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
                  Actividad en tiempo real
                </div>
                {['Grado 10° — Matemáticas — 100% asistencia', 'Grado 9° — Español — 2 ausencias detectadas', 'Grado 11° — Física — Alerta: 1 salida no autorizada'].map((row, i) => (
                  <div key={row} style={{
                    fontSize: '0.75rem',
                    color: i === 2 ? 'var(--nx-blue)' : 'var(--nx-muted)',
                    padding: '0.4rem 0',
                    borderBottom: i < 2 ? '1px solid var(--nx-border)' : 'none',
                  }}>
                    {row}
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>

        {/* Platform download buttons */}
        <div className="nx-reveal nx-reveal-delay-4" style={{ display: 'flex', justifyContent: 'center' }}>
          <div className="nx-platform-grid">
            {PLATFORMS.map(({ id, name, icon, href }) => (
              <a key={id} href={href} className="nx-platform-card" id={`download-btn-${id}`} aria-label={`Descargar para ${name}`}>
                <div style={{ color: 'var(--nx-blue)' }}>{icon}</div>
                <span className="nx-platform-card__name">{name}</span>
              </a>
            ))}
          </div>
        </div>

        {/* Micro-copy */}
        <p
          className="nx-micro nx-reveal nx-reveal-delay-5"
          style={{ textAlign: 'center', marginTop: '1.75rem' }}
        >
          Descarga gratuita para instituciones vinculadas · El acceso completo se activa cuando tu institución implementa NEXO.
        </p>
      </div>
    </section>
  )
}
