/**
 * WebApp router / NEXO Institucional
 * Enrutador SPA: lazy loading, protección por rol, PWA y deep links.
 */
import { lazy, Suspense, useEffect } from 'react';
import { Routes, Route, Navigate, useNavigate, useLocation } from 'react-router-dom';
import { useAuth } from './hooks/useAuth';
import { NotificationProvider } from './context/NotificationContext';
import { initTelemetry } from './api/telemetry';
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
  const location = useLocation();
  const isInstallRoute = location.pathname.includes('/instalar/');

  useEffect(() => {
    const saved = localStorage.getItem('nx-font-scale');
    if (saved) {
      document.documentElement.style.setProperty('--nx-font-scale', String(saved));
    }
  }, []);

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

  useEffect(() => {
    const stopTelemetry = initTelemetry();
    return () => { if (typeof stopTelemetry === 'function') stopTelemetry(); };
  }, []);

  return (
    <Suspense fallback={
      <div className="flex min-h-screen flex-col items-center justify-center bg-[var(--nx-canvas)]">
        <div className="animate-pulse">
          <LogoNexo className="h-20" useImage />
        </div>
      </div>
    }>
      {/* Install page outside main routes to avoid remounts losing deferredPrompt */}
      {isInstallRoute && (
        <Routes>
          <Route path="/instalar/:platform" element={<InstallPage />} />
        </Routes>
      )}

      <NotificationProvider>
        <div className="min-h-screen">
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
              <Route path="/auditoria" element={<Navigate to="/consulta" replace />} />
              <Route path="*" element={<Navigate to="/" />} />
            </Routes>
        </div>
      </NotificationProvider>
      {!isInstallRoute && <PwaInstallPrompt />}
    </Suspense>
  );
}

export default App;
