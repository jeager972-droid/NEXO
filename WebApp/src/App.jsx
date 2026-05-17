import { lazy, Suspense, useEffect } from 'react'
import { Routes, Route, Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from './hooks/useAuth'
import ProtectedRoute from './routes/ProtectedRoute'
import Layout from './layout/Layout'
import { ROLES } from './config/roles'
import PwaInstallPrompt from './components/PwaInstallPrompt'
import ErrorBoundary from './components/ErrorBoundary'

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
const Downloads = lazy(() => import('./pages/Downloads'))

function App() {
  const { user } = useAuth()
  const navigate = useNavigate()

  useEffect(() => {
    const setupDeepLink = async () => {
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
        console.log('Deep link not supported in this environment')
      }
    }
    setupDeepLink()
  }, [navigate])

  return (
    <Suspense fallback={<div className="min-h-screen flex items-center justify-center bg-institutional-900"><div className="text-white font-black text-2xl uppercase tracking-widest animate-pulse">NEXO</div></div>}>
      <Routes>
        <Route path="/login" element={!user ? <Login /> : <Navigate to="/" />} />
        <Route path="/unauthorized" element={<Unauthorized />} />

        <Route element={<ProtectedRoute />}>
          <Route element={<Layout />}>
            <Route path="/" element={<ErrorBoundary><Dashboard /></ErrorBoundary>} />
            <Route path="/operacion" element={<ErrorBoundary><Operation /></ErrorBoundary>} />
            <Route path="/notificaciones" element={<ErrorBoundary><Notifications /></ErrorBoundary>} />

            <Route
              path="/consulta"
              element={
                <ProtectedRoute allowedRoles={Object.values(ROLES)} />
              }
            >
              <Route index element={<ErrorBoundary><Consultation /></ErrorBoundary>} />
            </Route>

            {/* Rutas específicas por rol */}
            <Route
              path="/auditoria"
              element={
                <ProtectedRoute allowedRoles={[ROLES.SUPER_RECTOR, ROLES.RECTOR]} />
              }
            >
              <Route index element={<ErrorBoundary><Audit /></ErrorBoundary>} />
            </Route>

            <Route
              path="/informes"
              element={
                <ProtectedRoute allowedRoles={[ROLES.SUPER_RECTOR, ROLES.RECTOR]} />
              }
            >
              <Route index element={<ErrorBoundary><Reports /></ErrorBoundary>} />
            </Route>

            <Route
              path="/enrolamiento"
              element={
                <ProtectedRoute allowedRoles={[ROLES.SECRETARIA]} />
              }
            >
              <Route index element={<ErrorBoundary><Enrollment /></ErrorBoundary>} />
            </Route>
          </Route>
        </Route>

        <Route path="/descargas" element={<Downloads />} />
        <Route path="*" element={<Navigate to="/" />} />
      </Routes>
      <PwaInstallPrompt />
    </Suspense>
  )
}

export default App
