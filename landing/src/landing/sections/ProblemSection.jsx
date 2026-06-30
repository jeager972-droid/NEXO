import { useRef, useEffect } from 'react'
import { useReveal } from '../components/useReveal'
import { useStickyScroll } from '../components/useStickyScroll'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
// gsap.registerPlugin called once globally in LandingPage.jsx

// MODULE 03 — DECLARACIÓN DEL PROBLEMA
// CAMBIO 2: Redacción en tercera persona generalizada. Tono diagnóstico, no acusatorio.
// CAMBIO 6: Sticky scroll, animación de título por palabras y stagger de columnas

const PROBLEMS = [
  {
    id: 'lista',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor"
        strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <rect x="4" y="3" width="20" height="22" rx="2"/>
        <line x1="9" y1="9" x2="19" y2="9"/>
        <line x1="9" y1="14" x2="19" y2="14"/>
        <line x1="9" y1="19" x2="15" y2="19"/>
      </svg>
    ),
    title: 'El registro manual de asistencia',
    body: 'En la mayoría de las instituciones educativas colombianas, el registro de asistencia consume tiempo de clase que los docentes no pueden recuperar. Tiempo el cual se acumula día tras día, y es irrecuperable. No es tiempo administrativo; es tiempo de cátedra que los estudiantes no reciben.',
  },
  {
    id: 'salida',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor"
        strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="M18 14H4M4 14l4-4M4 14l4 4"/>
        <path d="M12 5h9a2 2 0 012 2v14a2 2 0 01-2 2h-9"/>
      </svg>
    ),
    title: 'Los estudiantes que nadie ve salir',
    body: 'Entre el cambio de una clase y la siguiente, entre una salida al baño y el regreso, hay intervalos de tiempo donde las instituciones pierden trazabilidad sobre sus estudiantes. Cuando ocurre un incidente en ese margen, la responsabilidad institucional queda expuesta sin capacidad de acción.',
  },
  {
    id: 'padre',
    icon: (
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none" stroke="currentColor"
        strokeWidth="1.25" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="M20 4H8a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2z"/>
        <line x1="14" y1="10" x2="14" y2="16"/>
        <circle cx="14" cy="19" r="0.5" fill="currentColor"/>
      </svg>
    ),
    title: 'Las familias fuera del circuito',
    body: 'Las inasistencias registradas en papel o en sistemas desconectados llegan a los acudientes con retrasos de días, o simplemente no llegan. Las familias quedan por fuera del circuito de información en tiempo real, lo que genera vacíos de comunicación que complican la corresponsabilidad entre el hogar y la escuela.',
  },
]

export default function ProblemSection() {
  const wrapperRef = useRef()
  const innerRef = useRef()
  const titleRef = useRef()
  const columnsRef = useRef([])
  const closingRef = useRef()

  useReveal(innerRef)

  // Aplicar arquitectura sticky scroll
  useStickyScroll(wrapperRef, innerRef)

  useEffect(() => {
    const wrapper = wrapperRef.current
    if (!wrapper) return

    const isMobile = window.innerWidth <= 768
    if (isMobile) {
      gsap.set([columnsRef.current, closingRef.current], { opacity: 1, y: 0 })
      gsap.set(titleRef.current?.querySelectorAll('span') || [],
        { opacity: 1, y: 0 })
      return
    }

    // Descomponer el título en palabras para simular SplitText por palabras
    const titleEl = titleRef.current
    if (titleEl) {
      const words = titleEl.textContent.trim().split(/\s+/)
      titleEl.innerHTML = words
        .map(word => `<span style="display:inline-block;opacity:0;transform:translateY(40px)">${word}</span>`)
        .join('&nbsp;')
    }

    // Set columns initial hidden state
    gsap.set(columnsRef.current, { opacity: 0, y: 50 })

    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: wrapper,
        start: 'top 75%', // Bug 4: section content trigger at 75%
        toggleActions: 'play none none none',
      }
    })

    // Título: palabras con stagger 0.07s
    tl.to(titleRef.current?.querySelectorAll('span') || [], {
      opacity: 1,
      y: 0,
      duration: 0.6,
      stagger: 0.07,
      ease: 'power3.out',
    }, '-=0.25')

    // Tres columnas: stagger 0.15s, Y:50px + opacity:0 -> natural
    .to(columnsRef.current, {
      opacity: 1,
      y: 0,
      duration: 0.8,
      stagger: 0.15,
      ease: 'power3.out',
    }, '-=0.25')

    // Closing
    .fromTo(closingRef.current,
      { opacity: 0, y: 20 },
      { opacity: 1, y: 0, duration: 0.8, ease: 'power3.out' },
      '-=0.4'
    )

    return () => tl.kill()
  }, [])

  return (
    <div ref={wrapperRef} className="section-wrapper" id="el-problema">
      <section
        ref={innerRef}
        className="section-inner"
        style={{
          background: 'linear-gradient(180deg, var(--nx-void) 0%, var(--nx-deep) 100%)',
          paddingLeft: 'var(--nx-section-px)',
          paddingRight: 'var(--nx-section-px)',
          display: 'flex',
          alignItems: 'center',
        }}
      >
        <div style={{ maxWidth: '1280px', margin: '0 auto', width: '100%' }}>
          {/* Eyebrow removed */}

          {/* CAMBIO 2: Nuevo título — diagnóstico, no acusatorio */}
          <h3
            ref={titleRef}
            className="nx-h2"
            style={{ maxWidth: '700px', marginBottom: '5rem' }}
          >
            Hay vacíos que el sistema educativo colombiano tiene pendiente cubrir, los cuales afectan a quienes más merecen protección.
          </h3>

          {/* 3-column problem grid */}
          <div
            className="nx-problem-grid"
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(3, 1fr)',
              gap: '2.5rem',
            }}
          >
            {PROBLEMS.map(({ id, icon, title, body }, i) => (
              <div
                key={id}
                ref={el => columnsRef.current[i] = el}
                className="nx-problem-card nx-reveal"
                style={{
                  borderTop: '1px solid var(--nx-border)',
                  paddingTop: '2rem',
                }}
              >
                <div className="nx-icon" style={{ marginBottom: '1.5rem' }}>
                  {icon}
                </div>
                <h3 className="nx-h3" style={{ marginBottom: '0.85rem' }}>{title}</h3>
                <p className="nx-body" style={{ fontSize: '0.9rem' }}>{body}</p>
              </div>
            ))}
          </div>

          {/* Closing */}
          <div
            ref={closingRef}
            className="nx-reveal"
            style={{
              marginTop: '4.5rem',
              paddingTop: '2.5rem',
              borderTop: '1px solid var(--nx-border)',
              display: 'flex',
              justifyContent: 'center',
              opacity: 0,
            }}
          >
            <p style={{
              maxWidth: '640px',
              textAlign: 'center',
              fontSize: '1rem',
              lineHeight: 1.75,
              color: 'var(--nx-text)',
              fontStyle: 'italic',
            }}>
              NEXO no es una carga más. Es la infraestructura que cierra estos tres vacíos
              simultáneamente, en tiempo real, sin depender de la conexión a internet
              de las instituciones.
            </p>
          </div>
        </div>
      </section>

      <style>{`
        @media (max-width: 768px) {
          #el-problema .nx-problem-grid {
            grid-template-columns: 1fr !important;
            gap: 1.25rem !important;
          }
          #el-problema .nx-problem-card {
            padding: 1.5rem !important;
            border-radius: var(--nx-radius-card) !important;
          }
        }
      `}</style>
    </div>
  )
}
