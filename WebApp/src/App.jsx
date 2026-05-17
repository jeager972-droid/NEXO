import { lazy, Suspense, useEffect } from 'react'
import { Routes, Route, Navigate, useNavigate } from 'react-router-dom'
import { useAuth } from './hooks/useAuth'
import ProtectedRoute from './routes/ProtectedRoute'
import Layout from './layout/Layout'
import { ROLES } from './config/roles'
import PwaInstallPrompt from './components/PwaInstallPrompt'

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
    <Suspense fallback={null}>
      <Routes>
        <Route path="/login" element={!user ? <Login /> : <Navigate to="/" />} />
        <Route path="/unauthorized" element={<Unauthorized />} />

        <Route element={<ProtectedRoute />}>
          <Route element={<Layout />}>
            <Route path="/" element={<Dashboard />} />
            <Route path="/operacion" element={<Operation />} />
            <Route path="/notificaciones" element={<Notifications />} />
            
            <Route 
              path="/consulta" 
              element={
                <ProtectedRoute allowedRoles={Object.values(ROLES)} />
              }
            >
              <Route index element={<Consultation />} />
            </Route>
            
            {/* Rutas específicas por rol */}
            <Route 
              path="/auditoria" 
              element={
                <ProtectedRoute allowedRoles={[ROLES.SUPER_RECTOR, ROLES.RECTOR]} />
              }
            >
              <Route index element={<Audit />} />
            </Route>

            <Route 
              path="/informes" 
              element={
                <ProtectedRoute allowedRoles={[ROLES.SUPER_RECTOR, ROLES.RECTOR]} />
              }
            >
              <Route index element={<Reports />} />
            </Route>

            <Route 
              path="/enrolamiento" 
              element={
                <ProtectedRoute allowedRoles={[ROLES.SECRETARIA]} />
              }
            >
              <Route index element={<Enrollment />} />
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
