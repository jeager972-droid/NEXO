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
  {
    id: 'warranty',
    label: 'Cobertura total o parcial ante daños',
    meaning: '',
    hotspotPos: { top: '62%', right: '14%' },
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
      onClick={handlePointerUp}
      onPointerUp={handlePointerUp}
      onKeyDown={e => e.key === 'Enter' && onClick(spec.id)}
      role="button"
      tabIndex={0}
      aria-label={`Ver detalle: ${spec.label}`}
      aria-pressed={isActive}
    >
      {/* Pulse ring */}
      <div className="nx-hotspot__ring" />
      {/* Solid dot — larger tap target on mobile */}
      <div
        className="nx-hotspot__dot"
        style={{
          width: isMobile ? '18px' : '12px',
          height: isMobile ? '18px' : '12px',
          transform: isActive ? 'scale(1.4)' : 'scale(1)',
          boxShadow: isActive ? '0 0 0 4px rgba(45, 110, 48, 0.3)' : 'none',
          transition: 'transform 0.2s var(--nx-ease), box-shadow 0.2s',
        }}
      />

      {/* Tooltip — positioned for mobile or desktop */}
      {isActive && (
        <div
          role="tooltip"
          className="nx-hotspot-tooltip"
          style={{
            position: isMobile ? 'fixed' : 'absolute',
            ...(isMobile ? {
              bottom: '1.5rem',
              left: '1rem',
              right: '1rem',
              transform: 'none',
            } : {
              bottom: 'calc(100% + 10px)',
              left: '50%',
              transform: 'translateX(-50%)',
              width: '200px',
            }),
            background: 'var(--nx-card)',
            border: '1px solid var(--nx-border)',
            borderRadius: '0.75rem',
            padding: isMobile ? '1rem 1.25rem' : '0.75rem 1rem',
            pointerEvents: isMobile ? 'auto' : 'none',
            animation: 'tooltipIn 0.2s var(--nx-ease)',
            zIndex: 100,
            fontFamily: 'var(--nx-font)',
            boxShadow: isMobile ? '0 8px 32px rgba(15, 45, 18, 0.12)' : 'none',
          }}
        >
          {/* Close button — mobile only */}
          {isMobile && (
            <button
              type="button"
              onClick={(e) => { e.stopPropagation(); onClick(spec.id); }}
              onPointerUp={(e) => { e.stopPropagation(); onClick(spec.id); }}
              aria-label="Cerrar"
              style={{
                position: 'absolute',
                top: '0.5rem',
                right: '0.5rem',
                background: 'none',
                border: 'none',
                width: '28px',
                height: '28px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                cursor: 'pointer',
                color: 'var(--nx-muted)',
                borderRadius: '50%',
              }}
            >
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
                <line x1="3" y1="3" x2="11" y2="11" /><line x1="11" y1="3" x2="3" y2="11" />
              </svg>
            </button>
          )}
          <div style={{ fontSize: '0.68rem', fontWeight: 700, color: 'var(--nx-green)', textTransform: 'uppercase', letterSpacing: '0.08em', marginBottom: '0.3rem' }}>
            {spec.label}
          </div>
          <div style={{ fontSize: isMobile ? '0.82rem' : '0.78rem', color: 'var(--nx-text)', lineHeight: 1.55 }}>
            {spec.meaning}
          </div>
          {/* Arrow — desktop only */}
          {!isMobile && (
            <div style={{
              position: 'absolute', bottom: '-5px', left: '50%',
              transform: 'translateX(-50%) rotate(45deg)',
              width: '8px', height: '8px',
              background: 'var(--nx-surface)',
              borderRight: '1px solid var(--nx-border)',
              borderBottom: '1px solid var(--nx-border)',
            }} />
          )}
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
          background: 'var(--nx-deep)',
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
          <h2 className="nx-h2 nx-reveal nx-reveal-delay-1" style={{ maxWidth: '680px', marginBottom: '1rem' }}>
            Construido para durar en las condiciones reales de una institución educativa colombiana.
          </h2>
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

            {/* ── RIGHT: Spec list synced to hotspots ── */}
            <div className="nx-node-specs nx-reveal nx-reveal-delay-4">
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
                    background: active === id ? 'rgba(45, 110, 48, 0.05)' : 'transparent',
                    transition: 'background 0.25s',
                  }}
                >
                  {/* Number circle */}
                  <div style={{
                    width: '28px', height: '28px', borderRadius: '50%', flexShrink: 0,
                    border: `1.5px solid ${active === id ? 'var(--nx-green)' : 'var(--nx-border)'}`,
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                    fontSize: '0.65rem', fontWeight: 700,
                    color: active === id ? 'var(--nx-green)' : 'var(--nx-muted)',
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
                El 70% de los costos de daño por causas naturales o ambientales son cubiertos por NEXO durante los primeros 5 años.
              </p>
            </div>
          </div>
        </div>
      </section>

      <style>{`
        .nx-hotspot__tooltip { z-index: 20; }
        @keyframes tooltipIn {
          from { opacity: 0; transform: translateY(4px); }
          to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
          #el-nodo .nx-node-grid {
            grid-template-columns: 1fr !important;
            gap: 2rem !important;
          }
          #el-nodo .nx-node-canvas-wrap {
            height: 360px !important;
            border-radius: 1rem !important;
            overflow: visible !important;
            margin-bottom: 1.5rem !important;
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
          /* Hide desktop cursor hint, show mobile touch hint instead */
          #el-nodo .nx-cursor-hint-desktop { display: none !important; }
          #el-nodo .nx-node-specs { padding-left: 0 !important; padding-top: 1.5rem !important; }
          #el-nodo .nx-node-specs > div {
            padding: 1rem 0.5rem !important;
            gap: 0.75rem !important;
          }
        }
      `}</style>
    </div>
  )
}
