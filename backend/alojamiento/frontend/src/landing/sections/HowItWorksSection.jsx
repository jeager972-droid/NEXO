import { useRef, useEffect } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { useReveal } from '../components/useReveal'
import { useStickyScroll } from '../components/useStickyScroll'
// gsap.registerPlugin called once globally in LandingPage.jsx

// MODULE 04 — HOW IT WORKS
// Psychological trigger: cognitive clarity — the user feels they already know how to use it
// CAMBIO 6: Sticky scroll, SVG de línea dibujado con scrub:1, y pasos que entran con toggleActions

const STEPS = [
  {
    num: '01',
    title: 'El estudiante llega',
    body: 'Coloca su huella en el nodo al entrar al salón. El registro ocurre al instante.',
  },
  {
    num: '02',
    title: 'El sistema protege la trazabilidad',
    body: 'Si un estudiante no registró su ingreso al inicio de la jornada, el acudiente recibe una notificación automática vía WhatsApp. Si registró ingreso pero no aparece en una clase posterior, coordinación recibe una alerta inmediata para actuar antes de que la situación escale.',
  },
  {
    num: '03',
    title: 'La institución tiene visibilidad completa',
    body: 'Coordinadores y rectores tienen a su disposición un panel en tiempo real con la información de la institución. Los profesores tienen al alcance de un botón su operación diaria: comunicación, registros, citaciones y más.',
  },
  {
    num: '04',
    title: 'Los patrones emergen solos',
    body: 'Salidas frecuentes al baño, llegadas tarde recurrentes, evasiones entre clases — NEXO cruza la información y genera alertas antes de que el problema escale.',
  },
]

export default function HowItWorksSection() {
  const wrapperRef = useRef()
  const innerRef = useRef()
  const lineRef = useRef()
  const stepsRef = useRef([])
  const eyebrowRef = useRef()
  const titleRef = useRef()
  const subtitleRef = useRef()
  const microRef = useRef()

  useReveal(innerRef)

  // Aplicar arquitectura sticky scroll
  useStickyScroll(wrapperRef, innerRef)

  useEffect(() => {
    const wrapper = wrapperRef.current
    const line = lineRef.current
    if (!wrapper || !line) return

    const isMobile = window.innerWidth <= 768
    if (isMobile) {
      gsap.set([eyebrowRef.current, titleRef.current, subtitleRef.current, microRef.current], { opacity: 1, y: 0 })
      gsap.set(stepsRef.current, { opacity: 1, scale: 1 })
      stepsRef.current.forEach(el => {
        if (!el) return
        const node = el.querySelector('.nx-timeline__node')
        if (node) {
          node.style.borderColor  = 'var(--nx-green)'
          node.style.color        = 'var(--nx-green)'
          node.style.backgroundColor = 'rgba(45, 110, 48, 0.08)'
        }
      })
      return
    }

    // Ocultar pasos inicialmente
    gsap.set(stepsRef.current, { opacity: 0, scale: 0.9 })

    const ctx = gsap.context(() => {
      // Header y textos
      gsap.fromTo([eyebrowRef.current, titleRef.current, subtitleRef.current],
        { opacity: 0, y: 30 },
        {
          opacity: 1,
          y: 0,
          duration: 0.85,    // Bug 4: subtítulos 0.85s
          stagger: 0.15,     // Bug 4: stagger 0.15s
          ease: 'power3.out',
          scrollTrigger: {
            trigger: wrapper,
            start: 'top 75%', // Bug 4: content trigger at 75%
            toggleActions: 'play none none none',
          }
        }
      )

      // Bug 4: Línea SVG con scrub:1
      gsap.to(line, {
        strokeDashoffset: 0,
        ease: 'none',
        scrollTrigger: {
          trigger: wrapper,
          start: 'top top',
          end: 'bottom bottom',
          scrub: 1,   // Bug 4: line-draw scrub = 1
        }
      })

      // PASO 4: Cada paso aparece con toggleActions cuando el scroll llega a su respectivo umbral
      const thresholds = [0, 45, 90, 135]

      STEPS.forEach((_, i) => {
        ScrollTrigger.create({
          trigger: wrapper,
          start: `top -${thresholds[i]}%`,
          toggleActions: 'play none none none',
          onEnter: () => {
            gsap.to(stepsRef.current[i], {
              opacity: 1,
              scale: 1,
              duration: 0.6,
              ease: 'power3.out',
            })
            gsap.to(stepsRef.current[i].querySelector('.nx-timeline__node'), {
              borderColor: 'var(--nx-green)',
              color: 'var(--nx-green)',
              backgroundColor: 'rgba(45, 110, 48, 0.08)',
              boxShadow: '0 0 20px rgba(45, 110, 48, 0.3)',
              duration: 0.4,
            })
          }
        })
      })

      // Microcopy
      gsap.fromTo(microRef.current,
        { opacity: 0, y: 15 },
        {
          opacity: 1,
          y: 0,
          duration: 0.6,
          ease: 'power3.out',
          scrollTrigger: {
            trigger: wrapper,
            start: 'top -155%',
            toggleActions: 'play none none none',
          }
        }
      )
    })

    return () => ctx.revert()
  }, [])

  return (
    <div
      ref={wrapperRef}
      className="section-wrapper section-wrapper--tall"
      id="como-funciona"
      style={{ height: '380vh' }}
    >
      <section
        ref={innerRef}
        className="section-inner"
        style={{
          background: 'var(--nx-deep)',
          paddingLeft: 'var(--nx-section-px)',
          paddingRight: 'var(--nx-section-px)',
          display: 'flex',
          alignItems: 'center',
        }}
      >
        <div style={{ maxWidth: '1280px', margin: '0 auto', width: '100%' }}>
          {/* Header */}
          <div ref={eyebrowRef} className="nx-eyebrow" style={{ opacity: 0 }}>El sistema</div>
          <h2
            ref={titleRef}
            className="nx-h2"
            style={{ maxWidth: '700px', marginBottom: '1rem', opacity: 0 }}
          >
            Así opera NEXO.
          </h2>
          <p
            ref={subtitleRef}
            className="nx-body"
            style={{ maxWidth: '520px', marginBottom: '5rem', opacity: 0 }}
          >
            Sin capacitaciones de semanas. Sin cambios de hábito forzados.
          </p>

          {/* Timeline */}
          <div className="nx-timeline" style={{ position: 'relative' }}>
            {/* Background line (rail) */}
            <div
              style={{
                position: 'absolute',
                top: '1.25rem',
                left: 'calc(1.25rem + 20px)',
                right: 'calc(1.25rem + 20px)',
                height: '2px',
                background: 'var(--nx-border)',
              }}
              aria-hidden="true"
            />

            {/* SVG de la línea conectora con drawSVG (vía strokeDashoffset/dasharray) */}
            <svg
              style={{
                position: 'absolute',
                top: '1.25rem',
                left: 'calc(1.25rem + 20px)',
                right: 'calc(1.25rem + 20px)',
                width: 'calc(100% - 2.5rem - 40px)',
                height: '2px',
                pointerEvents: 'none',
                zIndex: 1,
              }}
              aria-hidden="true"
            >
              <line
                ref={lineRef}
                x1="0"
                y1="1"
                x2="100%"
                y2="1"
                stroke="var(--nx-green)"
                strokeWidth="2"
                strokeDasharray="1200"
                strokeDashoffset="1200"
                style={{
                  filter: 'drop-shadow(0 0 4px rgba(45, 110, 48, 0.6))',
                }}
              />
            </svg>

            {/* Steps */}
            {STEPS.map(({ num, title, body }, i) => (
              <div
                key={num}
                ref={el => stepsRef.current[i] = el}
                className="nx-timeline__step"
                style={{ position: 'relative', zIndex: 2 }}
              >
                <div className="nx-timeline__node">{num}</div>
                <div className="nx-timeline__title">{title}</div>
                <p className="nx-timeline__body">{body}</p>
              </div>
            ))}
          </div>

          {/* Micro-copy */}
          <p
            ref={microRef}
            className="nx-micro"
            style={{
              textAlign: 'center',
              marginTop: '4rem',
              paddingTop: '2.5rem',
              borderTop: '1px solid var(--nx-border)',
              opacity: 0,
            }}
          >
            Todo esto ocurre sin internet · Con batería de respaldo de 12 horas · Con conectividad M2M independiente
          </p>
        </div>
      </section>

      <style>{`
        @media (max-width: 768px) {
          #como-funciona .section-inner {
            min-height: auto !important;
            padding-bottom: 3rem !important;
          }
          #como-funciona h2 {
            margin-bottom: 2.5rem !important;
          }
          #como-funciona .nx-micro {
            text-align: left !important;
            padding-left: 0 !important;
          }
        }
      `}</style>
    </div>
  )
}
