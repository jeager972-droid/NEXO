import { useEffect, useRef, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'
import { View } from '@react-three/drei'
import NexoModelViewer from '../../components/canvas/NexoModelViewer'

gsap.registerPlugin(ScrollTrigger)

// Annotations: side, label, vertical offset (%)
const LEFT_ANNOTATIONS = [
  { label: 'Carcasa Exterior',  top: '28%' },
  { label: 'PCB Principal',     top: '46%' },
  { label: 'Panel Biométrico',  top: '62%' },
]
const RIGHT_ANNOTATIONS = [
  { label: 'Panel Trasero',         top: '32%' },
  { label: 'Puertos de Conectividad', top: '50%' },
  { label: 'LED de Estado',         top: '64%' },
]

function AnnotationLeft({ label, top, visible }) {
  return (
    <div style={{
      position:   'absolute',
      left:       '3.5%',
      top,
      display:    'flex',
      alignItems: 'center',
      gap:        '0.6rem',
      opacity:     visible ? 1 : 0,
      transform:   visible ? 'translateX(0)' : 'translateX(-12px)',
      transition:  'opacity 0.5s cubic-bezier(0.32,0.72,0,1), transform 0.5s cubic-bezier(0.32,0.72,0,1)',
      pointerEvents: 'none',
    }}>
      <span style={{
        fontFamily:    "'JetBrains Mono', monospace",
        fontSize:      '0.62rem',
        letterSpacing: '0.08em',
        color:         'rgba(255,255,255,0.65)',
        whiteSpace:    'nowrap',
      }}>
        {label}
      </span>
      {/* Hairline extending right */}
      <div style={{
        flex:       1,
        height:     '1px',
        minWidth:   '3rem',
        maxWidth:   '8rem',
        background: 'linear-gradient(90deg, rgba(255,255,255,0.25) 0%, transparent 100%)',
      }} />
    </div>
  )
}

function AnnotationRight({ label, top, visible }) {
  return (
    <div style={{
      position:   'absolute',
      right:      '3.5%',
      top,
      display:    'flex',
      alignItems: 'center',
      flexDirection: 'row-reverse',
      gap:        '0.6rem',
      opacity:     visible ? 1 : 0,
      transform:   visible ? 'translateX(0)' : 'translateX(12px)',
      transition:  'opacity 0.5s cubic-bezier(0.32,0.72,0,1), transform 0.5s cubic-bezier(0.32,0.72,0,1)',
      pointerEvents: 'none',
    }}>
      <span style={{
        fontFamily:    "'JetBrains Mono', monospace",
        fontSize:      '0.62rem',
        letterSpacing: '0.08em',
        color:         'rgba(255,255,255,0.65)',
        whiteSpace:    'nowrap',
        textAlign:     'right',
      }}>
        {label}
      </span>
      {/* Hairline extending left */}
      <div style={{
        flex:       1,
        height:     '1px',
        minWidth:   '3rem',
        maxWidth:   '8rem',
        background: 'linear-gradient(270deg, rgba(255,255,255,0.25) 0%, transparent 100%)',
      }} />
    </div>
  )
}

export default function ExplodedSection() {
  const sectionRef   = useRef()
  const bottomRef    = useRef()
  const visibleState = useRef(false)
  const canvasWrapperRef = useRef()
  const [isInView, setIsInView] = useState(false)

  // Rerender trigger for annotation visibility
  const annotRef = useRef()

  useEffect(() => {
    const section = sectionRef.current
    if (!section) return

    const ctx = gsap.context(() => {
      // ── Entry: annotations fade + bottom text ─────────────────────────────
      ScrollTrigger.create({
        trigger: section,
        start:   'top 60%',
        end:     'bottom 40%',
        onEnter: () => {
          visibleState.current = true
          if (annotRef.current) annotRef.current.setAttribute('data-visible', 'true')

          // Bottom text in
          if (bottomRef.current) {
            gsap.fromTo(bottomRef.current.children,
              { opacity: 0, y: 22 },
              { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out', stagger: 0.12, delay: 0.2 }
            )
          }
        },
        onLeaveBack: () => {
          if (annotRef.current) annotRef.current.setAttribute('data-visible', 'false')
        },
        onLeave: () => {
          if (annotRef.current) annotRef.current.setAttribute('data-visible', 'false')
        },
      })

      // ── Animate local Canvas entry/exit ──
      gsap.fromTo(canvasWrapperRef.current,
        { opacity: 0, scale: 0.8 },
        {
          opacity: 1,
          scale: 1,
          duration: 0.8,
          ease: 'power2.out',
          scrollTrigger: {
            trigger: section,
            start: 'top 80%',
            end: 'bottom 20%',
            toggleActions: 'play reverse play reverse',
            onToggle: (self) => setIsInView(self.isActive)
          }
        }
      )
    }, section)

    // React to data-visible attribute changes for annotation CSS transitions
    const observer = new MutationObserver(() => {
      const isVisible = annotRef.current?.getAttribute('data-visible') === 'true'
      // Force re-paint by toggling a class
      if (annotRef.current) {
        annotRef.current.classList.toggle('nx-annot-visible', isVisible)
      }
    })
    if (annotRef.current) observer.observe(annotRef.current, { attributes: true })

    return () => {
      ctx.revert()
      observer.disconnect()
    }
  }, [])

  return (
    <section
      id="nx-section-exploded"
      ref={sectionRef}
      data-section="exploded"
      className="relative min-h-[100dvh] flex flex-col justify-between overflow-hidden pointer-events-none"
      style={{ padding: '4rem 0 5rem' }}
    >
      {/* ── Local WebGL Canvas ── */}
      <div 
        ref={canvasWrapperRef}
        style={{
          position: 'absolute',
          left: '50%',
          top: '46%',
          transform: 'translate(-50%, -50%)',
          width: '60%',
          height: '65%',
          zIndex: 1,
          pointerEvents: 'auto',
          opacity: 0,
        }}
      >
        {isInView && (
          <View track={canvasWrapperRef} style={{ width: '100%', height: '100%' }}>
            <NexoModelViewer type="exploded" scale={1.25} />
          </View>
        )}
      </div>
      {/* ── Top center badge ── */}
      <div style={{ display: 'flex', justifyContent: 'center', paddingTop: '2rem', pointerEvents: 'auto' }}>
        <span style={{
          fontFamily:    "'JetBrains Mono', monospace",
          fontSize:      '0.6rem',
          letterSpacing: '0.2em',
          textTransform: 'uppercase',
          color:         '#00e676',
          border:        '1px solid rgba(0,230,118,0.28)',
          borderRadius:  '999px',
          padding:       '0.3rem 0.85rem',
          background:    'rgba(0,230,118,0.04)',
        }}>
          Anatomía · HW-12
        </span>
      </div>

      {/* ── Annotations layer (absolute, full-height) ── */}
      <div
        ref={annotRef}
        data-visible="false"
        style={{ position: 'absolute', inset: 0, pointerEvents: 'none', zIndex: 10 }}
      >
        {LEFT_ANNOTATIONS.map(({ label, top }) => (
          <AnnotationLeft
            key={label}
            label={label}
            top={top}
            visible={annotRef.current?.getAttribute('data-visible') === 'true'}
          />
        ))}
        {RIGHT_ANNOTATIONS.map(({ label, top }) => (
          <AnnotationRight
            key={label}
            label={label}
            top={top}
            visible={annotRef.current?.getAttribute('data-visible') === 'true'}
          />
        ))}
      </div>

      {/* ── Bottom text ── */}
      <div ref={bottomRef} style={{ padding: '0 2.5rem 0 clamp(2.5rem, 8vw, 7rem)', maxWidth: '600px', pointerEvents: 'auto' }}>
        <h2 style={{
          opacity:       0,
          fontFamily:    "'Plus Jakarta Sans', 'Outfit', sans-serif",
          fontWeight:     800,
          fontSize:      'clamp(2.2rem, 4.5vw, 3.5rem)',
          lineHeight:     1.05,
          letterSpacing: '-0.025em',
          color:          '#ffffff',
          margin:         0,
        }}>
          Cada parte,<br />un propósito.
        </h2>
        <p style={{
          opacity:    0,
          fontFamily: "'Plus Jakarta Sans', sans-serif",
          fontWeight:  400,
          fontSize:   'clamp(0.85rem, 1.2vw, 0.95rem)',
          lineHeight:  1.65,
          color:      '#7a9186',
          marginTop:  '0.9rem',
        }}>
          Seis sistemas integrados en un chasis IP66 de acero galvanizado.
          Cero compromisos.
        </p>
      </div>

      {/* Annotation visibility CSS hack */}
      <style>{`
        .nx-annot-visible [style*="translateX(-12px)"] {
          opacity: 1 !important;
          transform: translateX(0) !important;
        }
        .nx-annot-visible [style*="translateX(12px)"] {
          opacity: 1 !important;
          transform: translateX(0) !important;
        }
      `}</style>
    </section>
  )
}
