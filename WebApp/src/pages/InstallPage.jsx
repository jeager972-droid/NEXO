import { useState, useEffect } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { Download, CheckCircle, Globe, AlertCircle, RefreshCw } from 'lucide-react'

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

// Detectar si es Chrome/Edge/Brave/Vivaldi desktop
function isChromeDesktop() {
  const ua = navigator.userAgent
  const isChromium = /Chrome|Edg|Brave|Vivaldi/i.test(ua)
  const isMobileUA = /Android|iPhone|iPad|iPod/i.test(ua)
  return isChromium && !isMobileUA
}

const PLATFORMS = {
  android: { title: 'Android', icon: 'smartphone' },
  ios:     { title: 'iPhone / iPad', icon: 'smartphone' },
  windows: { title: 'Windows', icon: 'monitor' },
  mac:     { title: 'Mac', icon: 'monitor' },
  linux:   { title: 'Linux', icon: 'monitor' },
}

export default function InstallPage() {
  const { platform } = useParams()
  const navigate = useNavigate()
  const [deferredPrompt, setDeferredPrompt] = useState(null)
  const [installed, setInstalled] = useState(false)
  const [browser, setBrowser] = useState(null)
  const [mobile, setMobile] = useState(false)
  const [showManualInstall, setShowManualInstall] = useState(false)
  const [showInstructions, setShowInstructions] = useState(false)

  useEffect(() => {
    if (!PLATFORMS[platform]) navigate('/login')
    setBrowser(detectBrowser())
    setMobile(isMobile())
  }, [platform, navigate])

  useEffect(() => {
    // Recuperar prompt capturado globalmente si ya se disparó
    if (window.__nexoPwaPrompt) {
      setDeferredPrompt(window.__nexoPwaPrompt)
    }

    const handler = (e) => {
      e.preventDefault()
      window.__nexoPwaPrompt = e
      setDeferredPrompt(e)
    }
    window.addEventListener('beforeinstallprompt', handler)
    window.addEventListener('appinstalled', () => {
      setInstalled(true)
      sessionStorage.removeItem('pwaPromptAvailable')
    })
    return () => {
      window.removeEventListener('beforeinstallprompt', handler)
    }
  }, [])

  useEffect(() => {
    // Intentar recuperar el prompt después de 3s si aún no está disponible
    const timer = setTimeout(() => {
      if (!deferredPrompt && window.__nexoPwaPrompt) {
        setDeferredPrompt(window.__nexoPwaPrompt)
      }
    }, 3000)
    return () => clearTimeout(timer)
  }, [deferredPrompt])

  useEffect(() => {
    // Para android/windows/mac/linux: después de 2s, si no hay prompt y no hay flag,
    // mostrar botón con instrucciones manuales
    if (platform === 'ios') return
    
    const timer = setTimeout(() => {
      const promptAvailable = sessionStorage.getItem('pwaPromptAvailable') === 'true'
      if (!deferredPrompt && !promptAvailable) {
        setShowManualInstall(true)
      }
    }, 2000)
    return () => clearTimeout(timer)
  }, [platform, deferredPrompt])

  const handleInstall = async () => {
    if (!deferredPrompt) return
    deferredPrompt.prompt()
    const { outcome } = await deferredPrompt.userChoice
    if (outcome === 'accepted') setInstalled(true)
    setDeferredPrompt(null)
    window.__nexoPwaPrompt = null
    sessionStorage.removeItem('pwaPromptAvailable')
  }

  const handleReload = () => {
    window.location.reload()
  }

  const content = PLATFORMS[platform]
  if (!content) return null

  // Determinar qué mostrar según plataforma y navegador
  const isIos = platform === 'ios'
  const isSafari = browser === 'safari'
  const isFirefox = browser === 'firefox'
  const canInstallNatively = !!deferredPrompt
  const needsSafari = isIos && !isSafari
  const promptWasAvailable = sessionStorage.getItem('pwaPromptAvailable') === 'true'
  const promptLost = promptWasAvailable && !deferredPrompt
  const isChromiumDesktop = isChromeDesktop()

  // Instrucciones manuales para iOS Safari (único caso que las necesita)
  const iosSteps = [
    'Abre esta página en Safari',
    'Toca el botón compartir (□↑) en la barra inferior',
    'Selecciona "Añadir a pantalla de inicio"',
    'Toca "Añadir" para confirmar',
  ]

  return (
    <>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap');
        
        @keyframes fadeUp {
          from { opacity: 0; transform: translateY(24px); }
          to   { opacity: 1; transform: translateY(0); }
        }

        .install-page * {
          font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .install-card {
          animation: fadeUp 0.6s cubic-bezier(0.16,1,0.3,1) both;
        }

        .btn-primary {
          transition: all 0.25s cubic-bezier(0.16,1,0.3,1);
        }

        .btn-primary:hover {
          background: #2d6e30 !important;
          transform: translateY(-1px);
          box-shadow: 0 8px 32px rgba(45,110,48,0.28);
        }

        .btn-ghost {
          transition: all 0.2s ease;
        }

        .btn-ghost:hover {
          background: #edf7ed !important;
          border-color: #2d6e30 !important;
        }
      `}</style>

      <div className="install-page" style={{
        minHeight: '100vh',
        background: 'linear-gradient(160deg, #ffffff 0%, #f0f9f0 55%, #dff0df 100%)',
        position: 'relative',
      }}>
        {/* Header fijo tipo navbar */}
        <header style={{
          position: 'fixed',
          top: 0,
          left: 0,
          right: 0,
          zIndex: 100,
          background: 'rgba(255,255,255,0.85)',
          backdropFilter: 'blur(16px)',
          borderBottom: '1px solid rgba(45,110,48,0.12)',
          padding: '1rem',
        }}>
          <div style={{
            textAlign: 'center',
            fontSize: '1.5rem',
            fontWeight: '800',
            letterSpacing: '-0.02em',
            color: '#0f2d12',
          }}>
            NEXO
          </div>
        </header>

        {/* Contenido principal */}
        <div style={{
          minHeight: '100vh',
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          padding: '6rem 1rem 3rem',
        }}>
          <div className="install-card" style={{
            maxWidth: '560px',
            width: '100%',
            background: '#ffffff',
            borderRadius: '1.5rem',
            border: '1px solid rgba(45,110,48,0.18)',
            boxShadow: '0 4px 20px rgba(15,45,18,0.07)',
            padding: '2.5rem 2rem',
          }}>
            {/* Eyebrow tag */}
            <div style={{
              textAlign: 'center',
              fontSize: '0.65rem',
              textTransform: 'uppercase',
              letterSpacing: '0.18em',
              color: '#2d6e30',
              fontWeight: '600',
              marginBottom: '0.75rem',
            }}>
              ● {content.title}
            </div>

            {/* Título */}
            <h1 style={{
              fontSize: 'clamp(1.8rem, 3.5vw, 2.4rem)',
              fontWeight: '800',
              color: '#0f2d12',
              letterSpacing: '-0.02em',
              textAlign: 'center',
              margin: '0 0 0.5rem 0',
              lineHeight: 1.2,
            }}>
              Instala NEXO
            </h1>

            {/* Subtítulo */}
            <p style={{
              fontSize: '1rem',
              color: '#4a6e4c',
              textAlign: 'center',
              lineHeight: 1.75,
              margin: '0 0 2rem 0',
            }}>
              Accede como app nativa desde tu dispositivo
            </p>

            {/* Estado: ya instalado */}
            {installed && (
              <div style={{
                background: '#edf7ed',
                border: '1px solid rgba(45,110,48,0.3)',
                borderRadius: '1rem',
                padding: '1.25rem',
                display: 'flex',
                alignItems: 'center',
                gap: '0.875rem',
                marginBottom: '1.5rem',
              }}>
                <CheckCircle size={24} color="#2d6e30" strokeWidth={2.5} />
                <div>
                  <p style={{ margin: 0, fontWeight: '700', color: '#0f2d12', fontSize: '0.95rem' }}>
                    ¡NEXO instalado!
                  </p>
                  <p style={{ margin: 0, fontSize: '0.85rem', color: '#4a6e4c' }}>
                    Ábrela desde tu pantalla de inicio
                  </p>
                </div>
              </div>
            )}

            {/* CASO 1: Puede instalar con botón nativo */}
            {canInstallNatively && !installed && (
              <div style={{ marginBottom: '1.5rem' }}>
                <p style={{
                  fontSize: '0.9rem',
                  color: '#4a6e4c',
                  textAlign: 'center',
                  marginBottom: '1rem',
                  lineHeight: 1.6,
                }}>
                  Tu navegador soporta instalación directa. Un solo clic:
                </p>
                <button
                  onClick={handleInstall}
                  className="btn-primary"
                  style={{
                    width: '100%',
                    background: '#1a4a1f',
                    color: 'white',
                    padding: '0.85rem 2rem',
                    borderRadius: '100px',
                    border: 'none',
                    fontSize: '0.95rem',
                    fontWeight: '700',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: '0.5rem',
                  }}
                >
                  <Download size={18} strokeWidth={2.5} />
                  Instalar NEXO ahora
                </button>
              </div>
            )}

            {/* CASO 2: iOS — necesita Safari */}
            {isIos && needsSafari && (
              <div style={{
                background: 'rgba(45,110,48,0.06)',
                border: '1px solid rgba(45,110,48,0.2)',
                borderRadius: '1rem',
                padding: '1rem 1.25rem',
                marginBottom: '1.5rem',
              }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: '0.75rem' }}>
                  <AlertCircle size={20} color="#2d6e30" strokeWidth={2.5} style={{ marginTop: '0.15rem', flexShrink: 0 }} />
                  <div>
                    <p style={{
                      margin: '0 0 0.25rem 0',
                      fontWeight: '700',
                      color: '#2d5e30',
                      fontSize: '0.9rem',
                    }}>
                      Abre esta página en Safari
                    </p>
                    <p style={{ margin: 0, fontSize: '0.85rem', color: '#4a6e4c', lineHeight: 1.6 }}>
                      iOS solo permite instalar PWAs desde Safari. Copia la URL y ábrela en Safari.
                    </p>
                  </div>
                </div>
              </div>
            )}

            {/* CASO 3: iOS en Safari — pasos manuales */}
            {isIos && isSafari && !installed && (
              <div style={{ marginBottom: '1.5rem' }}>
                {iosSteps.map((step, idx) => (
                  <div
                    key={idx}
                    style={{
                      display: 'flex',
                      gap: '1rem',
                      alignItems: 'flex-start',
                      marginBottom: idx < iosSteps.length - 1 ? '1rem' : 0,
                    }}
                  >
                    <div style={{
                      minWidth: '40px',
                      height: '40px',
                      borderRadius: '50%',
                      background: 'rgba(45,110,48,0.08)',
                      border: '1px solid rgba(45,110,48,0.2)',
                      color: '#2d6e30',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      fontSize: '0.95rem',
                      fontWeight: '700',
                      flexShrink: 0,
                    }}>
                      {idx + 1}
                    </div>
                    <p style={{
                      fontSize: '0.95rem',
                      color: '#0f2d12',
                      margin: '0.6rem 0 0 0',
                      lineHeight: 1.6,
                    }}>
                      {step}
                    </p>
                  </div>
                ))}
              </div>
            )}

            {/* CASO 4: Firefox — no soporta PWA */}
            {isFirefox && !isIos && !canInstallNatively && (
              <div style={{
                background: 'rgba(45,110,48,0.06)',
                border: '1px solid rgba(45,110,48,0.2)',
                borderRadius: '1rem',
                padding: '1rem 1.25rem',
                marginBottom: '1.5rem',
              }}>
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: '0.75rem' }}>
                  <AlertCircle size={20} color="#2d6e30" strokeWidth={2.5} style={{ marginTop: '0.15rem', flexShrink: 0 }} />
                  <div>
                    <p style={{
                      margin: '0 0 0.25rem 0',
                      fontWeight: '700',
                      color: '#2d5e30',
                      fontSize: '0.9rem',
                    }}>
                      Firefox no soporta instalación PWA
                    </p>
                    <p style={{ margin: 0, fontSize: '0.85rem', color: '#4a6e4c', lineHeight: 1.6 }}>
                      Abre esta página en Chrome, Edge, Brave o Vivaldi para instalar NEXO.
                    </p>
                  </div>
                </div>
              </div>
            )}

            {/* CASO 5A: Prompt se perdió pero estaba disponible — botón de recarga */}
            {promptLost && !installed && (
              <div style={{ marginBottom: '1.5rem' }}>
                <div style={{
                  background: 'rgba(45,110,48,0.06)',
                  border: '1px solid rgba(45,110,48,0.2)',
                  borderRadius: '1rem',
                  padding: '1rem 1.25rem',
                  marginBottom: '1rem',
                }}>
                  <p style={{
                    margin: '0 0 0.25rem 0',
                    fontWeight: '700',
                    color: '#2d5e30',
                    fontSize: '0.9rem',
                  }}>
                    Instalación disponible
                  </p>
                  <p style={{ margin: 0, fontSize: '0.85rem', color: '#4a6e4c', lineHeight: 1.6 }}>
                    Tu navegador puede instalar NEXO. Recarga la página para activar el botón de instalación.
                  </p>
                </div>
                <button
                  onClick={handleReload}
                  className="btn-primary"
                  style={{
                    width: '100%',
                    background: '#1a4a1f',
                    color: 'white',
                    padding: '0.85rem 2rem',
                    borderRadius: '100px',
                    border: 'none',
                    fontSize: '0.95rem',
                    fontWeight: '700',
                    cursor: 'pointer',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    gap: '0.5rem',
                  }}
                >
                  <RefreshCw size={18} strokeWidth={2.5} />
                  Recargar para instalar
                </button>
              </div>
            )}

            {/* CASO 5B: android/windows/mac/linux — botón directo con instrucciones */}
            {!canInstallNatively && !promptLost && !isIos && !isFirefox && !installed && showManualInstall && (
              <div style={{ marginBottom: '1.5rem' }}>
                {!showInstructions ? (
                  <>
                    <div style={{
                      background: 'rgba(45,110,48,0.06)',
                      border: '1px solid rgba(45,110,48,0.2)',
                      borderRadius: '1rem',
                      padding: '1rem 1.25rem',
                      marginBottom: '1rem',
                    }}>
                      <p style={{
                        margin: '0 0 0.5rem 0',
                        fontWeight: '700',
                        color: '#2d5e30',
                        fontSize: '0.9rem',
                      }}>
                        ¿No ves el botón de instalación?
                      </p>
                      <p style={{ margin: 0, fontSize: '0.85rem', color: '#4a6e4c', lineHeight: 1.6 }}>
                        Tu navegador puede instalar NEXO. Busca el ícono <strong>⊕</strong> en la barra de direcciones.
                      </p>
                    </div>
                    <button
                      onClick={() => setShowInstructions(true)}
                      className="btn-primary"
                      style={{
                        width: '100%',
                        background: '#1a4a1f',
                        color: 'white',
                        padding: '0.85rem 2rem',
                        borderRadius: '100px',
                        border: 'none',
                        fontSize: '0.95rem',
                        fontWeight: '700',
                        cursor: 'pointer',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        gap: '0.5rem',
                      }}
                    >
                      <Download size={18} strokeWidth={2.5} />
                      Ver instrucciones de instalación
                    </button>
                  </>
                ) : (
                  <div style={{
                    background: 'rgba(45,110,48,0.06)',
                    border: '1px solid rgba(45,110,48,0.2)',
                    borderRadius: '1rem',
                    padding: '1rem 1.25rem',
                    marginBottom: '1.5rem',
                  }}>
                    <div style={{ display: 'flex', alignItems: 'flex-start', gap: '0.75rem' }}>
                      <AlertCircle size={20} color="#2d6e30" strokeWidth={2.5} style={{ marginTop: '0.15rem', flexShrink: 0 }} />
                      <div>
                        <p style={{
                          margin: '0 0 0.25rem 0',
                          fontWeight: '700',
                          color: '#2d5e30',
                          fontSize: '0.9rem',
                        }}>
                          Busca el ícono de instalación
                        </p>
                        <p style={{ margin: 0, fontSize: '0.85rem', color: '#4a6e4c', lineHeight: 1.6 }}>
                          Busca el ícono <strong>⊕</strong> en la barra de direcciones de tu navegador y haz clic para instalar NEXO.
                        </p>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            )}


            {/* Botón secundario */}
            {!installed && (
              <Link
                to="/login"
                className="btn-ghost"
                style={{
                  width: '100%',
                  background: 'transparent',
                  color: '#1a4a1f',
                  padding: '0.85rem 2rem',
                  borderRadius: '100px',
                  border: '1px solid #2d6e30',
                  fontSize: '0.9rem',
                  fontWeight: '700',
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '0.5rem',
                  textDecoration: 'none',
                }}
              >
                <Globe size={18} strokeWidth={2.5} />
                Abrir NEXO en el navegador
              </Link>
            )}

            {installed && (
              <Link
                to="/login"
                className="btn-primary"
                style={{
                  width: '100%',
                  background: '#1a4a1f',
                  color: 'white',
                  padding: '0.85rem 2rem',
                  borderRadius: '100px',
                  border: 'none',
                  fontSize: '0.9rem',
                  fontWeight: '700',
                  cursor: 'pointer',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: '0.5rem',
                  textDecoration: 'none',
                }}
              >
                Ir al login →
              </Link>
            )}
          </div>

          {/* Footer micro-copy */}
          <p style={{
            fontSize: '0.75rem',
            color: '#6b9e6e',
            textAlign: 'center',
            marginTop: '2rem',
            letterSpacing: '0.05em',
          }}>
            Solo disponible para instituciones vinculadas
          </p>
        </div>
      </div>
    </>
  )
}
