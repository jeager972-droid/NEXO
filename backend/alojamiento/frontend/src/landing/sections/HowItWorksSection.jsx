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
    body: 'Salidas frecuentes al baño, llegadas tarde recurrentes y evasiones entre clases. NEXO cruza la información y genera alertas antes de que el problema escale.',
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
      gsap.set(stepsRef.current, { opacity: 0, y: 20 })

      const mobileFill = document.querySelector('.nx-timeline__mobile-fill')
      const mobileTrack = document.querySelector('.nx-timeline__mobile-track')

      const ctxMobile = gsap.context(() => {
        // Animate vertical fill based on scroll within the section
        if (mobileFill && wrapper) {
          gsap.fromTo(mobileFill, { scaleY: 0 }, {
            scaleY: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: wrapper,
              start: 'top 60%',
              end: 'bottom 80%',
              scrub: 0.5,
            }
          })
        }

        // Reveal each step on scroll
        stepsRef.current.forEach((el, i) => {
          if (!el) return
          gsap.to(el, {
            opacity: 1,
            y: 0,
            duration: 0.5,
            ease: 'power2.out',
            scrollTrigger: {
              trigger: el,
              start: 'top 85%',
              toggleActions: 'play none none none',
            }
          })
          // Activate node color when visible
          ScrollTrigger.create({
            trigger: el,
            start: 'top 80%',
            toggleActions: 'play none none none',
            onEnter: () => {
              const disc = el.querySelector('.nx-timeline__node-disc')
              if (disc) {
                gsap.to(disc, {
                  borderColor: 'var(--nx-green)',
                  color: 'var(--nx-green)',
                  backgroundColor: '#e8f5e9',
                  boxShadow: '0 0 20px rgba(45, 110, 48, 0.3)',
                  duration: 0.4,
                })
              }
            }
          })
        })
      })

      return () => ctxMobile.revert()
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

      // Bug 4: Línea SVG con scrub rápido
      gsap.to(line, {
        strokeDashoffset: 0,
        ease: 'none',
        scrollTrigger: {
          trigger: wrapper,
          start: 'top top',
          end: 'bottom bottom',
          scrub: 0.3,
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
            gsap.to(stepsRef.current[i].querySelector('.nx-timeline__node-disc'), {
              borderColor: 'var(--nx-green)',
              color: 'var(--nx-green)',
              backgroundColor: '#e8f5e9',
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
      style={{ height: '280vh' }}
    >
      <section
        ref={innerRef}
        className="section-inner"
        style={{
          background: 'linear-gradient(180deg, var(--nx-deep) 0%, var(--nx-surface) 100%)',
          paddingLeft: 'var(--nx-section-px)',
          paddingRight: 'var(--nx-section-px)',
          display: 'flex',
          alignItems: 'center',
        }}
      >
        <div style={{ maxWidth: '1280px', margin: '0 auto', width: '100%' }}>
          {/* Header */}
          <div ref={eyebrowRef} className="nx-eyebrow" style={{ opacity: 0 }}>El sistema</div>
          <h3
            ref={titleRef}
            className="nx-h2"
            style={{ maxWidth: '700px', marginBottom: '1rem', opacity: 0 }}
          >
            Así opera NEXO.
          </h3>
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
              className="nx-timeline__rail"
              style={{
                position: 'absolute',
                top: '1.25rem',
                left: 'calc(1.25rem + 20px)',
                right: 'calc(1.25rem + 20px)',
                height: '2px',
                background: 'var(--nx-border)',
                zIndex: 0,
              }}
              aria-hidden="true"
            />

            {/* SVG de la línea conectora con drawSVG (vía strokeDashoffset/dasharray) */}
            <svg
              className="nx-timeline__svg"
              style={{
                position: 'absolute',
                top: '1.25rem',
                left: 'calc(1.25rem + 20px)',
                right: 'calc(1.25rem + 20px)',
                width: 'calc(100% - 2.5rem - 40px)',
                height: '2px',
                pointerEvents: 'none',
                zIndex: 0,
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

            {/* Mobile vertical track */}
            <div className="nx-timeline__mobile-track" aria-hidden="true">
              <div className="nx-timeline__mobile-fill" />
            </div>

            {/* Steps */}
            {STEPS.map(({ num, title, body }, i) => (
              <div
                key={num}
                ref={el => stepsRef.current[i] = el}
                className="nx-timeline__step"
                data-step={i}
                style={{ position: 'relative', zIndex: 2 }}
              >
                <div className="nx-timeline__node">
                  <div className="nx-timeline__node-disc">{num}</div>
                </div>
                <div style={{ flex: 1, display: 'flex', flexDirection: 'column' }}>
                  <div className="nx-timeline__title">{title}</div>
                  <p className="nx-timeline__body">{body}</p>
                </div>
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
