import { ShieldAlert, ArrowLeft } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

const Unauthorized = () => {
  const navigate = useNavigate();

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-100 px-4">
      <div className="max-w-md w-full text-center space-y-6">
        <div className="inline-flex items-center justify-center w-24 h-24 bg-red-100 text-red-600 rounded-full mb-4">
          <ShieldAlert size={60} />
        </div>
        <h1 className="text-3xl font-extrabold text-gray-900 uppercase tracking-tight">
          Acceso Denegado
        </h1>
        <p className="text-gray-600 text-lg">
          No tienes los permisos necesarios para acceder a esta sección del sistema. 
          Contacta al administrador si crees que esto es un error.
        </p>
        <button 
          onClick={() => navigate('/')}
          className="inline-flex items-center gap-2 bg-institutional-800 hover:bg-institutional-900 text-white font-bold py-3 px-8 rounded-xl transition-all shadow-lg"
        >
          <ArrowLeft size={20} />
          Volver al Dashboard
        </button>
      </div>
    </div>
  );
};

export default Unauthorized;
