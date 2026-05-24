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
    body: 'Coloca su huella en el nodo al entrar al salón. El registro ocurre en menos de un segundo. El profesor ya está enseñando.',
  },
  {
    num: '02',
    title: 'El sistema detecta la ausencia',
    body: 'Si un estudiante no registró presencia, NEXO lo identifica automáticamente y notifica al acudiente vía WhatsApp — sin intervención humana, sin formularios, sin demoras.',
  },
  {
    num: '03',
    title: 'La institución tiene visibilidad completa',
    body: 'Coordinadores y rectores acceden en tiempo real a un panel donde cada movimiento dentro de la institución queda registrado, auditado y descargable.',
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
      STEPS.forEach((_, i) => {
        const threshold = i * 25 // 0%, 25%, 50%, 75%
        ScrollTrigger.create({
          trigger: wrapper,
          start: `top -${threshold}%`,
          toggleActions: 'play none none none',
          onEnter: () => {
            gsap.to(stepsRef.current[i], {
              opacity: 1,
              scale: 1,
              duration: 0.6,
              ease: 'power3.out',
            })
            gsap.to(stepsRef.current[i].querySelector('.nx-timeline__node'), {
              borderColor: 'var(--nx-blue)',
              color: 'var(--nx-blue)',
              backgroundColor: 'rgba(10,132,255,0.08)',
              boxShadow: '0 0 20px rgba(10,132,255,0.3)',
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
            start: 'bottom 90%',
            toggleActions: 'play none none none',
          }
        }
      )
    })

    return () => ctx.revert()
  }, [])

  return (
    <div ref={wrapperRef} className="section-wrapper section-wrapper--tall" id="como-funciona">
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
                stroke="var(--nx-blue)"
                strokeWidth="2"
                strokeDasharray="1200"
                strokeDashoffset="1200"
                style={{
                  filter: 'drop-shadow(0 0 4px rgba(10,132,255,0.6))',
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
    </div>
  )
}
