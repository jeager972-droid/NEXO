/**
 * ProtectedRoute / NEXO Institucional
 * Responsabilidad: Guardia de rutas autenticadas. Si no hay sesión redirige a /login;
 * si el rol no está en allowedRoles redirige a /unauthorized. Muestra spinner mientras
 * AuthContext está inicializando.
 * Dependencias: react-router-dom, useAuth.
 * Props: { allowedRoles?: string[] }.
 */
import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';

const ProtectedRoute = ({ allowedRoles }) => {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-institutional-600"></div>
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  if (allowedRoles && !allowedRoles.includes(user.role)) {
    return <Navigate to="/unauthorized" replace />;
  }

  return <Outlet />;
};

export default ProtectedRoute;
