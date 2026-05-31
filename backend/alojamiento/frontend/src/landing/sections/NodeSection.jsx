import { useRef, useState, useEffect } from 'react'
import { useReveal } from '../components/useReveal'
import { useStickyScroll } from '../components/useStickyScroll'
import NexoCanvas from '../components/NexoCanvas'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
// gsap.registerPlugin called once globally in LandingPage.jsx

// MODULE 06 — THE NODE
// Left: large interactive 3D model with hotspot overlay
// Right: spec list synced to hotspot clicks
// CAMBIO 6: Sticky scroll, zoom del modelo (1.12 -> 1.0) y hotspots stagger (0.18s)

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
    label: 'Encriptado de extremo a extremo',
    meaning: 'Los datos biométricos viajan y se almacenan con encriptación completa en cada capa del sistema.',
    hotspotPos: { top: '30%', right: '18%' },
  },
]

function Hotspot({ spec, isActive, onClick, isMobile }) {
  const handlePointerUp = (e) => {
    e.stopPropagation()
    onClick(spec.id)
  }

  return (
    <div
      className="nx-hotspot"
      style={{ position: 'absolute', ...spec.hotspotPos, zIndex: 10 }}
      onPointerUp={handlePointerUp}
      onKeyDown={e => e.key === 'Enter' && onClick(spec.id)}
      role="button"
      tabIndex={0}
      aria-label={`Ver detalle: ${spec.label}`}
      aria-pressed={isActive}
    >
      <div className="nx-hotspot__ring" />
      <div
        className="nx-hotspot__dot"
        style={{
          width: isMobile ? '18px' : '12px',
          height: isMobile ? '18px' : '12px',
          transform: isActive ? 'scale(1.5)' : 'scale(1)',
          boxShadow: isActive ? '0 0 0 5px rgba(45, 110, 48, 0.25)' : 'none',
          transition: 'transform 0.2s var(--nx-ease), box-shadow 0.2s',
        }}
      />
    </div>
  )
}

function SpecPanel({ active, specs, onClose }) {
  const activeSpec = specs.find(s => s.id === active)

  return (
    <div
      className="nx-node-panel nx-reveal nx-reveal-delay-4"
      style={{
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        minHeight: '200px',
      }}
    >
      {activeSpec ? (
        <div
          key={active}
          style={{ animation: 'panelIn 0.2s var(--nx-ease)' }}
        >
          <div style={{
            fontSize: '0.65rem', fontWeight: 700, color: 'var(--nx-green)',
            textTransform: 'uppercase', letterSpacing: '0.12em', marginBottom: '1rem',
          }}>
            Especificación
          </div>
          <h3 style={{
            fontSize: '1.25rem', fontWeight: 800, color: 'var(--nx-white)',
            lineHeight: 1.2, marginBottom: '1rem',
          }}>
            {activeSpec.label}
          </h3>
          <p style={{ fontSize: '0.9rem', color: 'var(--nx-muted)', lineHeight: 1.65 }}>
            {activeSpec.meaning}
          </p>
          <button
            type="button"
            onPointerUp={onClose}
            style={{
              marginTop: '1.5rem', background: 'none', border: '1px solid var(--nx-border)',
              borderRadius: '100px', padding: '0.4rem 1rem', fontSize: '0.75rem',
              color: 'var(--nx-muted)', cursor: 'pointer', fontFamily: 'var(--nx-font)',
              transition: 'border-color 0.2s, color 0.2s', alignSelf: 'flex-start',
            }}
            aria-label="Cerrar panel"
          >
            Cerrar ×
          </button>
        </div>
      ) : (
        <div style={{ opacity: 0.45 }}>
          <div style={{
            width: '40px', height: '1px', background: 'var(--nx-border)', marginBottom: '1.25rem',
          }} />
          <p style={{ fontSize: '0.8rem', color: 'var(--nx-muted)', lineHeight: 1.65 }}>
            Toca un punto parpadeante para explorar las especificaciones del nodo.
          </p>
        </div>
      )}
    </div>
  )
}

export default function NodeSection() {
  const wrapperRef = useRef()
  const innerRef = useRef()
  const [active, setActive] = useState(null)
  const [canvasScale, setCanvasScale] = useState(1.12)
  const [isMobile, setIsMobile] = useState(false)
  useReveal(innerRef)

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth <= 768)
    check()
    window.addEventListener('resize', check)
    return () => window.removeEventListener('resize', check)
  }, [])

  // Aplicar arquitectura sticky scroll
  useStickyScroll(wrapperRef, innerRef)

  useEffect(() => {
    const wrapper = wrapperRef.current
    const inner = innerRef.current
    if (!wrapper || !inner) return

    const isMobile = window.innerWidth <= 768
    if (isMobile) {
      setCanvasScale(1.0)  // Slightly smaller on mobile to leave room for hotspots
      return
    }

    // Ocultar hotspots al inicio
    const hotspots = inner.querySelectorAll('.nx-hotspot')
    gsap.set(hotspots, { opacity: 0, scale: 0 })

    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: wrapper,
        start: 'top 75%', // Bug 4: content trigger at 75%
        toggleActions: 'play none none none',
      }
    })

    // PASO 4: Modelo empieza con scale:1.12 y llega a 1.0 en 1.4s con power2.out
    const scaleObj = { val: 1.12 }
    tl.to(scaleObj, {
      val: 1.0,
      duration: 1.4,
      ease: 'power2.out',
      onUpdate: () => {
        setCanvasScale(scaleObj.val)
      }
    })

    // PASO 4: Los hotspots aparecen con stagger 0.18s después de que el nodo termine su entrada
    tl.to(hotspots, {
      opacity: 1,
      scale: 1,
      duration: 0.5,
      stagger: 0.18,
      ease: 'back.out(1.7)',
    }, '-=0.1') // Empieza justo al final de la escala

    return () => tl.kill()
  }, [])

  const toggle = (id) => setActive(prev => prev === id ? null : id)

  return (
    <div ref={wrapperRef} className="section-wrapper section-wrapper--tall" id="el-nodo">
      <section
        ref={innerRef}
        className="section-inner"
        style={{
          background: 'linear-gradient(180deg, var(--nx-surface) 0%, var(--nx-deep) 100%)',
          overflow:   isMobile ? 'visible' : 'hidden',
          paddingLeft: 'var(--nx-section-px)',
          paddingRight: 'var(--nx-section-px)',
          display: 'flex',
          alignItems: 'center',
        }}
      >
        <div style={{ maxWidth: '1280px', margin: '0 auto', width: '100%' }}>
          {/* Header */}
          <div className="nx-eyebrow nx-reveal">El hardware</div>
          <h3 className="nx-h2 nx-reveal nx-reveal-delay-1" style={{ maxWidth: '680px', marginBottom: '1rem' }}>
            Construido para durar en las condiciones reales de una institución educativa colombiana.
          </h3>
          <p className="nx-body nx-reveal nx-reveal-delay-2" style={{ maxWidth: '520px', marginBottom: '4rem' }}>
            No diseñado en un laboratorio ideal. Diseñado para cortes de luz, para humedad,
            para el uso diario de cientos de estudiantes — y para seguir funcionando.
          </p>

          {/* Grid: 3D canvas left, spec list right */}
          <div
            className="nx-node-grid"
            style={{
              display: 'grid',
              gridTemplateColumns: '1fr 1fr',
              gap: '4rem',
              alignItems: 'center',
            }}
          >
            {/* ── LEFT: 3D model with hotspot overlay ── */}
            <div
              className="nx-node-canvas-wrap nx-reveal nx-reveal-delay-3"
              style={{ position: 'relative', height: '520px' }}
            >
              {/* Live 3D canvas */}
              <div style={{ width: '100%', height: '100%', borderRadius: isMobile ? '0' : '1.25rem', overflow: isMobile ? 'visible' : 'hidden' }}>
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
                  isMobile={isMobile}
                />
              ))}

              {/* Cursor hint — desktop only */}
              <div
                aria-hidden="true"
                className="nx-cursor-hint-desktop"
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

            {/* ── RIGHT: Dynamic spec panel (PC: beside canvas, Mobile: below) ── */}
            <SpecPanel active={active} specs={SPECS} onClose={() => setActive(null)} />
          </div>
        </div>
      </section>

      <style>{`
        @keyframes panelIn {
          from { opacity: 0; transform: translateY(8px); }
          to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
          #el-nodo .nx-node-grid {
            grid-template-columns: 1fr !important;
            gap: 1.5rem !important;
          }
          #el-nodo .nx-node-canvas-wrap {
            height: 360px !important;
            border-radius: 1rem !important;
            overflow: visible !important;
            margin-bottom: 0 !important;
          }
          #el-nodo .nx-hotspot {
            display: block !important;
            width: 28px !important;
            height: 28px !important;
            touch-action: manipulation;
          }
          #el-nodo .nx-hotspot__ring {
            width: 18px !important;
            height: 18px !important;
          }
          #el-nodo .nx-cursor-hint-desktop { display: none !important; }
          #el-nodo .nx-node-panel {
            padding: 1.25rem !important;
            background: var(--nx-surface) !important;
            border: 1px solid var(--nx-border) !important;
            border-radius: 1rem !important;
            min-height: auto !important;
          }
        }
      `}</style>
    </div>
  )
}
