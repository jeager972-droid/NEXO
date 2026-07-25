/**
 * Unauthorized page / NEXO Institucional
 * Pantalla 403 — rol sin acceso a ruta protegida.
 */
import { ShieldAlert, ArrowLeft } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

const Unauthorized = () => {
  const navigate = useNavigate();

  return (
    <div
      className="min-h-screen flex items-center justify-center px-4"
      style={{ backgroundColor: 'var(--nx-canvas)' }}
    >
      <div className="max-w-md w-full text-center space-y-6">
        <div
          className="inline-flex items-center justify-center w-20 h-20 mb-4"
          style={{
            backgroundColor: 'color-mix(in oklch, var(--nx-danger) 10%, transparent)',
            color: 'var(--nx-danger)',
            borderRadius: '50%',
          }}
        >
          <ShieldAlert size={48} strokeWidth={1.5} />
        </div>
        <h1
          className="text-2xl font-semibold"
          style={{ color: 'var(--nx-text)' }}
        >
          Acceso Denegado
        </h1>
        <p className="text-base" style={{ color: 'var(--nx-text-muted)' }}>
          No tienes los permisos necesarios para acceder a esta sección del sistema.
          Contacta al administrador si crees que esto es un error.
        </p>
        <button
          onClick={() => navigate('/')}
          className="inline-flex items-center gap-2 font-semibold py-3 px-8 transition-colors"
          style={{
            backgroundColor: 'var(--nx-accent)',
            color: 'var(--nx-accent-text)',
            borderRadius: 'var(--nx-radius-control)',
          }}
        >
          <ArrowLeft size={18} strokeWidth={1.75} />
          Volver al Dashboard
        </button>
      </div>
    </div>
  );
};

export default Unauthorized;
