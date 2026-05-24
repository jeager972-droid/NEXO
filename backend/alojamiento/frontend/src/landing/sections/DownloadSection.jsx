import { useRef, useEffect } from 'react'
import { useReveal } from '../components/useReveal'
import gsap from 'gsap'

// MODULE 08 — APP DOWNLOAD
// CAMBIO 4: Eliminado mockup de la app. Íconos al doble de tamaño.
// GSAP magnetic/tilt + scale hover por plataforma con glow representativo.

const PLATFORMS = [
  {
    id: 'android',
    name: 'Android',
    glowColor: 'rgba(61,220,132,0.35)',   // Android green
    href: '#download-android',
    icon: (
      <svg width="56" height="56" viewBox="0 0 56 56" fill="none" stroke="currentColor"
        strokeWidth="1.1" strokeLinecap="round" strokeLinejoin="round">
        {/* Android robot icon */}
        <path d="M14 20h28v24a4 4 0 01-4 4H18a4 4 0 01-4-4V20z"/>
        <path d="M20 20V14a8 8 0 0116 0v6"/>
        <circle cx="21" cy="33" r="2" fill="currentColor" stroke="none"/>
        <circle cx="35" cy="33" r="2" fill="currentColor" stroke="none"/>
        <line x1="10" y1="26" x2="10" y2="36"/>
        <line x1="46" y1="26" x2="46" y2="36"/>
      </svg>
    ),
  },
  {
    id: 'ios',
    name: 'iOS',
    glowColor: 'rgba(180,180,185,0.35)',   // Apple silver
    href: '#download-ios',
    icon: (
      <svg width="56" height="56" viewBox="0 0 56 56" fill="none" stroke="currentColor"
        strokeWidth="1.1" strokeLinecap="round" strokeLinejoin="round">
        <path d="M37 4C34 4 31 6 28 6s-6-2-9-2C12 4 6 10 6 19c0 13 8 31 14 31 3 0 4-2 8-2s5 2 8 2c6 0 14-18 14-29C50 10 44 4 37 4z"/>
        <path d="M28 6V2"/>
      </svg>
    ),
  },
  {
    id: 'windows',
    name: 'Windows',
    glowColor: 'rgba(0,120,212,0.35)',     // Windows blue
    href: '#download-windows',
    icon: (
      <svg width="56" height="56" viewBox="0 0 56 56" fill="none" stroke="currentColor"
        strokeWidth="1.1" strokeLinecap="round" strokeLinejoin="round">
        <rect x="4"  y="4"  width="22" height="22" rx="2"/>
        <rect x="30" y="4"  width="22" height="22" rx="2"/>
        <rect x="4"  y="30" width="22" height="22" rx="2"/>
        <rect x="30" y="30" width="22" height="22" rx="2"/>
      </svg>
    ),
  },
  {
    id: 'mac',
    name: 'Mac',
    glowColor: 'rgba(180,180,185,0.35)',   // Apple silver
    href: '#download-mac',
    icon: (
      <svg width="56" height="56" viewBox="0 0 56 56" fill="none" stroke="currentColor"
        strokeWidth="1.1" strokeLinecap="round" strokeLinejoin="round">
        <rect x="6" y="6" width="44" height="32" rx="4"/>
        <line x1="2"  y1="48" x2="54" y2="48"/>
        <line x1="20" y1="38" x2="36" y2="38"/>
        <line x1="28" y1="38" x2="28" y2="48"/>
      </svg>
    ),
  },
  {
    id: 'linux',
    name: 'Linux',
    glowColor: 'rgba(255,185,0,0.30)',     // Tux yellow
    href: '#download-linux',
    icon: (
      <svg width="56" height="56" viewBox="0 0 56 56" fill="none" stroke="currentColor"
        strokeWidth="1.1" strokeLinecap="round" strokeLinejoin="round">
        <path d="M28 4c-11 0-16 8-16 18v4c0 3-1 6-3 9C7 38 6 40 6 42c0 3 4 6 11 6 3 0 6-1 8-3 1 1 2 1 3 1s2 0 3-1c2 2 5 3 8 3 7 0 11-3 11-6 0-2-1-4-3-7-2-3-3-6-3-9v-4C44 12 39 4 28 4z"/>
        <circle cx="21" cy="24" r="2" fill="currentColor" stroke="none"/>
        <circle cx="35" cy="24" r="2" fill="currentColor" stroke="none"/>
        <path d="M22 34c1.5 2 4 3 6 3s4.5-1 6-3"/>
      </svg>
    ),
  },
]

// CAMBIO 4: GSAP magnetic tilt + scale + glow on hover
function PlatformCard({ id, name, icon, href, glowColor }) {
  const cardRef    = useRef()
  const glowRef    = useRef()
  const iconRef    = useRef()

  useEffect(() => {
    const card = cardRef.current
    const glow = glowRef.current
    const iconEl = iconRef.current
    if (!card) return

    // Tilt/magnetic: tracks cursor position within card, max ±12 deg
    const handleMouseMove = (e) => {
      const rect = card.getBoundingClientRect()
      const cx   = rect.left + rect.width / 2
      const cy   = rect.top  + rect.height / 2
      const dx   = (e.clientX - cx) / (rect.width  / 2)  // -1 to 1
      const dy   = (e.clientY - cy) / (rect.height / 2)

      // Tilt the icon — gentle magnetic pull (max 8deg)
      gsap.to(iconEl, {
        rotateX: -dy * 8,
        rotateY:  dx * 8,
        duration: 0.25,
        ease: 'power2.out',
      })
    }

    // Hover in: scale + translateY + glow
    const handleMouseEnter = () => {
      gsap.to(card, {
        scale: 1.12,
        y: -6,
        duration: 0.3,
        ease: 'power2.out',
      })
      gsap.to(glow, {
        opacity: 1,
        duration: 0.3,
        ease: 'power2.out',
      })
    }

    // Hover out: return to base state
    const handleMouseLeave = () => {
      gsap.to(card, {
        scale: 1,
        y: 0,
        duration: 0.25,
        ease: 'power2.inOut',
      })
      gsap.to(iconEl, {
        rotateX: 0,
        rotateY: 0,
        duration: 0.35,
        ease: 'power2.inOut',
      })
      gsap.to(glow, {
        opacity: 0,
        duration: 0.25,
        ease: 'power2.inOut',
      })
    }

    card.addEventListener('mouseenter', handleMouseEnter)
    card.addEventListener('mouseleave', handleMouseLeave)
    card.addEventListener('mousemove',  handleMouseMove)

    return () => {
      card.removeEventListener('mouseenter', handleMouseEnter)
      card.removeEventListener('mouseleave', handleMouseLeave)
      card.removeEventListener('mousemove',  handleMouseMove)
    }
  }, [])

  return (
    <a
      ref={cardRef}
      href={href}
      id={`download-btn-${id}`}
      aria-label={`Descargar NEXO para ${name}`}
      style={{
        display:        'flex',
        flexDirection:  'column',
        alignItems:     'center',
        gap:            '1rem',
        padding:        '2.25rem 2rem',
        background:     'var(--nx-surface)',
        border:         '1px solid var(--nx-border)',
        borderRadius:   '1.25rem',
        cursor:         'pointer',
        position:       'relative',
        overflow:       'hidden',
        willChange:     'transform',
        // Preserve 3D for tilt effect
        transformStyle: 'preserve-3d',
        textDecoration: 'none',
        transition:     'border-color 0.3s',
      }}
      onMouseEnter={e => e.currentTarget.style.borderColor = 'rgba(10,132,255,0.4)'}
      onMouseLeave={e => e.currentTarget.style.borderColor = 'var(--nx-border)'}
    >
      {/* Platform-specific glow — hidden by default, revealed on hover */}
      <div
        ref={glowRef}
        aria-hidden="true"
        style={{
          position:     'absolute',
          inset:        0,
          background:   `radial-gradient(circle at center, ${glowColor} 0%, transparent 70%)`,
          opacity:      0,
          pointerEvents: 'none',
          borderRadius: '1.25rem',
        }}
      />

      {/* Icon — double size vs original 28px → 56px */}
      <div
        ref={iconRef}
        style={{
          color:          'var(--nx-blue)',
          position:       'relative',
          zIndex:         1,
          transformStyle: 'preserve-3d',
        }}
      >
        {icon}
      </div>

      {/* Platform name */}
      <span style={{
        fontSize:      '0.8rem',
        fontWeight:    600,
        color:         'var(--nx-text)',
        letterSpacing: '0.04em',
        position:      'relative',
        zIndex:        1,
      }}>
        {name}
      </span>
    </a>
  )
}

export default function DownloadSection() {
  const sectionRef = useRef()
  useReveal(sectionRef)

  return (
    <section
      ref={sectionRef}
      id="descarga"
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
        {/* Header — centered */}
        <div style={{ textAlign: 'center', marginBottom: '5rem' }}>
          <div className="nx-eyebrow nx-reveal" style={{ justifyContent: 'center', display: 'flex' }}>
            La aplicación
          </div>
          <h2 className="nx-h2 nx-reveal nx-reveal-delay-1" style={{ marginBottom: '1rem' }}>
            Tu panel de control institucional.
          </h2>
          <p className="nx-body nx-reveal nx-reveal-delay-2" style={{ maxWidth: '480px', margin: '0 auto' }}>
            Disponible para Android, iOS, Windows, Mac y Linux.
            La misma información, en tiempo real, donde estés.
          </p>
        </div>

        {/* CAMBIO 4: Platform cards — íconos protagonistas, sin mockup */}
        <div
          className="nx-reveal nx-reveal-delay-3"
          style={{
            display:         'flex',
            gap:             '1.25rem',
            justifyContent:  'center',
            flexWrap:        'wrap',
          }}
        >
          {PLATFORMS.map(p => (
            <PlatformCard key={p.id} {...p} />
          ))}
        </div>

        {/* Micro-copy */}
        <p className="nx-micro nx-reveal nx-reveal-delay-4"
          style={{ textAlign: 'center', marginTop: '2.5rem' }}>
          Descarga gratuita para instituciones vinculadas ·
          El acceso completo se activa cuando la institución implementa NEXO.
        </p>
      </div>
    </section>
  )
}
