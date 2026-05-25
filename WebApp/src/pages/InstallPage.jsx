import { useState, useEffect } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { Download, Smartphone, Monitor, Globe, Info, CheckCircle } from 'lucide-react'

// Detectar navegador
function detectBrowser() {
  const ua = navigator.userAgent
  if (/Safari/i.test(ua) && !/Chrome/i.test(ua)) return 'safari'
  if (/Chrome/i.test(ua)) return 'chrome'
  if (/Edg/i.test(ua)) return 'edge'
  if (/Firefox/i.test(ua)) return 'firefox'
  return 'other'
}

// Detectar si es móvil
function isMobile() {
  return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)
}

const PLATFORMS = {
  android: { title: 'Android', color: '#3DDC84', icon: 'smartphone' },
  ios:     { title: 'iPhone / iPad', color: '#B4B4B9', icon: 'smartphone' },
  windows: { title: 'Windows', color: '#0078D4', icon: 'monitor' },
  mac:     { title: 'Mac', color: '#B4B4B9', icon: 'monitor' },
  linux:   { title: 'Linux', color: '#FFB900', icon: 'monitor' },
}

export default function InstallPage() {
  const { platform } = useParams()
  const navigate = useNavigate()
  const [deferredPrompt, setDeferredPrompt] = useState(null)
  const [installed, setInstalled] = useState(false)
  const [browser, setBrowser] = useState(null)
  const [mobile, setMobile] = useState(false)

  useEffect(() => {
    if (!PLATFORMS[platform]) navigate('/login')
    setBrowser(detectBrowser())
    setMobile(isMobile())
  }, [platform, navigate])

  useEffect(() => {
    const handler = (e) => {
      e.preventDefault()
      setDeferredPrompt(e)
    }
    window.addEventListener('beforeinstallprompt', handler)
    window.addEventListener('appinstalled', () => setInstalled(true))
    return () => {
      window.removeEventListener('beforeinstallprompt', handler)
    }
  }, [])

  const handleInstall = async () => {
    if (!deferredPrompt) return
    deferredPrompt.prompt()
    const { outcome } = await deferredPrompt.userChoice
    if (outcome === 'accepted') setInstalled(true)
    setDeferredPrompt(null)
  }

  const content = PLATFORMS[platform]
  if (!content) return null

  // Determinar qué mostrar según plataforma y navegador
  const isIos = platform === 'ios'
  const isSafari = browser === 'safari'
  const isFirefox = browser === 'firefox'
  const canInstallNatively = !!deferredPrompt
  const needsSafari = isIos && !isSafari

  // Instrucciones manuales para iOS Safari (único caso que las necesita)
  const iosSteps = [
    'Abre esta página en Safari',
    'Toca el botón compartir (□↑) en la barra inferior',
    'Selecciona "Añadir a pantalla de inicio"',
    'Toca "Añadir" para confirmar',
  ]

  return (
    <div style={{
      minHeight: '100vh',
      background: 'linear-gradient(180deg, #003366 0%, #001a3a 100%)',
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '2rem 1rem',
    }}>
      {/* Logo */}
      <div style={{
        position: 'absolute', top: '2rem',
        fontSize: '32px', fontWeight: '900',
        letterSpacing: '0.3em', color: 'white',
        textTransform: 'uppercase'
      }}>NEXO</div>

      <div style={{
        maxWidth: '520px', width: '100%',
        background: 'rgba(255,255,255,0.98)',
        borderRadius: '24px', padding: '2.5rem 2rem',
        boxShadow: '0 20px 60px rgba(0,0,0,0.4)',
        marginTop: '4rem'
      }}>
        {/* Header */}
        <div style={{ textAlign: 'center', marginBottom: '2rem' }}>
          <div style={{ fontSize: '48px', marginBottom: '0.75rem' }}>
            {content.icon === 'smartphone' ? '📱' : '💻'}
          </div>
          <h1 style={{
            fontSize: '24px', fontWeight: '800',
            color: '#003366', margin: '0 0 0.5rem 0'
          }}>
            Instala NEXO en {content.title}
          </h1>
          <p style={{ fontSize: '14px', color: '#6b7280', margin: 0 }}>
            Accede como app nativa desde tu dispositivo
          </p>
        </div>

        {/* Estado: ya instalado */}
        {installed && (
          <div style={{
            background: '#f0fdf4', border: '2px solid #22c55e',
            borderRadius: '12px', padding: '1.25rem',
            display: 'flex', alignItems: 'center', gap: '0.75rem',
            marginBottom: '1.5rem'
          }}>
            <CheckCircle size={24} color="#16a34a" />
            <div>
              <p style={{ margin: 0, fontWeight: '700', color: '#15803d' }}>¡NEXO instalado!</p>
              <p style={{ margin: 0, fontSize: '13px', color: '#16a34a' }}>Ábrela desde tu pantalla de inicio</p>
            </div>
          </div>
        )}

        {/* CASO 1: Puede instalar con botón nativo */}
        {canInstallNatively && !installed && (
          <div style={{ marginBottom: '1.5rem' }}>
            <p style={{ fontSize: '14px', color: '#374151', textAlign: 'center', marginBottom: '1rem' }}>
              Tu navegador soporta instalación directa. Un solo clic:
            </p>
            <button onClick={handleInstall} style={{
              width: '100%', background: '#1a4a1f', color: 'white',
              padding: '1.1rem', borderRadius: '12px', border: 'none',
              fontSize: '16px', fontWeight: '700', cursor: 'pointer',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              gap: '0.5rem', letterSpacing: '0.02em'
            }}>
              <Download size={20} />
              Instalar NEXO ahora
            </button>
          </div>
        )}

        {/* CASO 2: iOS — necesita Safari */}
        {isIos && needsSafari && (
          <div style={{
            background: '#FEF3C7', border: '2px solid #F59E0B',
            borderRadius: '12px', padding: '1rem', marginBottom: '1.5rem'
          }}>
            <p style={{ margin: '0 0 0.5rem 0', fontWeight: '700', color: '#92400E', fontSize: '14px' }}>
              ⚠️ Abre esta página en Safari
            </p>
            <p style={{ margin: 0, fontSize: '13px', color: '#78350F' }}>
              iOS solo permite instalar PWAs desde Safari. Copia la URL y ábrela en Safari.
            </p>
          </div>
        )}

        {/* CASO 3: iOS en Safari — pasos manuales */}
        {isIos && isSafari && !installed && (
          <div style={{ marginBottom: '1.5rem' }}>
            {iosSteps.map((step, idx) => (
              <div key={idx} style={{
                display: 'flex', gap: '0.875rem',
                alignItems: 'flex-start', marginBottom: '0.875rem'
              }}>
                <div style={{
                  minWidth: '28px', height: '28px', borderRadius: '50%',
                  background: '#1a4a1f', color: 'white', display: 'flex',
                  alignItems: 'center', justifyContent: 'center',
                  fontSize: '13px', fontWeight: '700', flexShrink: 0
                }}>{idx + 1}</div>
                <p style={{ fontSize: '14px', color: '#1f2937', margin: '0.25rem 0 0 0', lineHeight: 1.5 }}>
                  {step}
                </p>
              </div>
            ))}
          </div>
        )}

        {/* CASO 4: Firefox — no soporta PWA */}
        {isFirefox && !isIos && !canInstallNatively && (
          <div style={{
            background: '#FEF3C7', border: '2px solid #F59E0B',
            borderRadius: '12px', padding: '1rem', marginBottom: '1.5rem'
          }}>
            <p style={{ margin: '0 0 0.25rem 0', fontWeight: '700', color: '#92400E', fontSize: '14px' }}>
              Firefox no soporta instalación PWA
            </p>
            <p style={{ margin: 0, fontSize: '13px', color: '#78350F' }}>
              Abre esta página en Chrome, Edge, Brave o Vivaldi para instalar NEXO.
            </p>
          </div>
        )}

        {/* CASO 5: Otro navegador compatible pero sin prompt aún */}
        {!canInstallNatively && !isIos && !isFirefox && !installed && (
          <div style={{
            background: '#eff6ff', border: '2px solid #93c5fd',
            borderRadius: '12px', padding: '1rem', marginBottom: '1.5rem'
          }}>
            <p style={{ margin: '0 0 0.25rem 0', fontWeight: '700', color: '#1e40af', fontSize: '14px' }}>
              Busca el ícono de instalación
            </p>
            <p style={{ margin: 0, fontSize: '13px', color: '#1d4ed8' }}>
              En la barra de direcciones de tu navegador debe aparecer un ícono ⊕ o similar. Haz clic ahí para instalar NEXO.
            </p>
          </div>
        )}

        {/* Botón secundario */}
        {!installed && (
          <Link to="/login" style={{
            width: '100%', background: 'transparent', color: '#003366',
            padding: '0.875rem', borderRadius: '12px', border: '2px solid #003366',
            fontSize: '14px', fontWeight: '700', cursor: 'pointer',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            gap: '0.5rem', textDecoration: 'none'
          }}
            onMouseEnter={e => { e.currentTarget.style.background = '#003366'; e.currentTarget.style.color = 'white' }}
            onMouseLeave={e => { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = '#003366' }}
          >
            <Globe size={18} />
            Abrir NEXO en el navegador
          </Link>
        )}

        {installed && (
          <Link to="/login" style={{
            width: '100%', background: '#1a4a1f', color: 'white',
            padding: '0.875rem', borderRadius: '12px', border: 'none',
            fontSize: '14px', fontWeight: '700', cursor: 'pointer',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            gap: '0.5rem', textDecoration: 'none'
          }}>
            Ir al login →
          </Link>
        )}
      </div>

      <p style={{
        fontSize: '11px', color: 'rgba(255,255,255,0.4)',
        textAlign: 'center', marginTop: '1.5rem', letterSpacing: '0.05em'
      }}>
        Solo disponible para instituciones vinculadas
      </p>
    </div>
  )
}
