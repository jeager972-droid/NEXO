import { useRef, useEffect, useState } from 'react'
import { useReveal } from '../components/useReveal'
import { useStickyScroll } from '../components/useStickyScroll'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
// gsap.registerPlugin called once globally in LandingPage.jsx

// MODULE 02 — CREDIBILITY BAR
// Psychological trigger: social proof + risk reduction

const METRICS = [
  { num: 847,      suffix: '',   label: 'Estudiantes monitoreados' },
  { num: 12,       suffix: '',   label: 'Instituciones activas' },
  { num: 0,        suffix: '',   label: 'Fugas de datos registradas' },
  { num: 30,       suffix: 'min/día', label: 'Devueltos por docente' },
  { num: 98.7,     suffix: '%',  label: 'Uptime registrado' },
]

function AnimatedNumber({ target, suffix, active }) {
  const [val, setVal] = useState(0)
  const started = useRef(false)

  useEffect(() => {
    if (!active || started.current) return
    started.current = true

    const isFloat = !Number.isInteger(target)
    const duration = 1400
    const start = performance.now()

    function tick(now) {
      const elapsed = Math.min((now - start) / duration, 1)
      const ease = 1 - Math.pow(1 - elapsed, 3) // ease-out-cubic
      const current = target * ease
      setVal(isFloat ? current.toFixed(1) : Math.floor(current))
      if (elapsed < 1) requestAnimationFrame(tick)
      else setVal(isFloat ? target.toFixed(1) : target)
    }
    requestAnimationFrame(tick)
  }, [active, target])

  return <>{val}{suffix && <span style={{ fontSize: '0.55em', marginLeft: '0.15em', color: 'var(--nx-muted)' }}>{suffix}</span>}</>
}

export default function CredibilityBar() {
  const wrapperRef = useRef()
  const innerRef = useRef()
  const [active, setActive] = useState(false)
  useReveal(innerRef)

  // Aplicar arquitectura sticky scroll
  useStickyScroll(wrapperRef, innerRef)

  useEffect(() => {
    const trigger = ScrollTrigger.create({
      trigger: wrapperRef.current,
      start: 'top 75%', // Bug 4: content trigger at 75%
      onEnter: () => setActive(true),
      once: true
    })
    return () => trigger.kill()
  }, [])

  return (
    <div ref={wrapperRef} className="section-wrapper" id="credibilidad">
      <section
        ref={innerRef}
        className="section-inner"
        style={{
          background: 'linear-gradient(180deg, var(--nx-surface-2) 0%, var(--nx-deep) 100%)',
          borderTop: '1px solid var(--nx-border)',
          borderBottom: '1px solid var(--nx-border)',
          paddingLeft: 'var(--nx-section-px)',
          paddingRight: 'var(--nx-section-px)',
          display: 'flex',
          alignItems: 'center',
        }}
      >
        <div style={{ maxWidth: '1280px', margin: '0 auto', width: '100%' }}>
          {/* Anchor text */}
          <p
            className="nx-reveal nx-micro"
            style={{
              textAlign: 'center',
              marginBottom: '2.5rem',
              letterSpacing: '0.1em',
              textTransform: 'uppercase',
              fontSize: '0.72rem',
            }}
          >
            Implementado en instituciones educativas de Colombia
          </p>

          {/* Placeholder institution names */}
          <div
            className="nx-reveal nx-reveal-delay-1"
            style={{
              display: 'flex',
              justifyContent: 'center',
              gap: '3rem',
              marginBottom: '3rem',
              flexWrap: 'wrap',
            }}
          >
            {['I.E. San Carlos', 'Colegio Mayor', 'Escuela Técnica N°4', 'I.E. La Primavera'].map(name => (
              <span
                key={name}
                style={{
                  fontSize: '0.78rem',
                  fontWeight: 600,
                  color: 'var(--nx-muted-2)',
                  letterSpacing: '0.04em',
                  textTransform: 'uppercase',
                }}
              >
                {name}
              </span>
            ))}
          </div>

          {/* Divider */}
          <div className="nx-divider nx-reveal nx-reveal-delay-2" style={{ marginBottom: '3rem' }} />

          {/* Metrics */}
          <div
            className="nx-reveal nx-reveal-delay-3"
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(5, 1fr)',
              gap: '1rem',
            }}
          >
            {METRICS.map(({ num, suffix, label }) => (
              <div
                key={label}
                style={{ textAlign: 'center', padding: '0.5rem' }}
              >
                <div className="nx-metric-num">
                  <AnimatedNumber target={num} suffix={suffix} active={active} />
                </div>
                <div className="nx-metric-label">{label}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      <style>{`
        @media (max-width: 768px) {
          #credibilidad [style*="repeat(5, 1fr)"] {
            grid-template-columns: repeat(2, 1fr) !important;
          }
        }
      `}</style>
    </div>
  )
}
