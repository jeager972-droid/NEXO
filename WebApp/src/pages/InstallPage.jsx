import { useState, useEffect } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { Download, Smartphone, Monitor, Chrome, Info } from 'lucide-react'

const PLATFORM_CONTENT = {
  android: {
    title: 'Instala NEXO en Android',
    icon: <Smartphone className="w-16 h-16" />,
    color: '#3DDC84',
    steps: [
      'Abre Chrome y visita la app',
      'Toca el menú (⋮) en la esquina superior derecha',
      'Selecciona "Añadir a pantalla de inicio"',
      'Confirma tocando "Añadir"',
      'Abre NEXO desde tu pantalla de inicio'
    ],
    showPwaButton: true,
    note: null
  },
  ios: {
    title: 'Instala NEXO en iPhone o iPad',
    icon: <Smartphone className="w-16 h-16" />,
    color: '#B4B4B9',
    steps: [
      'Abre Safari (obligatorio, no Chrome)',
      'Toca el botón compartir (□↑) en la parte inferior',
      'Desplázate y toca "En el inicio"',
      'Toca "Añadir" arriba a la derecha',
      'Abre NEXO desde tu pantalla de inicio'
    ],
    showPwaButton: false,
    note: '⚠️ Solo funciona desde Safari'
  },
  windows: {
    title: 'Instala NEXO en Windows',
    icon: <Monitor className="w-16 h-16" />,
    color: '#0078D4',
    steps: [
      'Abre Chrome o Edge',
      'Visita la app',
      'Haz clic en el ícono de instalación (⊕) en la barra de direcciones',
      'Haz clic en "Instalar"',
      'NEXO aparece como app en tu escritorio'
    ],
    showPwaButton: true,
    note: null
  },
  mac: {
    title: 'Instala NEXO en Mac',
    icon: <Monitor className="w-16 h-16" />,
    color: '#B4B4B9',
    steps: [
      'Abre Chrome o Safari',
      'En Chrome: clic en ⊕ en la barra de direcciones → Instalar',
      'En Safari: menú Archivo → "Añadir al Dock"',
      'NEXO aparece en tu Dock y Launchpad'
    ],
    showPwaButton: true,
    note: null
  },
  linux: {
    title: 'Instala NEXO en Linux',
    icon: <Monitor className="w-16 h-16" />,
    color: '#FFB900',
    steps: [
      'Abre Chrome o Chromium',
      'Haz clic en el ícono de instalación (⊕) en la barra de direcciones',
      'Haz clic en "Instalar"',
      'NEXO aparece en tu menú de aplicaciones'
    ],
    showPwaButton: true,
    note: null
  }
}

export default function InstallPage() {
  const { platform } = useParams()
  const navigate = useNavigate()
  const [deferredPrompt, setDeferredPrompt] = useState(null)

  useEffect(() => {
    if (!PLATFORM_CONTENT[platform]) {
      navigate('/app/login')
    }
  }, [platform, navigate])

  useEffect(() => {
    const handler = (e) => {
      e.preventDefault()
      setDeferredPrompt(e)
    }
    window.addEventListener('beforeinstallprompt', handler)
    return () => window.removeEventListener('beforeinstallprompt', handler)
  }, [])

  const handleInstall = async () => {
    if (!deferredPrompt) return
    deferredPrompt.prompt()
    const result = await deferredPrompt.userChoice
    console.log('PWA install result:', result)
    setDeferredPrompt(null)
  }

  const content = PLATFORM_CONTENT[platform]
  if (!content) return null

  return (
    <div style={{
      minHeight: '100vh',
      background: 'linear-gradient(180deg, #003366 0%, #001a3a 100%)',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '2rem 1rem',
      position: 'relative',
      overflow: 'hidden'
    }}>
      <div style={{
        position: 'absolute',
        top: '2rem',
        fontSize: '32px',
        fontWeight: '900',
        letterSpacing: '0.3em',
        color: 'white',
        textTransform: 'uppercase'
      }}>
        NEXO
      </div>

      <div style={{
        maxWidth: '580px',
        width: '100%',
        background: 'rgba(255, 255, 255, 0.98)',
        borderRadius: '24px',
        padding: '3rem 2.5rem',
        boxShadow: '0 20px 60px rgba(0, 0, 0, 0.4)',
        marginTop: '4rem'
      }}>
        <div style={{
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          marginBottom: '2.5rem'
        }}>
          <div style={{
            color: content.color,
            marginBottom: '1rem',
            opacity: 0.9
          }}>
            {content.icon}
          </div>
          <h1 style={{
            fontSize: '28px',
            fontWeight: '800',
            color: '#003366',
            textAlign: 'center',
            margin: 0,
            lineHeight: 1.2
          }}>
            {content.title}
          </h1>
        </div>

        {content.note && (
          <div style={{
            background: '#FEF3C7',
            border: '2px solid #F59E0B',
            borderRadius: '12px',
            padding: '1rem',
            marginBottom: '2rem',
            display: 'flex',
            alignItems: 'center',
            gap: '0.75rem'
          }}>
            <Info className="w-5 h-5 text-amber-600 shrink-0" />
            <span style={{
              fontSize: '14px',
              fontWeight: '600',
              color: '#92400E'
            }}>
              {content.note}
            </span>
          </div>
        )}

        <div style={{
          display: 'flex',
          flexDirection: 'column',
          gap: '1rem',
          marginBottom: '2rem'
        }}>
          {content.steps.map((step, idx) => (
            <div key={idx} style={{
              display: 'flex',
              gap: '1rem',
              alignItems: 'flex-start'
            }}>
              <div style={{
                minWidth: '32px',
                height: '32px',
                borderRadius: '50%',
                background: '#1a4a1f',
                color: 'white',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: '14px',
                fontWeight: '700',
                flexShrink: 0
              }}>
                {idx + 1}
              </div>
              <p style={{
                fontSize: '15px',
                color: '#1f2937',
                margin: 0,
                paddingTop: '0.35rem',
                lineHeight: 1.5
              }}>
                {step}
              </p>
            </div>
          ))}
        </div>

        <div style={{
          display: 'flex',
          flexDirection: 'column',
          gap: '0.75rem'
        }}>
          {content.showPwaButton && deferredPrompt && (
            <button
              onClick={handleInstall}
              style={{
                width: '100%',
                background: '#1a4a1f',
                color: 'white',
                padding: '1rem',
                borderRadius: '12px',
                border: 'none',
                fontSize: '15px',
                fontWeight: '700',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '0.5rem',
                transition: 'transform 0.15s',
                letterSpacing: '0.02em'
              }}
              onMouseDown={(e) => e.currentTarget.style.transform = 'scale(0.98)'}
              onMouseUp={(e) => e.currentTarget.style.transform = 'scale(1)'}
              onMouseLeave={(e) => e.currentTarget.style.transform = 'scale(1)'}
            >
              <Download className="w-5 h-5" />
              Instalar NEXO ahora
            </button>
          )}

          <Link
            to="/app/login"
            style={{
              width: '100%',
              background: 'transparent',
              color: '#003366',
              padding: '1rem',
              borderRadius: '12px',
              border: '2px solid #003366',
              fontSize: '15px',
              fontWeight: '700',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              gap: '0.5rem',
              textDecoration: 'none',
              transition: 'all 0.15s',
              letterSpacing: '0.02em'
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.background = '#003366'
              e.currentTarget.style.color = 'white'
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.background = 'transparent'
              e.currentTarget.style.color = '#003366'
            }}
          >
            <Chrome className="w-5 h-5" />
            Abrir NEXO en el navegador
          </Link>
        </div>
      </div>

      <p style={{
        fontSize: '11px',
        color: 'rgba(255, 255, 255, 0.5)',
        textAlign: 'center',
        marginTop: '2rem',
        letterSpacing: '0.05em'
      }}>
        Solo disponible para instituciones vinculadas
      </p>
    </div>
  )
}
