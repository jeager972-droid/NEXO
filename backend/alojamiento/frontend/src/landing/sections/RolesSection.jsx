import { useRef, useState, useEffect, useCallback } from 'react'
import { useReveal } from '../components/useReveal'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

gsap.registerPlugin(ScrollTrigger)

// MODULE 07 — ROLES & USE CASES
// CAMBIO 3: Bug fix — GSAP-driven tab transitions instead of CSS animation
// CAMBIO 6: Tabs de rol entran con stagger horizontal de 0.1s al entrar al viewport

const ROLES = [
  {
    id: 'rector',
    label: 'Rector',
    icon: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <circle cx="12" cy="8" r="4"/>
        <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
        <path d="M17 4l2 2-2 2"/>
      </svg>
    ),
    headline: 'La firma institucional queda protegida.',
    body: 'Los rectores acceden a la auditoría completa de su institución. Cada acción de cada rol queda registrada — incluyendo quién borró un registro y a qué hora. La trazabilidad no depende de la memoria de nadie.',
    features: [
      'Auditoría completa con marca de tiempo por acción',
      'Registro de quién eliminó o modificó datos',
      'Informes descargables listos para entes de control',
    ],
  },
  {
    id: 'coordinador',
    label: 'Coordinador',
    icon: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <rect x="2" y="3" width="20" height="14" rx="2"/>
        <line x1="8" y1="21" x2="16" y2="21"/>
        <line x1="12" y1="17" x2="12" y2="21"/>
        <line x1="6" y1="8" x2="18" y2="8"/>
        <line x1="6" y1="12" x2="13" y2="12"/>
      </svg>
    ),
    headline: 'Los problemas se detectan antes de escalar.',
    body: 'Los coordinadores ven evasiones entre clases, salidas frecuentes y llegadas tarde recurrentes — todo en un panel en tiempo real, con alertas automáticas configurables por umbral antes de que cualquier situación se convierta en incidente.',
    features: [
      'Panel de patrones y anomalías en tiempo real',
      'Alertas automáticas configurables por umbral',
      'Historial completo por estudiante exportable',
    ],
  },
  {
    id: 'profesor',
    label: 'Profesor',
    icon: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
        <path d="M2 17l10 5 10-5"/>
        <path d="M2 12l10 5 10-5"/>
      </svg>
    ),
    headline: 'La carga administrativa de los docentes se reduce a cero.',
    body: 'El registro de asistencia ocurre automáticamente. Los docentes pueden citar acudientes con un botón, reportar daños o incidentes desde su teléfono, y dedicar el tiempo de clase exclusivamente a enseñar.',
    features: [
      'Asistencia automática — sin intervención manual',
      'Citar acudientes desde el móvil en un toque',
      'Reportes de incidentes y daños desde la app',
    ],
  },
  {
    id: 'secretaria',
    label: 'Secretaría de Educación',
    icon: (
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round">
        <path d="M3 3h18a1 1 0 011 1v14a1 1 0 01-1 1H3a1 1 0 01-1-1V4a1 1 0 011-1z"/>
        <polyline points="17 3 17 10 14 8 11 10 11 3"/>
      </svg>
    ),
    headline: 'Trazabilidad a escala municipal o departamental.',
    body: 'Las secretarías de educación implementan custodia estudiantil en todas las instituciones de su jurisdicción. Acceden a reportes consolidados por sede, y justifican la inversión pública con datos reales descargables para rendición de cuentas.',
    features: [
      'Reportes consolidados por institución y sede',
      'Panel de supervisión multi-institución',
      'Exportación de datos para rendición de cuentas',
    ],
  },
]

export default function RolesSection() {
  const sectionRef   = useRef()
  const tabsContainerRef = useRef()
  const panelRef     = useRef()
  const [activeRole, setActiveRole] = useState(0)
  const currentRole  = useRef(0)
  const isAnimating  = useRef(false)
  useReveal(sectionRef)

  // CAMBIO 6: Stagger de entrada horizontal de los tabs al entrar en viewport
  useEffect(() => {
    const container = tabsContainerRef.current
    if (!container) return

    const tabs = container.querySelectorAll('.nx-tab')
    gsap.set(tabs, { opacity: 0, y: 15 })

    const trigger = ScrollTrigger.create({
      trigger: container,
      start: 'top 80%',
      once: true,
      onEnter: () => {
        gsap.to(tabs, {
          opacity: 1,
          y: 0,
          duration: 0.6,
          stagger: 0.1,
          ease: 'power3.out',
        })
      }
    })

    return () => trigger.kill()
  }, [])

  // CAMBIO 3: Transición GSAP (fade out 150ms -> fade in 250ms)
  const switchRole = useCallback((idx) => {
    if (idx === currentRole.current || isAnimating.current) return
    isAnimating.current = true

    gsap.to(panelRef.current, {
      opacity: 0,
      y: 8,
      duration: 0.15,
      ease: 'power2.in',
      onComplete: () => {
        setActiveRole(idx)
        currentRole.current = idx
      },
    })
  }, [])

  useEffect(() => {
    if (!panelRef.current) return
    gsap.fromTo(
      panelRef.current,
      { opacity: 0, y: 12 },
      {
        opacity: 1,
        y: 0,
        duration: 0.28,
        ease: 'power3.out',
        onComplete: () => { isAnimating.current = false },
      }
    )
  }, [activeRole])

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
        <h2 className="nx-h2 nx-reveal nx-reveal-delay-1" style={{ maxWidth: '700px', marginBottom: '1rem' }}>
          NEXO opera diferente para cada rol.
        </h2>
        <p className="nx-body nx-reveal nx-reveal-delay-2" style={{ maxWidth: '500px', marginBottom: '3.5rem' }}>
          Pero todos ven lo mismo: control total.
        </p>

        {/* Tabs con ref para el stagger horizontal de 0.1s */}
        <div
          ref={tabsContainerRef}
          className="nx-tabs"
          role="tablist"
          aria-label="Roles de usuario"
        >
          {ROLES.map((r, i) => (
            <button
              key={r.id}
              id={`tab-${r.id}`}
              className={`nx-tab${activeRole === i ? ' active' : ''}`}
              onClick={() => switchRole(i)}
              aria-selected={activeRole === i}
              aria-controls={`panel-${r.id}`}
              role="tab"
              type="button"
            >
              {r.label}
            </button>
          ))}
        </div>

        {/* Tab panel controlado por GSAP */}
        <div
          ref={panelRef}
          id={`panel-${role.id}`}
          role="tabpanel"
          aria-labelledby={`tab-${role.id}`}
          style={{
            display: 'grid',
            gridTemplateColumns: 'auto 1fr',
            gap: '3.5rem',
            alignItems: 'start',
            opacity: 1,
            willChange: 'opacity, transform',
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
            <div style={{
              fontSize: '0.7rem', fontWeight: 700, letterSpacing: '0.12em',
              textTransform: 'uppercase', color: 'var(--nx-blue)', marginBottom: '0.75rem',
            }}>
              {role.label}
            </div>

            {/* Headline */}
            <h3 style={{ fontSize: '1.4rem', fontWeight: 800, color: 'var(--nx-white)', marginBottom: '0.85rem', lineHeight: 1.2 }}>
              {role.headline}
            </h3>

            {/* Body */}
            <p className="nx-body" style={{ marginBottom: '2rem', maxWidth: '560px' }}>
              {role.body}
            </p>

            {/* Feature list */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
              {role.features.map(f => (
                <div key={f} style={{ display: 'flex', alignItems: 'center', gap: '0.85rem', fontSize: '0.875rem', color: 'var(--nx-text)' }}>
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
        @media (max-width: 640px) {
          #roles [style*="grid-template-columns: auto 1fr"] {
            grid-template-columns: 1fr !important;
          }
          .nx-tabs { overflow-x: auto; gap: 0; }
          .nx-tab  { font-size: 0.75rem; padding: 0.6rem 0.85rem; white-space: nowrap; }
        }
      `}</style>
    </section>
  )
}
