/**
 * SCR-AUTH-03 Unauthorized
 * Acceso denegado. Explica por qué y ofrece ruta de salida.
 */
import { useNavigate } from 'react-router-dom';
import { ShieldAlert } from 'lucide-react';
import { Button } from '../components/ui/Button';
import { EmptyState } from '../components/ui/EmptyState';

const Unauthorized = () => {
  const navigate = useNavigate();

  return (
    <div className="min-h-[60vh] flex items-center justify-center p-4">
      <EmptyState
        icon={<ShieldAlert size={48} className="text-[var(--nx-warning)]" />}
        title="No tienes permiso para ver esta sección"
        description="Tu rol no está autorizado a acceder aquí. Si crees que es un error, contacta al administrador."
        action={
          <div className="flex gap-3">
            <Button variant="secondary" onClick={() => navigate(-1)}>Volver</Button>
            <Button onClick={() => navigate('/')}>Ir al inicio</Button>
          </div>
        }
      />
    </div>
  );
};

export default Unauthorized;
