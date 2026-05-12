import { useState } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import { Mail, Lock, AlertCircle, ArrowRight } from 'lucide-react';
import LogoNexo from '../components/LogoNexo';

const Login = () => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const { login } = useAuth();
  const navigate = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);

    try {
      await login(email, password);
      navigate('/');
    } catch (err) {
      console.error('Error detallado de login:', err);
      setError(typeof err === 'string' ? err : 'Error al conectar con el servidor institucional.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-slate-950 px-4 py-12 transition-colors duration-300">
      <div className="max-w-xl w-full">
        <div className="bg-white dark:bg-slate-900 rounded-[2.5rem] shadow-soft dark:shadow-soft-dark overflow-hidden border border-gray-100 dark:border-slate-800/50">
          <div className="px-10 py-12 text-center border-b border-gray-50 dark:border-slate-800/50">
            <div className="flex justify-center mb-8">
              <LogoNexo className="h-16" />
            </div>
            <h2 className="text-3xl font-black text-gray-900 dark:text-white tracking-tight uppercase">
              Portal Institucional
            </h2>
            <p className="text-gray-400 dark:text-slate-500 text-sm mt-3 font-bold uppercase tracking-widest">
              Panel de Monitoreo Biométrico
            </p>
          </div>

          <form onSubmit={handleSubmit} className="px-12 py-12 space-y-8">
            {error && (
              <div className="flex items-center gap-4 p-5 bg-red-50 dark:bg-red-900/10 text-red-600 dark:text-red-400 rounded-2xl text-sm font-bold border border-red-100 dark:border-red-900/20 animate-in fade-in zoom-in duration-300">
                <AlertCircle size={22} className="flex-shrink-0" />
                <p>{error}</p>
              </div>
            )}

            <div className="space-y-3">
              <label className="text-xs font-black text-gray-400 dark:text-slate-500 uppercase tracking-widest ml-1">
                Correo Electrónico
              </label>
              <div className="relative group">
                <div className="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none text-gray-300 dark:text-slate-600 group-focus-within:text-institutional-600 transition-colors">
                  <Mail size={20} />
                </div>
                <input
                  type="email"
                  required
                  className="block w-full pl-14 pr-6 py-5 bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-slate-800 rounded-3xl focus:ring-4 focus:ring-institutional-500/10 focus:border-institutional-500 transition-all outline-none text-gray-900 dark:text-white font-medium text-lg"
                  placeholder="usuario@inst.edu"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                />
              </div>
            </div>

            <div className="space-y-3">
              <label className="text-xs font-black text-gray-400 dark:text-slate-500 uppercase tracking-widest ml-1">
                Contraseña
              </label>
              <div className="relative group">
                <div className="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none text-gray-300 dark:text-slate-600 group-focus-within:text-institutional-600 transition-colors">
                  <Lock size={20} />
                </div>
                <input
                  type="password"
                  required
                  className="block w-full pl-14 pr-6 py-5 bg-gray-50 dark:bg-slate-800/50 border border-gray-100 dark:border-slate-800 rounded-3xl focus:ring-4 focus:ring-institutional-500/10 focus:border-institutional-500 transition-all outline-none text-gray-900 dark:text-white font-medium text-lg"
                  placeholder="••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                />
              </div>
            </div>

            <button
              type="submit"
              disabled={loading}
              className="w-full bg-institutional-900 hover:bg-institutional-800 dark:bg-institutional-700 dark:hover:bg-institutional-600 text-white font-black py-6 px-8 rounded-3xl transition-all flex items-center justify-center gap-3 disabled:opacity-70 disabled:cursor-not-allowed shadow-xl shadow-institutional-900/20 dark:shadow-none text-lg uppercase tracking-widest group"
            >
              {loading ? (
                <div className="h-6 w-6 border-3 border-white/30 border-t-white rounded-full animate-spin" />
              ) : (
                <>
                  <span>Ingresar al Sistema</span>
                  <ArrowRight size={22} className="group-hover:translate-x-1 transition-transform" />
                </>
              )}
            </button>
          </form>

          <div className="px-12 py-8 bg-gray-50 dark:bg-slate-800/30 text-center border-t border-gray-100 dark:border-slate-800/50">
            <p className="text-[10px] text-gray-400 dark:text-slate-600 font-bold uppercase tracking-[0.2em]">
              Acceso Restringido • Institución Educativa NEXO
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Login;
