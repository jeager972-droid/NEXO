import { useEffect, useRef, useState } from 'react'
import gsap from 'gsap'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

gsap.registerPlugin(ScrollTrigger)

// ── App Mockup States ────────────────────────────────────────────────
// 0 = Home (Citar button)
// 1 = Form (Grupo, Estudiante, Motivo)
// 2 = Chat (WhatsApp-style NEXO push message)

function AppMockup() {
  const [state, setState] = useState(0)
  const [form, setForm] = useState({ grupo: '', estudiante: '', motivo: '' })
  const [chatChoice, setChatChoice] = useState(null)

  const handleSend = (e) => {
    e.preventDefault()
    setState(2)
  }

  const handleReset = () => {
    setState(0)
    setForm({ grupo: '', estudiante: '', motivo: '' })
    setChatChoice(null)
  }

  const inputStyle = {
    width: '100%',
    background: 'rgba(255,255,255,0.06)',
    border: '1px solid rgba(255,255,255,0.12)',
    borderRadius: '0.6rem',
    padding: '0.65rem 0.85rem',
    color: '#fff',
    fontSize: '0.8rem',
    fontFamily: "'Plus Jakarta Sans', sans-serif",
    outline: 'none',
  }

  const labelStyle = {
    fontSize: '0.65rem',
    fontFamily: "'JetBrains Mono', monospace",
    letterSpacing: '0.12em',
    textTransform: 'uppercase',
    color: 'rgba(255,255,255,0.4)',
    display: 'block',
    marginBottom: '0.3rem',
  }

  return (
    /* ── Phone Frame ── */
    <div style={{
      width: 280,
      background: '#111',
      borderRadius: '2.5rem',
      border: '2px solid rgba(255,255,255,0.12)',
      overflow: 'hidden',
      boxShadow: '0 30px 60px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.05)',
      flexShrink: 0,
    }}>
      {/* Notch */}
      <div style={{ height: 14, background: '#0a0a0a', display: 'flex', justifyContent: 'center', alignItems: 'center' }}>
        <div style={{ width: 60, height: 5, background: '#1a1a1a', borderRadius: 99 }} />
      </div>

      {/* App header */}
      <div style={{
        background: '#141a17',
        padding: '0.75rem 1rem',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        borderBottom: '1px solid rgba(255,255,255,0.06)',
      }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.4rem' }}>
          <span style={{ width: 15, height: 15, borderRadius: 4, background: '#00e676', display: 'flex', alignItems: 'center', justifyContent: 'center', fontSize: 9, fontWeight: 900, color: '#0a0f0d' }}>N</span>
          <span style={{ fontFamily: "'Plus Jakarta Sans', sans-serif", fontWeight: 700, fontSize: '0.78rem', color: '#fff' }}>NEXO App</span>
          <span style={{ width: 6, height: 6, borderRadius: '50%', background: '#00e676', animation: 'nx-led 2s ease-in-out infinite' }} />
        </div>
        {state > 0 && (
          <button onClick={handleReset} style={{ background: 'none', border: 'none', color: 'rgba(255,255,255,0.4)', fontSize: '0.7rem', cursor: 'pointer', fontFamily: 'monospace' }}>
            ← Volver
          </button>
        )}
      </div>

      {/* Screen content */}
      <div style={{ background: '#0e1512', minHeight: 410, padding: '1.2rem 1rem', display: 'flex', flexDirection: 'column' }}>

        {/* STATE 0: Home */}
        {state === 0 && (
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', flex: 1, gap: '1.2rem', paddingTop: '2rem' }}>
            <div style={{ textAlign: 'center', marginBottom: '0.5rem' }}>
              <p style={{ fontFamily: "'Plus Jakarta Sans', sans-serif", fontSize: '0.75rem', color: 'rgba(255,255,255,0.35)', margin: 0 }}>Buenos días, Docente</p>
              <p style={{ fontFamily: "'Plus Jakarta Sans', sans-serif", fontSize: '0.9rem', fontWeight: 700, color: '#fff', margin: '0.3rem 0 0' }}>¿Qué necesitas hoy?</p>
            </div>
            <button
              id="app-citar-btn"
              onClick={() => setState(1)}
              style={{
                background: '#00c05a',
                color: '#fff',
                border: 'none',
                borderRadius: '999px',
                padding: '0.8rem 1.4rem',
                fontFamily: "'Plus Jakarta Sans', sans-serif",
                fontWeight: 700,
                fontSize: '0.85rem',
                cursor: 'pointer',
                width: '100%',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '0.4rem',
                transition: 'background 0.2s ease',
              }}
              onMouseEnter={e => { e.currentTarget.style.background = '#00d966' }}
              onMouseLeave={e => { e.currentTarget.style.background = '#00c05a' }}
            >
              📋 Citar Acudiente
            </button>
             <p style={{ fontFamily: "'Plus Jakarta Sans', sans-serif", fontSize: '0.68rem', color: 'rgba(255,255,255,0.25)', textAlign: 'center' }}>
              Toca para iniciar el proceso de citación
            </p>
          </div>
        )}

        {/* STATE 1: Form */}
        {state === 1 && (
          <form onSubmit={handleSend} style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
            <p style={{ fontFamily: "'Plus Jakarta Sans', sans-serif", fontWeight: 700, fontSize: '0.88rem', color: '#fff', margin: '0 0 0.25rem' }}>Nueva Citación</p>
            <div>
              <label style={labelStyle}>Grupo</label>
              <select value={form.grupo} onChange={e => setForm({...form, grupo: e.target.value})} required style={inputStyle}>
                <option value="">Seleccionar...</option>
                {['10-A', '10-B', '11-A', '9-C'].map(g => <option key={g}>{g}</option>)}
              </select>
            </div>
            <div>
              <label style={labelStyle}>Estudiante</label>
              <input
                type="text"
                placeholder="Nombre completo"
                value={form.estudiante}
                onChange={e => setForm({...form, estudiante: e.target.value})}
                required
                style={inputStyle}
              />
            </div>
            <div>
              <label style={labelStyle}>Motivo</label>
              <select value={form.motivo} onChange={e => setForm({...form, motivo: e.target.value})} required style={inputStyle}>
                <option value="">Seleccionar...</option>
                {['Ausentismo', 'Disciplina', 'Académico', 'Salud'].map(m => <option key={m}>{m}</option>)}
              </select>
            </div>
            <button type="submit" style={{
              background: '#00c05a',
              color: '#fff',
              border: 'none',
              borderRadius: '999px',
              padding: '0.75rem',
              fontFamily: "'Plus Jakarta Sans', sans-serif",
              fontWeight: 700,
              fontSize: '0.82rem',
              cursor: 'pointer',
              marginTop: '0.25rem',
              transition: 'background 0.2s ease',
            }}
              onMouseEnter={e => { e.currentTarget.style.background = '#00d966' }}
              onMouseLeave={e => { e.currentTarget.style.background = '#00c05a' }}
            >
              Enviar Citación →
            </button>
          </form>
        )}

        {/* STATE 2: WhatsApp-style chat */}
        {state === 2 && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: '0.55rem' }}>
            {/* Status bar */}
            <div style={{ fontSize: '0.62rem', fontFamily: 'monospace', color: 'rgba(255,255,255,0.25)', textAlign: 'center', letterSpacing: '0.08em' }}>
              NEXO · Notificación push enviada
            </div>

            {/* Timestamp */}
            <div style={{ textAlign: 'center', fontSize: '0.62rem', color: 'rgba(255,255,255,0.2)', fontFamily: 'monospace' }}>
              {new Date().toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' })}
            </div>

            {/* NEXO push bubble */}
            <div style={{
              background: '#1e2e25',
              border: '1px solid rgba(0,230,118,0.2)',
              borderRadius: '0.8rem 0.8rem 0.8rem 0.2rem',
              padding: '0.8rem 0.9rem',
              maxWidth: '85%',
            }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.3rem', marginBottom: '0.4rem' }}>
                <span style={{ width: 14, height: 14, borderRadius: 3, background: '#00e676', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', fontSize: 8, fontWeight: 900, color: '#0a0f0d' }}>N</span>
                <span style={{ fontFamily: "'JetBrains Mono', monospace", fontSize: '0.65rem', color: '#00e676', letterSpacing: '0.05em' }}>NEXO Sistema</span>
              </div>
              <p style={{ fontFamily: "'Plus Jakarta Sans', sans-serif", fontSize: '0.78rem', color: 'rgba(255,255,255,0.85)', lineHeight: 1.45, margin: 0 }}>
                Hola. Fue generada una citación para <strong style={{ color: '#fff' }}>{form.estudiante || 'el estudiante'}</strong>. ¿Puede asistir?
              </p>
            </div>

            {/* Option buttons */}
            {chatChoice === null && (
              <div style={{ display: 'flex', flexDirection: 'column', gap: '0.45rem', marginLeft: 'auto', maxWidth: '85%', width: '100%' }}>
                {['1. Sí, confirmo asistencia', '2. No puedo, reagendar'].map((opt, i) => (
                  <button
                    key={i}
                    id={`chat-option-${i + 1}`}
                    onClick={() => setChatChoice(i)}
                    style={{
                      background: i === 0 ? 'rgba(0,192,90,0.18)' : 'rgba(255,255,255,0.06)',
                      border: `1px solid ${i === 0 ? 'rgba(0,230,118,0.35)' : 'rgba(255,255,255,0.1)'}`,
                      borderRadius: '0.8rem 0.8rem 0.2rem 0.8rem',
                      padding: '0.6rem 0.8rem',
                      color: i === 0 ? '#00e676' : 'rgba(255,255,255,0.7)',
                      fontFamily: "'Plus Jakarta Sans', sans-serif",
                      fontSize: '0.74rem',
                      fontWeight: 600,
                      cursor: 'pointer',
                      textAlign: 'right',
                      transition: 'all 0.2s ease',
                    }}
                  >
                    {opt}
                  </button>
                ))}
              </div>
            )}

            {/* Confirmation */}
            {chatChoice !== null && (
              <>
                <div style={{
                  background: chatChoice === 0 ? 'rgba(0,192,90,0.18)' : 'rgba(255,150,50,0.12)',
                  border: `1px solid ${chatChoice === 0 ? 'rgba(0,230,118,0.25)' : 'rgba(255,150,50,0.2)'}`,
                  borderRadius: '0.8rem 0.8rem 0.2rem 0.8rem',
                  padding: '0.6rem 0.8rem',
                  maxWidth: '85%',
                  marginLeft: 'auto',
                }}>
                  <p style={{ fontFamily: "'Plus Jakarta Sans', sans-serif", fontSize: '0.74rem', color: chatChoice === 0 ? '#00e676' : '#ff9632', margin: 0, fontWeight: 600 }}>
                    {chatChoice === 0 ? '1. Sí, confirmo asistencia' : '2. No puedo, reagendar'}
                  </p>
                </div>
                <div style={{
                  background: '#1e2e25',
                  border: '1px solid rgba(0,230,118,0.2)',
                  borderRadius: '0.8rem 0.8rem 0.8rem 0.2rem',
                  padding: '0.65rem 0.8rem',
                  maxWidth: '85%',
                }}>
                  <p style={{ fontFamily: "'Plus Jakarta Sans', sans-serif", fontSize: '0.74rem', color: 'rgba(255,255,255,0.8)', margin: 0 }}>
                    {chatChoice === 0
                      ? '✅ Confirmado. La institución ha sido notificada.'
                      : '📅 Entendido. Recibirá una nueva fecha pronto.'}
                  </p>
                </div>
              </>
            )}
          </div>
        )}
      </div>

      {/* Home indicator */}
      <div style={{ height: 18, background: '#0e1512', display: 'flex', justifyContent: 'center', alignItems: 'center' }}>
        <div style={{ width: 60, height: 4, background: 'rgba(255,255,255,0.15)', borderRadius: 99 }} />
      </div>

      <style>{`
        @keyframes nx-led {
          0%, 100% { opacity: 1; }
          50%       { opacity: 0.3; }
        }
      `}</style>
    </div>
  )
}

// ── Main Section Component ────────────────────────────────────────────────────
export default function EcosistemaSection() {
  const sectionRef = useRef()
  const leftRef    = useRef()
  const rightRef   = useRef()
  const ledRef     = useRef(null) // GSAP ticker ref for LED pulse

  useEffect(() => {
    const section = sectionRef.current
    if (!section) return

    const ctx = gsap.context(() => {
      // ── Entry text animations ──────────────────────────────────────────────
      gsap.timeline({
        scrollTrigger: {
          trigger: section,
          start:   'top 70%',
          end:     'top 30%',
          toggleActions: 'play none none reverse',
        }
      })
        .fromTo(leftRef.current.children,
          { opacity: 0, y: 28 },
          { opacity: 1, y: 0, duration: 0.7, ease: 'power3.out', stagger: 0.12 }
        )
        .fromTo(rightRef.current,
          { opacity: 0, x: 30 },
          { opacity: 1, x: 0, duration: 0.8, ease: 'power3.out' },
          '-=0.4'
        )

      // ── Background transition ScrollTrigger ──
      ScrollTrigger.create({
        trigger: section,
        start:   'top center',
        end:     'bottom center',
        onEnter: () => {
          if (document.body) gsap.to(document.body, { backgroundColor: '#0a0f0d', duration: 0.8 })
        },
        onEnterBack: () => {
          if (document.body) gsap.to(document.body, { backgroundColor: '#0a0f0d', duration: 0.8 })
        },
        onLeaveBack: () => {
          if (document.body) gsap.to(document.body, { backgroundColor: '#121212', duration: 0.8 })
        },
      })
    }, section)

    return () => ctx.revert()
  }, [])

  return (
    <section
      id="nx-section-ecosistema"
      ref={sectionRef}
      data-section="ecosistema"
      className="relative min-h-[100dvh] flex items-center overflow-hidden py-28"
    >
      {/* ── Data beam particle effect (CSS only) ── */}
      <div aria-hidden="true" style={{
        position: 'absolute',
        left:     '35%',
        top:      '50%',
        transform:'translateY(-50%)',
        width:    '30%',
        height:   2,
        background: 'linear-gradient(90deg, rgba(0,230,118,0.6) 0%, rgba(0,230,118,0) 100%)',
        zIndex:   0,
        filter:   'blur(1px)',
        animation:'nx-beam 2.4s ease-in-out infinite',
      }} />
      <div aria-hidden="true" style={{
        position: 'absolute',
        left:     '37%',
        top:      '48%',
        width:    '22%',
        height:   1,
        background: 'linear-gradient(90deg, rgba(0,230,118,0.3) 0%, rgba(0,230,118,0) 100%)',
        zIndex:   0,
        filter:   'blur(1px)',
        animation:'nx-beam 2.4s ease-in-out infinite 0.3s',
      }} />

      {/* ── Content: left text + right mockup ── */}
      <div style={{ display: 'grid', gridTemplateColumns: '1.2fr 0.8fr', alignItems: 'center', width: '100%', maxWidth: '1050px', margin: '0 auto', padding: '0 2rem', gap: '5rem', position: 'relative', zIndex: 10 }}>

        {/* Left: typographic column */}
        <div ref={leftRef} style={{ maxWidth: 480 }}>
          {/* Eyebrow */}
          <div style={{ opacity: 0 }}>
            <span style={{
              fontFamily:    "'JetBrains Mono', monospace",
              fontSize:      '0.63rem',
              fontWeight:    400,
              letterSpacing: '0.2em',
              textTransform: 'uppercase',
              color:         '#00e676',
              border:        '1px solid rgba(0,230,118,0.28)',
              borderRadius:  '999px',
              padding:       '0.3rem 0.85rem',
              background:    'rgba(0,230,118,0.05)',
              display:       'inline-block',
              marginBottom:  '1.6rem',
            }}>
              Ecosistema
            </span>
          </div>

          {/* Heading */}
          <div style={{ opacity: 0 }}>
            <h2 style={{
              fontFamily:   "'Plus Jakarta Sans', 'Outfit', sans-serif",
              fontWeight:    800,
              fontSize:     'clamp(2.4rem, 4.5vw, 3.8rem)',
              lineHeight:    1.05,
              letterSpacing: '-0.025em',
              color:         '#ffffff',
              margin:        '0 0 1.2rem',
            }}>
              La Operación<br />en tu Mano
            </h2>
          </div>

          {/* Body */}
          <div style={{ opacity: 0 }}>
            <p style={{
              fontFamily: "'Plus Jakarta Sans', sans-serif",
              fontWeight:  400,
              fontSize:   'clamp(0.88rem, 1.3vw, 1rem)',
              lineHeight:  1.7,
              color:      '#8da898',
              margin:     0,
              maxWidth:   '40ch',
            }}>
              Gestiona notificaciones, citaciones y control de custodia
              en tiempo real. Los acudientes reciben alertas instantáneas
              con total trazabilidad desde el nodo.
            </p>
          </div>
        </div>

        {/* Right: phone mockup */}
        <div ref={rightRef} style={{ opacity: 0, display: 'flex', justifyContent: 'center' }}>
          <AppMockup />
        </div>
      </div>

      <style>{`
        @keyframes nx-beam {
          0%   { opacity: 0; transform: translateY(-50%) scaleX(0); transform-origin: left; }
          30%  { opacity: 1; transform: translateY(-50%) scaleX(1); transform-origin: left; }
          70%  { opacity: 1; transform: translateY(-50%) scaleX(1); transform-origin: right; }
          100% { opacity: 0; transform: translateY(-50%) scaleX(0); transform-origin: right; }
        }
      `}</style>
    </section>
  )
}
