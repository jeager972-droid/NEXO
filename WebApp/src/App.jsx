/**
 * WebApp router / NEXO Institucional
 * Responsabilidad: Definir las rutas de la SPA, proteger con ProtectedRoute, aplicar
 * lazy-loading de páginas, inicializar telemetría, capturar beforeinstallprompt PWA
 * y manejar deep links de Tauri. Envuelve rutas autenticadas con Layout y ErrorBoundary.
 * Dependencias: react-router-dom, framer-motion, AuthContext, ProtectedRoute, Layout, PwaInstallPrompt, api/telemetry.
 */
import { lazy, Suspense, useEffect } from 'react'
import { Routes, Route, Navigate, useNavigate } from 'react-router-dom'
import { motion, AnimatePresence } from 'framer-motion'
import { useAuth } from './hooks/useAuth'
import ProtectedRoute from './routes/ProtectedRoute'
import Layout from './layout/Layout'
import { ROLES } from './config/roles'
import PwaInstallPrompt from './components/PwaInstallPrompt'
import ErrorBoundary from './components/ErrorBoundary'
// Telemetry deshabilitado para reducir requests periódicos al backend/Redis.
// import { initTelemetry } from './api/telemetry'

// Pages
const Login = lazy(() => import('./pages/Login'))
const Dashboard = lazy(() => import('./pages/Dashboard'))
const Operation = lazy(() => import('./pages/Operation'))
const Notifications = lazy(() => import('./pages/Notifications'))
const Reports = lazy(() => import('./pages/Reports'))
const Consultation = lazy(() => import('./pages/Consultation'))
const Enrollment = lazy(() => import('./pages/Enrollment'))
const Unauthorized = lazy(() => import('./pages/Unauthorized'))
const Audit = lazy(() => import('./pages/Audit'))
const Casos = lazy(() => import('./pages/Seguimiento'))
const Downloads  = lazy(() => import('./pages/Downloads'))
const InstallPage = lazy(() => import('./pages/InstallPage'))
const Profile = lazy(() => import('./pages/Profile'))

function App() {
  const { user } = useAuth()
  const navigate = useNavigate()

  // La telemetría periódica está deshabilitada para reducir peticiones y uso de Redis.
  // useEffect(() => { initTelemetry() }, [])

  useEffect(() => {
    const handler = (e) => {
      e.preventDefault()
      window.__nexoPwaPrompt = e
    }
    const installedHandler = () => {
      window.__nexoPwaPrompt = null
    }
    window.addEventListener('beforeinstallprompt', handler)
    window.addEventListener('appinstalled', installedHandler)
    return () => {
      window.removeEventListener('beforeinstallprompt', handler)
      window.removeEventListener('appinstalled', installedHandler)
    }
  }, [])

  useEffect(() => {
    const setupDeepLink = async () => {
      // FIX: Solo intentar deep linking si estamos en entorno nativo (Tauri)
      if (!window.__TAURI__) {
        return; // Salir silenciosamente en navegadores web
      }
      try {
        const { onOpenUrl } = await import('@tauri-apps/plugin-deep-link')
        await onOpenUrl((urls) => {
          const url = urls[0]
          if (url.includes('nexo://login')) {
            navigate('/login')
          } else if (url.includes('nexo://operacion')) {
            navigate('/operacion')
          }
        })
      } catch (e) {
        console.warn('Deep link failed in native environment:', e)
      }
    }
    setupDeepLink()
  }, [navigate])

  const shellKey = user ? 'authenticated' : 'unauthenticated'

  return (
    <Suspense fallback={
      <div
        className="min-h-screen flex flex-col items-center justify-center gap-3"
        style={{ backgroundColor: '#003366' }}
      >
        <div
          className="text-white font-black uppercase"
          style={{ fontSize: '18px', letterSpacing: '0.3em' }}
        >
          NEXO
        </div>
        <div
          className="text-white/40 font-bold uppercase"
          style={{ fontSize: '9px', letterSpacing: '0.25em' }}
        >
          Cargando módulo…
        </div>
      </div>
    }>
      <AnimatePresence mode="wait" initial={false}>
        <motion.div
          key={shellKey}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.22, ease: 'easeInOut' }}
          style={{ minHeight: '100vh' }}
        >
          <Routes>
            <Route path="/login" element={!user ? <Login /> : <Navigate to="/" />} />
            <Route path="/unauthorized" element={<Unauthorized />} />

            <Route element={<ProtectedRoute />}>
              <Route element={<Layout />}>
                <Route path="/" element={<ErrorBoundary><Dashboard /></ErrorBoundary>} />
                <Route path="/operacion" element={<ErrorBoundary><Operation /></ErrorBoundary>} />
                <Route path="/notificaciones" element={<ErrorBoundary><Notifications /></ErrorBoundary>} />
                <Route path="/perfil" element={<ErrorBoundary><Profile /></ErrorBoundary>} />

                <Route
                  path="/consulta"
                  element={<ProtectedRoute allowedRoles={[ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR]} />}
                >
                  <Route index element={<ErrorBoundary><Consultation /></ErrorBoundary>} />
                </Route>

                <Route
                  path="/casos"
                  element={<ProtectedRoute allowedRoles={[ROLES.RECTOR, ROLES.COORDINADOR, ROLES.PSICORIENTADOR]} />}
                >
                  <Route index element={<ErrorBoundary><Casos /></ErrorBoundary>} />
                </Route>

                <Route
                  path="/auditoria"
                  element={<ProtectedRoute allowedRoles={[ROLES.RECTOR]} />}
                >
                  <Route index element={<ErrorBoundary><Audit /></ErrorBoundary>} />
                </Route>

                <Route
                  path="/informes"
                  element={<ProtectedRoute allowedRoles={[ROLES.RECTOR]} />}
                >
                  <Route index element={<ErrorBoundary><Reports /></ErrorBoundary>} />
                </Route>

                <Route
                  path="/enrolamiento"
                  element={<ProtectedRoute allowedRoles={[ROLES.SECRETARIA]} />}
                >
                  <Route index element={<ErrorBoundary><Enrollment /></ErrorBoundary>} />
                </Route>
              </Route>
            </Route>

            <Route path="/descargas" element={<Downloads />} />
            <Route path="/instalar/:platform" element={<InstallPage />} />
            <Route path="*" element={<Navigate to="/" />} />
          </Routes>
        </motion.div>
      </AnimatePresence>
      <PwaInstallPrompt />
    </Suspense>
  )
}

export default App
