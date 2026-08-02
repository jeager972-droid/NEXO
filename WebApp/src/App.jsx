/**
 * WebApp router / NEXO Institucional
 * Enrutador SPA: lazy loading, protección por rol, PWA y deep links.
 */
import { lazy, Suspense, useEffect } from 'react';
import { Routes, Route, Navigate, useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from './hooks/useAuth';
import ProtectedRoute from './routes/ProtectedRoute';
import Layout from './layout/Layout';
import { ROLES } from './config/roles';
import PwaInstallPrompt from './components/PwaInstallPrompt';
import ErrorBoundary from './components/ErrorBoundary';
import LogoNexo from './components/LogoNexo';

const Login = lazy(() => import('./pages/Login'));
const Dashboard = lazy(() => import('./pages/Dashboard'));
const Operation = lazy(() => import('./pages/Operation'));
const Notifications = lazy(() => import('./pages/Notifications'));
const Consultation = lazy(() => import('./pages/Consultation'));
const Enrollment = lazy(() => import('./pages/Enrollment'));
const Unauthorized = lazy(() => import('./pages/Unauthorized'));
const Casos = lazy(() => import('./pages/Seguimiento'));
const Downloads = lazy(() => import('./pages/Downloads'));
const InstallPage = lazy(() => import('./pages/InstallPage'));
const Profile = lazy(() => import('./pages/Profile'));

function App() {
  const { user } = useAuth();
  const navigate = useNavigate();

  useEffect(() => {
    const handler = (e) => { e.preventDefault(); window.__nexoPwaPrompt = e; };
    const installedHandler = () => { window.__nexoPwaPrompt = null; };
    window.addEventListener('beforeinstallprompt', handler);
    window.addEventListener('appinstalled', installedHandler);
    return () => {
      window.removeEventListener('beforeinstallprompt', handler);
      window.removeEventListener('appinstalled', installedHandler);
    };
  }, []);

  useEffect(() => {
    const setupDeepLink = async () => {
      if (!window.__TAURI__) return;
      try {
        const { onOpenUrl } = await import('@tauri-apps/plugin-deep-link');
        await onOpenUrl((urls) => {
          const url = urls[0];
          if (url.includes('nexo://login')) navigate('/login');
          else if (url.includes('nexo://operacion')) navigate('/operacion');
        });
      } catch (e) {
        console.warn('Deep link failed:', e);
      }
    };
    setupDeepLink();
  }, [navigate]);

  const shellKey = user ? 'authenticated' : 'unauthenticated';

  return (
    <Suspense fallback={
      <div className="flex min-h-screen flex-col items-center justify-center bg-[var(--nx-canvas)]">
        <motion.div
          initial={{ scale: 0.3, opacity: 0 }}
          animate={{ scale: 1, opacity: 1 }}
          transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
        >
          <LogoNexo className="h-20" useImage />
        </motion.div>
      </div>
    }>
      <AnimatePresence mode="wait" initial={false}>
        <motion.div
          key={shellKey}
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          transition={{ duration: 0.22, ease: 'easeInOut' }}
          className="min-h-screen"
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

                <Route path="/consulta" element={<ProtectedRoute allowedRoles={[ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR]} />}>
                  <Route index element={<ErrorBoundary><Consultation /></ErrorBoundary>} />
                </Route>
                <Route path="/casos" element={<ProtectedRoute allowedRoles={[ROLES.RECTOR, ROLES.COORDINADOR, ROLES.PSICORIENTADOR]} />}>
                  <Route index element={<ErrorBoundary><Casos /></ErrorBoundary>} />
                </Route>
                <Route path="/enrolamiento" element={<ProtectedRoute allowedRoles={[ROLES.SECRETARIA]} />}>
                  <Route index element={<ErrorBoundary><Enrollment /></ErrorBoundary>} />
                </Route>
              </Route>
            </Route>

            <Route path="/descargas" element={<Downloads />} />
            <Route path="/instalar/:platform" element={<InstallPage />} />
            <Route path="/auditoria" element={<Navigate to="/consulta" replace />} />
            <Route path="*" element={<Navigate to="/" />} />
          </Routes>
        </motion.div>
      </AnimatePresence>
      <PwaInstallPrompt />
    </Suspense>
  );
}

export default App;
