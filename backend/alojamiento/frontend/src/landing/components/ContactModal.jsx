import { useRef, useState, useEffect } from 'react'
import gsap from 'gsap'

// CAMBIO 5: Modal de contacto
// CTA: "Quiero que NEXO llegue a mi institución"
// Validación inline, console.log con JSON, estado de carga y éxito.

const CARGO_OPTIONS = [
  { value: '',             label: 'Selecciona tu cargo' },
  { value: 'rector',       label: 'Rector' },
  { value: 'coordinador',  label: 'Coordinador' },
  { value: 'docente',      label: 'Docente' },
  { value: 'secretaria',   label: 'Secretaría de Educación' },
  { value: 'otro',         label: 'Otro' },
]

function validate(data) {
  const errors = {}
  if (!data.nombre.trim())      errors.nombre    = 'El nombre es obligatorio.'
  if (!data.cargo)              errors.cargo     = 'Selecciona tu cargo.'
  if (!data.institucion.trim()) errors.institucion = 'El nombre de la institución es obligatorio.'
  if (!data.municipio.trim())   errors.municipio = 'El municipio y departamento son obligatorios.'

  const emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  if (!data.email.trim())           errors.email = 'El correo es obligatorio.'
  else if (!emailRe.test(data.email)) errors.email = 'Ingresa un correo electrónico válido.'

  const digits = data.whatsapp.replace(/\D/g, '')
  if (!data.whatsapp.trim()) errors.whatsapp = 'El WhatsApp es obligatorio.'
  else if (digits.length < 10) errors.whatsapp = 'El WhatsApp debe tener mínimo 10 dígitos.'

  return errors
}

const INITIAL = { nombre: '', cargo: '', institucion: '', municipio: '', email: '', whatsapp: '', mensaje: '' }

export default function ContactModal({ onClose }) {
  const overlayRef = useRef()
  const panelRef   = useRef()
  const [form, setForm]     = useState(INITIAL)
  const [errors, setErrors] = useState({})
  const [status, setStatus] = useState('idle') // idle | loading | success

  // CAMBIO 5: Animación de entrada — escala 0.92 → 1.0 + fade in, power3.out
  useEffect(() => {
    const ctx = gsap.context(() => {
      gsap.fromTo(overlayRef.current,
        { opacity: 0 },
        { opacity: 1, duration: 0.25, ease: 'power2.out' }
      )
      gsap.fromTo(panelRef.current,
        { scale: 0.92, opacity: 0, y: 24 },
        { scale: 1, opacity: 1, y: 0, duration: 0.35, ease: 'power3.out', delay: 0.05 }
      )
    })
    return () => ctx.revert()
  }, [])

  // Cerrar con animación de salida
  const handleClose = () => {
    gsap.to(panelRef.current,   { scale: 0.94, opacity: 0, y: 16, duration: 0.22, ease: 'power2.in' })
    gsap.to(overlayRef.current, { opacity: 0, duration: 0.28, ease: 'power2.in', onComplete: onClose })
  }

  // Cerrar con ESC
  useEffect(() => {
    const handler = (e) => { if (e.key === 'Escape') handleClose() }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])

  // Prevenir scroll del body mientras el modal está abierto
  useEffect(() => {
    const scrollY = window.scrollY
    document.body.style.position = 'fixed'
    document.body.style.top = `-${scrollY}px`
    document.body.style.width = '100%'
    return () => {
      const saved = document.documentElement.style.scrollBehavior
      document.documentElement.style.scrollBehavior = 'auto'
      document.body.style.position = ''
      document.body.style.top = ''
      document.body.style.width = ''
      window.scrollTo(0, scrollY)
      document.documentElement.style.scrollBehavior = saved
    }
  }, [])

  const set = (field) => (e) => setForm(f => ({ ...f, [field]: e.target.value }))

  const handleSubmit = async (e) => {
    e.preventDefault()
    const errs = validate(form)
    setErrors(errs)
    if (Object.keys(errs).length > 0) return

    setStatus('loading')

    // TODO: BACKEND — Reemplazar este bloque con la llamada al API real.
    // Ejemplo: await fetch('/api/contacto', { method: 'POST', body: JSON.stringify(form) })
    console.log('[NEXO] Solicitud de contacto:', JSON.stringify(form, null, 2))

    // Simula latencia de red
    await new Promise(r => setTimeout(r, 1200))
    setStatus('success')
  }

  const inputStyle = (field) => ({
    width:           '100%',
    background:      'var(--nx-void)',
    border:          `1px solid ${errors[field] ? 'rgba(255,80,80,0.6)' : 'var(--nx-border)'}`,
    borderRadius:    '0.625rem',
    padding:         '0.75rem 1rem',
    fontSize:        '0.875rem',
    color:           'var(--nx-white)',
    outline:         'none',
    fontFamily:      'inherit',
    transition:      'border-color 0.2s',
    boxSizing:       'border-box',
  })

  return (
    <div
      ref={overlayRef}
      onClick={handleClose}
      style={{
        position:       'fixed',
        inset:          0,
        zIndex:         10000,
        background:     'rgba(0,0,0,0.75)',
        backdropFilter: 'blur(12px)',
        WebkitBackdropFilter: 'blur(12px)',
        display:        'flex',
        alignItems:     'center',
        justifyContent: 'center',
        padding:        '1.5rem',
      }}
      aria-modal="true"
      role="dialog"
      aria-label="Formulario de contacto NEXO"
    >
      <div
        ref={panelRef}
        className="nx-modal-panel"
        onClick={e => e.stopPropagation()}
        style={{
          background:   'var(--nx-deep)',
          border:       '1px solid var(--nx-border)',
          borderRadius: '1.25rem',
          width:        '100%',
          maxWidth:     '560px',
          maxHeight:    '90vh',
          overflowY:    'auto',
          padding:      '2.5rem',
          position:     'relative',
          willChange:   'transform, opacity',
        }}
      >
        {/* Botón de cierre */}
        <button
          onClick={handleClose}
          aria-label="Cerrar"
          style={{
            position:  'absolute',
            top:       '1.25rem',
            right:     '1.25rem',
            background:'none',
            border:    '1px solid var(--nx-border)',
            borderRadius: '50%',
            width:     '32px',
            height:    '32px',
            cursor:    'pointer',
            display:   'flex',
            alignItems:'center',
            justifyContent: 'center',
            color:     'var(--nx-muted)',
            transition: 'border-color 0.2s, color 0.2s',
          }}
          onMouseEnter={e => { e.currentTarget.style.borderColor = 'var(--nx-text)'; e.currentTarget.style.color = 'var(--nx-white)' }}
          onMouseLeave={e => { e.currentTarget.style.borderColor = 'var(--nx-border)'; e.currentTarget.style.color = 'var(--nx-muted)' }}
        >
          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
            <path d="M2 2l10 10M12 2L2 12"/>
          </svg>
        </button>

        {status === 'success' ? (
          /* ── Estado de éxito ── */
          <div style={{ textAlign: 'center', padding: '2rem 0' }}>
            {/* Ícono check animado */}
            <svg width="56" height="56" viewBox="0 0 56 56" fill="none" style={{ margin: '0 auto 1.5rem', display: 'block', animation: 'successPop 0.5s cubic-bezier(0.175,0.885,0.32,1.275)' }}>
              <circle cx="28" cy="28" r="26" stroke="var(--nx-green)" strokeWidth="1.5"/>
              <path d="M18 28l7 7 14-14" stroke="var(--nx-green)" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
            </svg>
            <h3 style={{ fontSize: '1.1rem', fontWeight: 800, color: 'var(--nx-white)', marginBottom: '0.75rem' }}>
              Tu solicitud fue recibida.
            </h3>
            <p style={{ fontSize: '0.875rem', color: 'var(--nx-muted)', lineHeight: 1.6 }}>
              Nos comunicaremos contigo en menos de 24 horas.
            </p>
          </div>
        ) : (
          /* ── Formulario ── */
          <>
            <div style={{ marginBottom: '2rem' }}>
              <div className="nx-eyebrow" style={{ marginBottom: '0.75rem' }}>Contacto</div>
              <h3 style={{ fontSize: '1.25rem', fontWeight: 800, color: 'var(--nx-white)', lineHeight: 1.2 }}>
                Quiero que NEXO llegue a mi institución
              </h3>
            </div>

            <form onSubmit={handleSubmit} noValidate style={{ display: 'flex', flexDirection: 'column', gap: '1.1rem' }}>
              {/* 1. Nombre completo */}
              <div>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--nx-text)', marginBottom: '0.4rem' }}>
                  Nombre completo *
                </label>
                <input type="text" value={form.nombre} onChange={set('nombre')}
                  placeholder="Tu nombre completo"
                  style={inputStyle('nombre')}
                  onFocus={e => e.target.style.borderColor = 'rgba(45, 110, 48, 0.5)'}
                  onBlur={e => e.target.style.borderColor = errors.nombre ? 'rgba(255,80,80,0.6)' : 'var(--nx-border)'}
                />
                {errors.nombre && <p style={{ fontSize: '0.72rem', color: '#ff7070', marginTop: '0.3rem' }}>{errors.nombre}</p>}
              </div>

              {/* 2. Cargo */}
              <div>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--nx-text)', marginBottom: '0.4rem' }}>
                  Cargo *
                </label>
                <select value={form.cargo} onChange={set('cargo')}
                  style={{ ...inputStyle('cargo'), appearance: 'none', cursor: 'pointer' }}>
                  {CARGO_OPTIONS.map(o => (
                    <option key={o.value} value={o.value} style={{ background: '#f7fcf7' }}>{o.label}</option>
                  ))}
                </select>
                {errors.cargo && <p style={{ fontSize: '0.72rem', color: '#ff7070', marginTop: '0.3rem' }}>{errors.cargo}</p>}
              </div>

              {/* 3. Institución educativa */}
              <div>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--nx-text)', marginBottom: '0.4rem' }}>
                  Nombre de la institución *
                </label>
                <input type="text" value={form.institucion} onChange={set('institucion')}
                  placeholder="I.E. San Carlos, Colegio..."
                  style={inputStyle('institucion')}
                  onFocus={e => e.target.style.borderColor = 'rgba(45, 110, 48, 0.5)'}
                  onBlur={e => e.target.style.borderColor = errors.institucion ? 'rgba(255,80,80,0.6)' : 'var(--nx-border)'}
                />
                {errors.institucion && <p style={{ fontSize: '0.72rem', color: '#ff7070', marginTop: '0.3rem' }}>{errors.institucion}</p>}
              </div>

              {/* 4. Municipio y departamento */}
              <div>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--nx-text)', marginBottom: '0.4rem' }}>
                  Municipio y departamento *
                </label>
                <input type="text" value={form.municipio} onChange={set('municipio')}
                  placeholder="Medellín, Antioquia"
                  style={inputStyle('municipio')}
                  onFocus={e => e.target.style.borderColor = 'rgba(45, 110, 48, 0.5)'}
                  onBlur={e => e.target.style.borderColor = errors.municipio ? 'rgba(255,80,80,0.6)' : 'var(--nx-border)'}
                />
                {errors.municipio && <p style={{ fontSize: '0.72rem', color: '#ff7070', marginTop: '0.3rem' }}>{errors.municipio}</p>}
              </div>

              {/* 5. Correo electrónico */}
              <div>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--nx-text)', marginBottom: '0.4rem' }}>
                  Correo electrónico institucional *
                </label>
                <input type="email" value={form.email} onChange={set('email')}
                  placeholder="nombre@institución.edu.co"
                  style={inputStyle('email')}
                  onFocus={e => e.target.style.borderColor = 'rgba(45, 110, 48, 0.5)'}
                  onBlur={e => e.target.style.borderColor = errors.email ? 'rgba(255,80,80,0.6)' : 'var(--nx-border)'}
                />
                {errors.email && <p style={{ fontSize: '0.72rem', color: '#ff7070', marginTop: '0.3rem' }}>{errors.email}</p>}
              </div>

              {/* 6. WhatsApp */}
              <div>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--nx-text)', marginBottom: '0.4rem' }}>
                  WhatsApp de contacto *
                </label>
                <input type="tel" value={form.whatsapp} onChange={set('whatsapp')}
                  placeholder="+57 310 000 0000"
                  style={inputStyle('whatsapp')}
                  onFocus={e => e.target.style.borderColor = 'rgba(45, 110, 48, 0.5)'}
                  onBlur={e => e.target.style.borderColor = errors.whatsapp ? 'rgba(255,80,80,0.6)' : 'var(--nx-border)'}
                />
                {errors.whatsapp && <p style={{ fontSize: '0.72rem', color: '#ff7070', marginTop: '0.3rem' }}>{errors.whatsapp}</p>}
              </div>

              {/* 7. Mensaje opcional */}
              <div>
                <label style={{ display: 'block', fontSize: '0.78rem', fontWeight: 600, color: 'var(--nx-text)', marginBottom: '0.4rem' }}>
                  ¿Algo que quieras contarnos?
                  <span style={{ fontWeight: 400, color: 'var(--nx-muted)', marginLeft: '0.4rem' }}>(opcional)</span>
                </label>
                <textarea
                  value={form.mensaje}
                  onChange={e => {
                    if (e.target.value.length <= 300) set('mensaje')(e)
                  }}
                  placeholder="Cuéntanos el contexto de tu institución..."
                  rows={3}
                  style={{ ...inputStyle('mensaje'), resize: 'vertical', minHeight: '80px' }}
                  onFocus={e => e.target.style.borderColor = 'rgba(45, 110, 48, 0.5)'}
                  onBlur={e => e.target.style.borderColor = 'var(--nx-border)'}
                />
                <p style={{ fontSize: '0.68rem', color: 'var(--nx-muted-2)', marginTop: '0.25rem', textAlign: 'right' }}>
                  {form.mensaje.length}/300
                </p>
              </div>

              {/* Submit */}
              <button
                type="submit"
                disabled={status === 'loading'}
                className="nx-btn-primary"
                style={{
                  width:      '100%',
                  padding:    '0.9rem',
                  fontSize:   '0.9rem',
                  marginTop:  '0.5rem',
                  opacity:    status === 'loading' ? 0.75 : 1,
                  cursor:     status === 'loading' ? 'not-allowed' : 'pointer',
                  display:    'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap:        '0.6rem',
                }}
              >
                {status === 'loading' ? (
                  <>
                    {/* Spinner SVG */}
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" style={{ animation: 'spinnerRot 0.8s linear infinite' }}>
                      <circle cx="8" cy="8" r="6" stroke="rgba(255,255,255,0.25)" strokeWidth="2"/>
                      <path d="M8 2a6 6 0 016 6" stroke="white" strokeWidth="2" strokeLinecap="round"/>
                    </svg>
                    Enviando...
                  </>
                ) : 'Enviar solicitud'}
              </button>

              <p style={{ fontSize: '0.7rem', color: 'var(--nx-muted-2)', textAlign: 'center' }}>
                Tus datos son tratados conforme a la Ley 1581 de protección de datos personales.
              </p>
            </form>
          </>
        )}
      </div>

      <style>{`
        @keyframes successPop {
          from { transform: scale(0.5); opacity: 0; }
          to   { transform: scale(1);   opacity: 1; }
        }
        @keyframes spinnerRot {
          from { transform: rotate(0deg); }
          to   { transform: rotate(360deg); }
        }
        /* Scroll del modal en móvil */
        @media (max-width: 560px) {
          [aria-label="Formulario de contacto NEXO"] > div {
            padding: 1.75rem 1.25rem !important;
            max-height: 95vh !important;
          }
        }

        @media (max-width: 768px) {
          .nx-modal-panel {
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
            top: auto !important;
            transform: none !important;
            max-width: 100% !important;
            width: 100% !important;
            border-radius: 1.25rem 1.25rem 0 0 !important;
            max-height: 92svh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
          }
        }
      `}</style>
    </div>
  )
}
