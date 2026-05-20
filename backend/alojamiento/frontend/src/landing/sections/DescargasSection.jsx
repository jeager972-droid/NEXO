import { useEffect, useRef } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

gsap.registerPlugin(ScrollTrigger)

const PLATFORMS = [
  { name: 'iOS',     sub: 'App Store',       icon: '🍎', color: '#1c1c1e' },
  { name: 'Android', sub: 'APK / Play Store', icon: '🤖', color: '#1c6c34' },
  { name: 'Web App', sub: 'Browser',          icon: '🌐', color: '#0a5fa3' },
  { name: 'Windows', sub: 'Installer x64',    icon: '🪟', color: '#0078d4' },
  { name: 'macOS',   sub: 'DMG / Silicon',    icon: '💻', color: '#555' },
]

// ── 3D Tilt Card ──────────────────────────────────────────────────────────────
function TiltCard({ name, sub, icon, color, index }) {
  const cardRef = useRef()

  const handleMove = (e) => {
    const card  = cardRef.current
    if (!card) return
    const rect  = card.getBoundingClientRect()
    const cx    = rect.left + rect.width  / 2
    const cy    = rect.top  + rect.height / 2
    const dx    = (e.clientX - cx) / (rect.width  / 2)
    const dy    = (e.clientY - cy) / (rect.height / 2)
    const rotX  = -dy * 10
    const rotY  =  dx * 10
    card.style.transform = `perspective(700px) rotateX(${rotX}deg) rotateY(${rotY}deg) scale(1.04)`
    card.style.boxShadow = `0 20px 40px rgba(0,0,0,0.12), 0 0 0 1px rgba(0,230,118,0.15), ${-dx * 8}px ${-dy * 8}px 20px rgba(0,230,118,0.06)`
    // Shift inner shine
    const shine = card.querySelector('.nx-tilt-shine')
    if (shine) {
      shine.style.background = `radial-gradient(circle at ${50 + dx * 30}% ${50 + dy * 30}%, rgba(255,255,255,0.08) 0%, transparent 70%)`
    }
  }

  const handleLeave = () => {
    const card = cardRef.current
    if (!card) return
    gsap.to(card, {
      rotateX: 0, rotateY: 0, scale: 1,
      boxShadow: '0 4px 24px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.06)',
      duration: 0.6,
      ease: 'power3.out',
    })
    const shine = card.querySelector('.nx-tilt-shine')
    if (shine) {
      gsap.to(shine, { opacity: 0, duration: 0.4 })
    }
  }

  const handleEnter = () => {
    const shine = cardRef.current?.querySelector('.nx-tilt-shine')
    if (shine) gsap.to(shine, { opacity: 1, duration: 0.3 })
  }

  return (
    <div
      ref={cardRef}
      id={`download-card-${name.toLowerCase().replace(' ', '-')}`}
      onMouseMove={handleMove}
      onMouseLeave={handleLeave}
      onMouseEnter={handleEnter}
      style={{
        position:        'relative',
        background:      '#ffffff',
        borderRadius:    '1.2rem',
        border:          '1px solid rgba(0,0,0,0.07)',
        boxShadow:       '0 4px 24px rgba(0,0,0,0.07), 0 0 0 1px rgba(0,0,0,0.06)',
        padding:         '2rem 1.5rem 1.5rem',
        display:         'flex',
        flexDirection:   'column',
        alignItems:      'center',
        gap:             '0.6rem',
        cursor:          'pointer',
        transformStyle:  'preserve-3d',
        transition:      'transform 0.1s ease',
        minWidth:        150,
        flex:            '1 1 0',
        willChange:      'transform',
        opacity:          0,
      }}
    >
      {/* Green accent bar at top */}
      <div style={{
        position:     'absolute',
        top:           0,
        left:          '15%',
        right:         '15%',
        height:        3,
        background:   '#00c05a',
        borderRadius: '0 0 4px 4px',
      }} />

      {/* Tilt shine overlay */}
      <div className="nx-tilt-shine" style={{
        position:      'absolute',
        inset:          0,
        borderRadius:  '1.2rem',
        opacity:        0,
        pointerEvents: 'none',
      }} />

      {/* Icon */}
      <div style={{
        fontSize:     '2.4rem',
        lineHeight:    1,
        marginBottom: '0.3rem',
      }}>
        {icon}
      </div>

      {/* Platform name */}
      <span style={{
        fontFamily:   "'Plus Jakarta Sans', 'Outfit', sans-serif",
        fontWeight:    800,
        fontSize:     '1.05rem',
        color:        '#0d1410',
        letterSpacing:'-0.01em',
      }}>
        {name}
      </span>

      {/* Sub label */}
      <span style={{
        fontFamily:    "'JetBrains Mono', monospace",
        fontSize:      '0.6rem',
        letterSpacing: '0.1em',
        color:         'rgba(0,0,0,0.35)',
        textTransform: 'uppercase',
      }}>
        {sub}
      </span>

      {/* Download CTA */}
      <div style={{
        marginTop:   'auto',
        paddingTop:  '1rem',
        fontFamily:  "'Plus Jakarta Sans', sans-serif",
        fontWeight:   600,
        fontSize:    '0.78rem',
        color:       '#00a84a',
        display:     'flex',
        alignItems:  'center',
        gap:         '0.3rem',
      }}>
        ↓ Descargar
      </div>
    </div>
  )
}

// ── Main Section ──────────────────────────────────────────────────────────────
export default function DescargasSection() {
  const sectionRef  = useRef()
  const headerRef   = useRef()
  const cardsRef    = useRef()
  const canvasRef   = useRef(null)

  useEffect(() => {
    const section = sectionRef.current
    if (!section) return

    const ctx = gsap.context(() => {
      // Header + cards staggered entry
      const entryTl = gsap.timeline({
        scrollTrigger: {
          trigger: section,
          start:   'top 70%',
          end:     'top 30%',
          toggleActions: 'play none none reverse',
        }
      })

      entryTl
        .fromTo(headerRef.current.children,
          { opacity: 0, y: 22 },
          { opacity: 1, y: 0, duration: 0.65, ease: 'power3.out', stagger: 0.1 }
        )
        .fromTo(cardsRef.current.children,
          { opacity: 0, y: 36, scale: 0.96 },
          { opacity: 1, y: 0, scale: 1, duration: 0.6, ease: 'power3.out', stagger: 0.08 },
          '-=0.3'
        )

      // Transition body background color
      ScrollTrigger.create({
        trigger: section,
        start:   'top 55%',
        end:     'bottom center',
        onEnter: () => {
          if (document.body) gsap.to(document.body, { backgroundColor: '#f4f7f5', duration: 0.8 })
        },
        onLeaveBack: () => {
          if (document.body) gsap.to(document.body, { backgroundColor: '#0a0f0d', duration: 0.8 })
        },
      })
    }, section)

    return () => ctx.revert()
  }, [])

  return (
    <section
      id="nx-section-descargas"
      ref={sectionRef}
      data-section="descargas"
      className="relative min-h-[100dvh] flex flex-col justify-center overflow-hidden py-28"
      style={{ background: '#f4f7f5' }}
    >
      {/* ── Header ── */}
      <div
        ref={headerRef}
        style={{
          textAlign:   'center',
          marginBottom: '4rem',
          padding:     '0 2rem',
        }}
      >
        {/* Eyebrow */}
        <div style={{ marginBottom: '1.4rem', opacity: 0 }}>
          <span style={{
            fontFamily:    "'JetBrains Mono', monospace",
            fontSize:      '0.63rem',
            fontWeight:    400,
            letterSpacing: '0.2em',
            textTransform: 'uppercase',
            color:         '#1c6c34',
            border:        '1px solid rgba(0,100,50,0.25)',
            borderRadius:  '999px',
            padding:       '0.32rem 0.85rem',
            background:    'rgba(0,200,100,0.06)',
            display:       'inline-block',
          }}>
            Despliegue Global
          </span>
        </div>

        {/* Heading */}
        <div style={{ opacity: 0 }}>
          <h2 style={{
            fontFamily:   "'Plus Jakarta Sans', 'Outfit', sans-serif",
            fontWeight:    800,
            fontSize:     'clamp(2.4rem, 5vw, 4rem)',
            lineHeight:    1.05,
            letterSpacing: '-0.025em',
            color:         '#0a100d',
            margin:        '0 0 0.8rem',
          }}>
            Disponible en todas<br />tus plataformas.
          </h2>
        </div>

        {/* Sub */}
        <div style={{ opacity: 0 }}>
          <p style={{
            fontFamily: "'Plus Jakarta Sans', sans-serif",
            fontWeight:  400,
            fontSize:   'clamp(0.88rem, 1.3vw, 1.05rem)',
            color:      'rgba(10,20,15,0.45)',
            margin:      0,
          }}>
            Descarga la app NEXO en el dispositivo que prefieras.
          </p>
        </div>
      </div>

      {/* ── Cards ── */}
      <div
        ref={cardsRef}
        style={{
          display:       'flex',
          gap:           '1.2rem',
          padding:       '0 clamp(2rem, 8vw, 6rem)',
          alignItems:    'stretch',
          flexWrap:      'wrap',
          justifyContent:'center',
        }}
      >
        {PLATFORMS.map((p, i) => (
          <TiltCard key={p.name} {...p} index={i} />
        ))}
      </div>

      {/* ── Footer legal line ── */}
      <div style={{
        textAlign:  'center',
        marginTop:  '4rem',
        fontFamily: "'JetBrains Mono', monospace",
        fontSize:   '0.58rem',
        letterSpacing: '0.12em',
        color:      'rgba(10,20,15,0.25)',
        textTransform: 'uppercase',
      }}>
        © 2025 NEXO · EdTech Antioqueña · Todos los derechos reservados
      </div>
    </section>
  )
}
