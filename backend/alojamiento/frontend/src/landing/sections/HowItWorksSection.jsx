import { useRef, useEffect } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { useReveal } from '../components/useReveal'

gsap.registerPlugin(ScrollTrigger)

// MODULE 04 — HOW IT WORKS
// Psychological trigger: cognitive clarity — the user feels they already know how to use it
// CAMBIO 6: Línea conectora que se dibuja con el scroll, pasos escalados de 0.9 a 1.0 + opacity 0

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
  const sectionRef = useRef()
  const lineRef = useRef()
  const stepsRef = useRef([])
  useReveal(sectionRef)

  useEffect(() => {
    const section = sectionRef.current
    const line = lineRef.current
    if (!section || !line) return

    // Ocultar pasos inicialmente
    gsap.set(stepsRef.current, { opacity: 0, scale: 0.9 })

    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: section,
        start: 'top 50%',
        end: 'bottom 70%',
        scrub: 0.8, // Inercia suave sincronizada con el scroll
      }
    })

    // CAMBIO 6: Dibujar progresivamente la línea conectora con el scroll
    tl.to(line, {
      width: '100%',
      ease: 'none',
      duration: 4,
    })

    // CAMBIO 6: Cada paso aparece cuando la línea llega a él
    STEPS.forEach((_, i) => {
      const startTime = i * (4 / (STEPS.length - 1 || 1))
      tl.to(stepsRef.current[i], {
        opacity: 1,
        scale: 1,
        duration: 0.5,
        ease: 'power2.out',
      }, startTime === 0 ? 0 : startTime - 0.2)
      // Activar visualmente el nodo (iluminación azul)
      .to(stepsRef.current[i].querySelector('.nx-timeline__node'), {
        borderColor: 'var(--nx-blue)',
        color: 'var(--nx-blue)',
        backgroundColor: 'rgba(10,132,255,0.08)',
        boxShadow: '0 0 20px rgba(10,132,255,0.3)',
        duration: 0.3,
      }, startTime === 0 ? 0 : startTime - 0.1)
    })

    return () => tl.kill()
  }, [])

  return (
    <section
      ref={sectionRef}
      id="como-funciona"
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
        {/* Header */}
        <div className="nx-eyebrow nx-reveal">El sistema</div>
        <h2
          className="nx-h2 nx-reveal nx-reveal-delay-1"
          style={{ maxWidth: '700px', marginBottom: '1rem' }}
        >
          Así opera NEXO.
        </h2>
        <p
          className="nx-body nx-reveal nx-reveal-delay-2"
          style={{ maxWidth: '520px', marginBottom: '5rem' }}
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
              height: '1px',
              background: 'var(--nx-border)',
            }}
            aria-hidden="true"
          />

          {/* Animated fill line */}
          <div
            ref={lineRef}
            style={{
              position: 'absolute',
              top: '1.25rem',
              left: 'calc(1.25rem + 20px)',
              height: '1px',
              width: '0%',
              background: `linear-gradient(90deg, var(--nx-blue), rgba(10,132,255,0.4))`,
              boxShadow: '0 0 10px rgba(10,132,255,0.5)',
              zIndex: 1,
            }}
            aria-hidden="true"
          />

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
          className="nx-micro nx-reveal nx-reveal-delay-4"
          style={{
            textAlign: 'center',
            marginTop: '4rem',
            paddingTop: '2.5rem',
            borderTop: '1px solid var(--nx-border)',
          }}
        >
          Todo esto ocurre sin internet · Con batería de respaldo de 12 horas · Con conectividad M2M independiente
        </p>
      </div>
    </section>
  )
}
