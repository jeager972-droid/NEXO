import { useRef, useState, useEffect } from 'react'
import { useReveal } from '../components/useReveal'
import NexoCanvas from '../components/NexoCanvas'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

gsap.registerPlugin(ScrollTrigger)

// MODULE 06 — THE NODE
// Left: large interactive 3D model with hotspot overlay
// Right: spec list synced to hotspot clicks
// CAMBIO 6: Zoom out del modelo 3D (1.15 -> 1.0) y stagger de hotspots (0.2s)

const SPECS = [
  {
    id: 'steel',
    label: 'Acero inoxidable',
    meaning: 'Resiste el uso intensivo diario de cientos de estudiantes sin degradarse.',
    hotspotPos: { top: '20%', left: '28%' },
  },
  {
    id: 'battery',
    label: 'Batería 12 horas',
    meaning: 'Opera durante cortes de luz sin interrupciones. Sin excusas.',
    hotspotPos: { top: '45%', left: '14%' },
  },
  {
    id: 'sim',
    label: 'Conectividad M2M',
    meaning: 'Tiene su propia SIM Card. No depende del WiFi de la institución.',
    hotspotPos: { top: '68%', left: '26%' },
  },
  {
    id: 'encrypt',
    label: 'Grado militar',
    meaning: 'Los datos biométricos nunca salen del nodo en formato legible.',
    hotspotPos: { top: '30%', right: '18%' },
  },
  {
    id: 'warranty',
    label: 'Garantía 5 años',
    meaning: 'Si falla, lo reemplazamos. Sin procesos. Sin costos ocultos.',
    hotspotPos: { top: '62%', right: '14%' },
  },
]

function Hotspot({ spec, isActive, onClick }) {
  return (
    <div
      className="nx-hotspot"
      style={{ position: 'absolute', ...spec.hotspotPos, zIndex: 10 }}
      onClick={() => onClick(spec.id)}
      onKeyDown={e => e.key === 'Enter' && onClick(spec.id)}
      role="button"
      tabIndex={0}
      aria-label={`Ver detalle: ${spec.label}`}
      aria-pressed={isActive}
    >
      {/* Pulse ring */}
      <div className="nx-hotspot__ring" />
      {/* Solid dot */}
      <div
        className="nx-hotspot__dot"
        style={{
          transform: isActive ? 'scale(1.4)' : 'scale(1)',
          boxShadow: isActive ? '0 0 0 4px rgba(10,132,255,0.3)' : 'none',
          transition: 'transform 0.2s var(--nx-ease), box-shadow 0.2s',
        }}
      />

      {/* Tooltip — appears above the dot */}
      {isActive && (
        <div
          role="tooltip"
          style={{
            position: 'absolute',
            bottom: 'calc(100% + 10px)',
            left: '50%',
            transform: 'translateX(-50%)',
            background: 'var(--nx-surface)',
            border: '1px solid var(--nx-border)',
            borderRadius: '0.75rem',
            padding: '0.75rem 1rem',
            width: '200px',
            pointerEvents: 'none',
            animation: 'tooltipIn 0.2s var(--nx-ease)',
          }}
        >
          <div style={{ fontSize: '0.68rem', fontWeight: 700, color: 'var(--nx-blue)', textTransform: 'uppercase', letterSpacing: '0.08em', marginBottom: '0.3rem' }}>
            {spec.label}
          </div>
          <div style={{ fontSize: '0.78rem', color: 'var(--nx-text)', lineHeight: 1.55 }}>
            {spec.meaning}
          </div>
          {/* Arrow */}
          <div style={{
            position: 'absolute', bottom: '-5px', left: '50%',
            transform: 'translateX(-50%) rotate(45deg)',
            width: '8px', height: '8px',
            background: 'var(--nx-surface)',
            borderRight: '1px solid var(--nx-border)',
            borderBottom: '1px solid var(--nx-border)',
          }} />
        </div>
      )}
    </div>
  )
}

export default function NodeSection() {
  const sectionRef = useRef()
  const [active, setActive] = useState(null)
  const [canvasScale, setCanvasScale] = useState(1.15)
  useReveal(sectionRef)

  useEffect(() => {
    const section = sectionRef.current
    if (!section) return

    // Ocultar hotspots al inicio para la animación de entrada
    const hotspots = section.querySelectorAll('.nx-hotspot')
    gsap.set(hotspots, { opacity: 0, scale: 0 })

    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: section,
        start: 'top 60%',
        once: true,
      }
    })

    // CAMBIO 6: Slow zoom out del modelo 3D al entrar en viewport
    const scaleObj = { val: 1.15 }
    tl.to(scaleObj, {
      val: 1.0,
      duration: 1.2,
      ease: 'power3.out',
      onUpdate: () => {
        setCanvasScale(scaleObj.val)
      }
    })

    // CAMBIO 6: Hotspots aparecen uno a uno con stagger 0.2s después de terminar la entrada
    tl.to(hotspots, {
      opacity: 1,
      scale: 1,
      duration: 0.5,
      stagger: 0.2,
      ease: 'back.out(1.7)',
    }, '-=0.2') // Solapado levemente para mayor fluidez

    return () => tl.kill()
  }, [])

  const toggle = (id) => setActive(prev => prev === id ? null : id)

  return (
    <section
      ref={sectionRef}
      id="el-nodo"
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
        <div className="nx-eyebrow nx-reveal">El hardware</div>
        <h2 className="nx-h2 nx-reveal nx-reveal-delay-1" style={{ maxWidth: '680px', marginBottom: '1rem' }}>
          Construido para durar en las condiciones reales de una institución educativa colombiana.
        </h2>
        <p className="nx-body nx-reveal nx-reveal-delay-2" style={{ maxWidth: '520px', marginBottom: '4rem' }}>
          No diseñado en un laboratorio ideal. Diseñado para cortes de luz, para humedad,
          para el uso diario de cientos de estudiantes — y para seguir funcionando.
        </p>

        {/* Grid: 3D canvas left, spec list right */}
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: '1fr 1fr',
            gap: '4rem',
            alignItems: 'center',
          }}
        >
          {/* ── LEFT: 3D model with hotspot overlay ── */}
          <div
            className="nx-reveal nx-reveal-delay-3"
            style={{ position: 'relative', height: '520px' }}
          >
            {/* Live 3D canvas */}
            <div style={{ width: '100%', height: '100%', borderRadius: '1.25rem', overflow: 'hidden' }}>
              <NexoCanvas
                type="solo"
                scale={canvasScale}
                coldLight={true}
                interactive={true}
              />
            </div>

            {/* Hotspot overlay — sits on top of canvas */}
            {SPECS.map(spec => (
              <Hotspot
                key={spec.id}
                spec={spec}
                isActive={active === spec.id}
                onClick={toggle}
              />
            ))}

            {/* Cursor hint */}
            <div
              aria-hidden="true"
              style={{
                position: 'absolute',
                bottom: '1rem',
                left: '50%',
                transform: 'translateX(-50%)',
                fontSize: '0.65rem',
                color: 'var(--nx-muted-2)',
                letterSpacing: '0.1em',
                textTransform: 'uppercase',
                whiteSpace: 'nowrap',
                pointerEvents: 'none',
              }}
            >
              Rota con el cursor · Toca los puntos
            </div>
          </div>

          {/* ── RIGHT: Spec list synced to hotspots ── */}
          <div className="nx-reveal nx-reveal-delay-4">
            {SPECS.map(({ id, label, meaning }, i) => (
              <div
                key={id}
                onClick={() => toggle(id)}
                role="button"
                tabIndex={0}
                onKeyDown={e => e.key === 'Enter' && toggle(id)}
                aria-pressed={active === id}
                style={{
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: '1rem',
                  padding: '1.4rem 0.75rem',
                  borderBottom: '1px solid var(--nx-border)',
                  cursor: 'pointer',
                  borderRadius: '0.5rem',
                  background: active === id ? 'rgba(10,132,255,0.05)' : 'transparent',
                  transition: 'background 0.25s',
                }}
              >
                {/* Number circle */}
                <div style={{
                  width: '28px', height: '28px', borderRadius: '50%', flexShrink: 0,
                  border: `1.5px solid ${active === id ? 'var(--nx-blue)' : 'var(--nx-border)'}`,
                  display: 'flex', alignItems: 'center', justifyContent: 'center',
                  fontSize: '0.65rem', fontWeight: 700,
                  color: active === id ? 'var(--nx-blue)' : 'var(--nx-muted)',
                  marginTop: '2px',
                  transition: 'border-color 0.25s, color 0.25s',
                }}>
                  {String(i + 1).padStart(2, '0')}
                </div>

                <div>
                  <div style={{
                    fontWeight: 700, fontSize: '0.9rem', marginBottom: '0.3rem',
                    color: active === id ? 'var(--nx-white)' : 'var(--nx-text)',
                    transition: 'color 0.25s',
                  }}>
                    {label}
                  </div>
                  <div style={{ fontSize: '0.82rem', color: 'var(--nx-muted)', lineHeight: 1.55 }}>
                    {meaning}
                  </div>
                </div>
              </div>
            ))}

            <p className="nx-micro" style={{ marginTop: '1.5rem', paddingLeft: '0.75rem' }}>
              El 70% de los costos de daño por causas naturales o ambientales son cubiertos por NEXO durante la vigencia del contrato.
            </p>
          </div>
        </div>
      </div>

      <style>{`
        @keyframes tooltipIn {
          from { opacity: 0; transform: translateX(-50%) translateY(6px); }
          to   { opacity: 1; transform: translateX(-50%) translateY(0); }
        }
        @media (max-width: 768px) {
          #el-nodo [style*="repeat(2, 1fr)"] {
            grid-template-columns: 1fr !important;
          }
          #el-nodo [style*="height: 520px"] {
            height: 320px !important;
          }
        }
      `}</style>
    </section>
  )
}
