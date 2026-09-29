/**
 * ProtectedRoute / NEXO Institucional
 * Guardia de rutas: requiere autenticación y roles permitidos.
 * El gate legal (cookies + Términos) solo corre en el guard externo:
 * las rutas anidadas con allowedRoles ya están dentro de él.
 */
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import LegalGate from '../components/legal/LegalGate';

const ProtectedRoute = ({ allowedRoles }) => {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) {
    return <div className="flex h-screen items-center justify-center text-[var(--nx-text-muted)]">Cargando…</div>;
  }

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />;
  }

  if (allowedRoles && !allowedRoles.includes(user.role)) {
    return <Navigate to="/unauthorized" replace />;
  }

  // Solo el guard externo (sin allowedRoles) monta el gate legal;
  // los anidados se renderizan dentro de él y no deben duplicarlo.
  if (!allowedRoles) {
    return <LegalGate user={user}><Outlet /></LegalGate>;
  }

  return <Outlet />;
};

export default ProtectedRoute;
