import { useRef, useEffect } from 'react'
import gsap from 'gsap'
import NexoCanvas from '../components/NexoCanvas'

// MODULE 01 — HERO
// Left: word-by-word headline reveal via GSAP
// Right: live 3D node, cold blue lighting, auto-rotate (no interaction)

export default function HeroSection() {
  const titleRef = useRef()

  useEffect(() => {
    const words = titleRef.current?.querySelectorAll('.hero-word')
    if (!words?.length) return
    gsap.fromTo(
      words,
      { y: 60, opacity: 0 },
      { y: 0, opacity: 1, duration: 0.85, ease: 'power3.out', stagger: 0.04, delay: 0.4 }
    )
  }, [])

  const headline =
    'Cada minuto que un estudiante desaparece del sistema, la institución asume la responsabilidad.'

  return (
    <section
      id="hero"
      className="nx-section"
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        paddingTop: '7rem',
        paddingBottom: '5rem',
        paddingLeft: 'var(--nx-section-px)',
        paddingRight: 'var(--nx-section-px)',
        background: 'var(--nx-void)',
        position: 'relative',
        overflow: 'hidden',
      }}
    >
      {/* Radial ambient glow behind node */}
      <div
        aria-hidden="true"
        style={{
          position: 'absolute',
          right: '5%',
          top: '50%',
          transform: 'translateY(-50%)',
          width: '520px',
          height: '520px',
          background: 'radial-gradient(circle, rgba(10,132,255,0.10) 0%, transparent 68%)',
          borderRadius: '50%',
          pointerEvents: 'none',
          zIndex: 0,
        }}
      />

      <div
        style={{
          position: 'relative',
          zIndex: 1,
          display: 'grid',
          gridTemplateColumns: '1fr 1fr',
          gap: '3rem',
          alignItems: 'center',
          maxWidth: '1280px',
          margin: '0 auto',
          width: '100%',
        }}
      >
        {/* ── LEFT: Copy ── */}
        <div>
          {/* Eyebrow */}
          <div
            className="nx-eyebrow"
            style={{ opacity: 0, animation: 'hFadeUp 0.6s var(--nx-ease) 0.15s forwards' }}
          >
            Sistema de Custodia Educativa en Tiempo Real — Colombia
          </div>

          {/* H1 — word reveal */}
          <h1
            ref={titleRef}
            className="nx-h1"
            style={{ marginBottom: '1.75rem', overflow: 'visible' }}
            aria-label={headline}
          >
            {headline.split(' ').map((word, i) => (
              <span
                key={i}
                className="hero-word"
                style={{
                  display: 'inline-block',
                  marginRight: '0.28em',
                  opacity: 0,
                  willChange: 'transform, opacity',
                }}
              >
                {word}
              </span>
            ))}
          </h1>

          {/* Subtitle */}
          <p
            className="nx-body"
            style={{
              maxWidth: '500px',
              marginBottom: '2.5rem',
              opacity: 0,
              animation: 'hFadeUp 0.7s var(--nx-ease) 0.95s forwards',
            }}
          >
            NEXO cierra ese vacío. Control de presencia, trazabilidad completa y comunicación
            institucional automatizada — todo en una infraestructura que opera sin internet, sin
            excusas y sin puntos de falla.
          </p>

          {/* CTAs */}
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '1.25rem',
              flexWrap: 'wrap',
              marginBottom: '1.25rem',
              opacity: 0,
              animation: 'hFadeUp 0.7s var(--nx-ease) 1.15s forwards',
            }}
          >
            <a href="#contacto" className="nx-btn-primary" id="hero-cta-demo">
              Solicitar demostración institucional
            </a>
            <a href="#como-funciona" className="nx-link-arrow" id="hero-cta-how">
              ¿Eres rector o directivo? Ve cómo funciona
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden>
                <path d="M2 7h10M8 3l4 4-4 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </a>
          </div>

          {/* Micro-copy */}
          <p
            className="nx-micro"
            style={{ opacity: 0, animation: 'hFadeUp 0.6s var(--nx-ease) 1.35s forwards' }}
          >
            Sin compromisos · Presentación adaptada al contexto de tu institución.
          </p>
        </div>

        {/* ── RIGHT: 3D Node ── */}
        <div
          aria-label="Modelo 3D del nodo NEXO"
          style={{
            height: '520px',
            borderRadius: '1.5rem',
            overflow: 'hidden',
            position: 'relative',
            opacity: 0,
            animation: 'hFadeUp 1s var(--nx-ease) 0.6s forwards',
          }}
        >
          <NexoCanvas
            type="solo"
            scale={1.1}
            coldLight={true}
            interactive={false}
          />

          {/* Hardware label */}
          <div
            aria-hidden="true"
            style={{
              position: 'absolute',
              bottom: '1.25rem',
              left: '50%',
              transform: 'translateX(-50%)',
              fontSize: '0.65rem',
              fontWeight: 600,
              letterSpacing: '0.14em',
              textTransform: 'uppercase',
              color: 'var(--nx-muted-2)',
              whiteSpace: 'nowrap',
            }}
          >
            Nodo NEXO — Hardware biométrico
          </div>
        </div>
      </div>

      {/* Scroll indicator */}
      <div
        aria-hidden="true"
        style={{
          position: 'absolute',
          bottom: '2.5rem',
          left: '50%',
          transform: 'translateX(-50%)',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          gap: '0.5rem',
          opacity: 0,
          animation: 'hFadeUp 0.6s var(--nx-ease) 1.7s forwards',
        }}
      >
        <span className="nx-micro">Desliza</span>
        <div
          style={{
            width: '1px',
            height: '48px',
            background: 'linear-gradient(to bottom, rgba(107,127,163,0.6), transparent)',
            animation: 'scrollBlink 2.2s ease-in-out infinite',
          }}
        />
      </div>

      <style>{`
        @keyframes hFadeUp {
          from { opacity: 0; transform: translateY(22px); }
          to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes scrollBlink {
          0%, 100% { opacity: 0.25; }
          50%       { opacity: 1; }
        }
        @media (max-width: 768px) {
          #hero > div > div { grid-template-columns: 1fr !important; }
          #hero [aria-label="Modelo 3D del nodo NEXO"] { height: 300px !important; }
        }
      `}</style>
    </section>
  )
}
