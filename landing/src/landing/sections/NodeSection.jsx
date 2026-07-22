/**
 * =============================================================================
 * NodeSection.jsx — Sección "El nodo" de la landing NEXO.
 * =============================================================================
 * RESPONSABILIDAD:
 *   Muestra el modelo 3D del nodo NEXO con hotspots interactivos. Al tocar un
 *   hotspot se actualiza el panel de especificaciones con datos de materiales,
 *   batería, conectividad y encriptación. Usa NexoCanvas con type='solo'.
 *
 * DEPENDENCIAS:
 *   - react hooks, gsap / ScrollTrigger
 *   - NexoCanvas, useReveal, useStickyScroll
 * =============================================================================
 */

import { useRef, useState, useEffect } from 'react'
import { useReveal } from '../components/useReveal'
import { useStickyScroll } from '../components/useStickyScroll'
import NexoCanvas from '../components/NexoCanvas'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
// gsap.registerPlugin called once globally in LandingPage.jsx

// MODULE 06 — THE NODE
// Center: large interactive 3D model with hotspot overlay
// Below: spec list synced to hotspot clicks

const SPECS = [
  {
    id: 'steel',
    label: 'Con materiales pensados para la durabilidad',
    meaning: 'Carcasa en acero inoxidable con certificación IP66. Resiste golpes, polvo y salpicaduras. Paneles acrílicos protegen la pantalla y el sensor. NEXO incluye cinco repuestos anuales por nodo sin costo adicional. No hay tornillería externa visible; la fijación a la pared se realiza completamente desde el interior, con cerradura de llave única.',
    hotspotPos: { top: '20%', left: '28%' },
    hotspotPosMobile: { top: '10%', left: '12%' },
  },
  {
    id: 'battery',
    label: 'Con autonomía de batería',
    meaning: 'Hasta 12 horas de operación continua ante cortes de energía. El flujo de registro y alertas no se detiene, incluso en las peores condiciones.',
    hotspotPos: { top: '45%', left: '14%' },
    hotspotPosMobile: { top: '40%', left: '4%' },
  },
  {
    id: 'sim',
    label: 'Conectividad propia',
    meaning: 'Protocolo de comunicación M2M con SIM Card independiente. No requiere la red de la institución para operar ni transmitir datos.',
    hotspotPos: { top: '68%', left: '26%' },
    hotspotPosMobile: { top: '75%', left: '10%' },
  },
  {
    id: 'encrypt',
    label: 'Encriptado de extremo a extremo',
    meaning: 'Todos los datos que gestiona este nodo viajan y se almacenan con encriptación completa en cada capa del sistema.',
    hotspotPos: { top: '30%', right: '18%' },
    hotspotPosMobile: { top: '15%', right: '6%' },
  },
]

function Hotspot({ spec, isActive, onClick, isMobile }) {
  const handlePointerUp = (e) => {
    e.stopPropagation()
    onClick(spec.id)
  }

  const pos = isMobile && spec.hotspotPosMobile ? spec.hotspotPosMobile : spec.hotspotPos
  const mobileGreen = '#6eb872'
  const dotSize = isMobile ? '14px' : '12px'

  return (
    <div
      className="nx-hotspot"
      style={{ position: 'absolute', ...pos, zIndex: 10 }}
      onPointerUp={handlePointerUp}
      onKeyDown={e => e.key === 'Enter' && onClick(spec.id)}
      role="button"
      tabIndex={0}
      aria-label={`Ver detalle: ${spec.label}`}
      aria-pressed={isActive}
    >
      <div
        className="nx-hotspot__ring"
        style={{
          background: isMobile ? mobileGreen : undefined,
        }}
      />
      <div
        className="nx-hotspot__dot"
        style={{
          width: dotSize,
          height: dotSize,
          background: isMobile ? mobileGreen : undefined,
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

  // Scroll-triggered entrance / exit animations (preserves auto-height, no sticky)
  useStickyScroll(wrapperRef, innerRef)

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth <= 768)
    check()
    window.addEventListener('resize', check)
    return () => window.removeEventListener('resize', check)
  }, [])

  useEffect(() => {
    const wrapper = wrapperRef.current
    const inner = innerRef.current
    if (!wrapper || !inner) return

    const isMobile = window.innerWidth <= 768
    if (isMobile) {
      setCanvasScale(1.0)
      return
    }

    // Ocultar hotspots al inicio
    const hotspots = inner.querySelectorAll('.nx-hotspot')
    gsap.set(hotspots, { opacity: 0, scale: 0 })

    const tl = gsap.timeline({
      scrollTrigger: {
        trigger: wrapper,
        start: 'top 75%',
        toggleActions: 'play none none none',
      }
    })

    // Modelo empieza con scale:1.12 y llega a 1.0
    const scaleObj = { val: 1.12 }
    tl.to(scaleObj, {
      val: 1.0,
      duration: 1.4,
      ease: 'power2.out',
      onUpdate: () => {
        setCanvasScale(scaleObj.val)
      }
    })

    // Hotspots aparecen con stagger
    tl.to(hotspots, {
      opacity: 1,
      scale: 1,
      duration: 0.5,
      stagger: 0.18,
      ease: 'back.out(1.7)',
    }, '-=0.1')

    return () => tl.kill()
  }, [])

  const toggle = (id) => setActive(prev => prev === id ? null : id)

  return (
    <div ref={wrapperRef} className="section-wrapper" id="el-nodo" style={{ height: 'auto', minHeight: '150vh' }}>
      <section
        ref={innerRef}
        className="section-inner"
        style={{
          background: 'linear-gradient(180deg, var(--nx-surface) 0%, var(--nx-deep) 100%)',
          overflow:   'visible',
          paddingLeft: 'var(--nx-section-px)',
          paddingRight: 'var(--nx-section-px)',
          paddingTop: '5rem',
          paddingBottom: '5rem',
          display: 'flex',
          alignItems: 'center',
          height: 'auto',
          minHeight: '100vh',
          position: 'relative',
        }}
      >
        <div style={{ maxWidth: '1280px', margin: '0 auto', width: '100%' }}>
          {/* Header */}
          <div className="nx-eyebrow nx-reveal">El hardware</div>
          <h3 className="nx-h2 nx-reveal nx-reveal-delay-1" style={{ maxWidth: '680px', marginBottom: '1rem' }}>
            El centro de la operación de NEXO
          </h3>
          <p className="nx-body nx-reveal nx-reveal-delay-2" style={{ maxWidth: '720px', marginBottom: '1rem' }}>
            NEXO implementa en cada aula de clase un nodo diseñado para aguantar durante años las condiciones reales de una institución educativa, el cual mediante su conectividad y batería autónoma, interconecta, automatiza y facilita la operación educativa de toda una institución.
          </p>
          <p
            className="nx-reveal nx-reveal-delay-3"
            style={{
              fontSize: '0.7rem',
              color: 'var(--nx-muted-2)',
              letterSpacing: '0.1em',
              textTransform: 'uppercase',
              marginBottom: '1.5rem',
            }}
          >
            Rota con el cursor · Toca los puntos
          </p>

          {/* Layout: 3D node centered, panel below on PC / beside on mobile handled via CSS */}
          <div
            className="nx-node-grid"
            style={{
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              gap: isMobile ? '1.5rem' : '2.5rem',
            }}
          >
            {/* ── CENTER: 3D model with hotspot overlay ── */}
            <div
              className="nx-node-canvas-wrap nx-reveal nx-reveal-delay-3"
              style={{
                position: 'relative',
                height: isMobile ? '360px' : '580px',
                width: '100%',
                maxWidth: isMobile ? '100%' : '860px',
                margin: '0 auto',
              }}
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

            </div>

            {/* ── BELOW: Dynamic spec panel (full width on PC) ── */}
            <div style={{ width: '100%' }}>
              <SpecPanel active={active} specs={SPECS} onClose={() => setActive(null)} />
            </div>
          </div>
        </div>
      </section>

      <style>{`
        @keyframes panelIn {
          from { opacity: 0; transform: translateY(8px); }
          to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
          #el-nodo .nx-node-canvas-wrap {
            border-radius: 1rem !important;
            overflow: visible !important;
            width: 75% !important;
            margin: 0 auto !important;
          }
          #el-nodo .nx-hotspot {
            display: block !important;
            touch-action: manipulation;
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
