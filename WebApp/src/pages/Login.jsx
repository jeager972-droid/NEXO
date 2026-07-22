/**
 * Login page / NEXO Institucional
 * Responsabilidad: Formulario de autenticación con email/contraseña, flujo de 2FA,
 * manejo de errores del backend, autenticación biométrica nativa y animaciones de entrada.
 * Redirige al dashboard tras login exitoso.
 * Dependencias: React, react-router-dom, framer-motion, useAuth, authApi, nativeAuth.
 */
import { useState } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import { Mail, Lock, AlertCircle, ArrowRight } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { authApi } from '../api/auth';

const STAGGER = {
  container: {
    hidden: {},
    show: { transition: { staggerChildren: 0.07, delayChildren: 0.15 } },
  },
  item: {
    hidden: { opacity: 0, y: 14 },
    show:   { opacity: 1, y: 0, transition: { duration: 0.38, ease: [0.25, 0.46, 0.45, 0.94] } },
  },
};

const CARD = {
  hidden: { opacity: 0, y: 28, scale: 0.985 },
  show:   { opacity: 1, y: 0, scale: 1, transition: { duration: 0.52, ease: [0.25, 0.46, 0.45, 0.94] } },
};

const FieldWrapper = ({ label, icon: Icon, children }) => (
  <motion.div variants={STAGGER.item} className="space-y-2">
    <label className="flex items-center gap-1.5 text-[10px] font-bold text-slate-400 uppercase tracking-[0.2em] ml-0.5 select-none">
      {label}
    </label>
    <div className="relative group">
      <span className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-300 group-focus-within:text-[#003366] transition-colors duration-250">
        <Icon size={17} strokeWidth={2} />
      </span>
      {children}
    </div>
  </motion.div>
);

const Login = () => {
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [error, setError]       = useState('');
  const [loading, setLoading]   = useState(false);
  const [show2FA, setShow2FA]   = useState(false);
  const [otpCode, setOtpCode]   = useState('');
  const { login, setUser }      = useAuth();
  const navigate    = useNavigate();

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const result = await login(email, password);
      if (result?.status === '2fa_required' || result?.requires_2fa) {
        setShow2FA(true);
        return;
      }
      navigate('/');
    } catch (err) {
      console.error('Error detallado de login:', err);
      // BUG-15 FIX: login() ahora lanza un Error object, no un string
      setError(err instanceof Error ? err.message : typeof err === 'string' ? err : 'Credenciales inválidas.');
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyOTP = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const data = await authApi.verify2FA(email, otpCode);
      if (data.user) {

        if (setUser) setUser(data.user);
        navigate('/');
      } else {
        setError('Error al verificar el código OTP.');
      }
    } catch (err) {
      setError(typeof err === 'string' ? err : err.response?.data?.message || 'Código OTP inválido.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div
      className="min-h-screen flex flex-col items-center justify-center px-4 py-14 font-sans"
      style={{ backgroundColor: '#F8FAFC' }}
    >
      {/* Main card */}
      <motion.div
        variants={CARD}
        initial="hidden"
        animate="show"
        className="w-full max-w-md"
        style={{
          backgroundColor: '#FFFFFF',
          border: '1.5px solid #E2E8F0',
          borderRadius: '4px',
          boxShadow: '0 2px 8px -1px rgba(0,51,102,0.06), 0 1px 3px -1px rgba(0,51,102,0.04)',
        }}
      >
        {/* Header */}
        <div
          className="px-10 pt-10 pb-8 text-center"
          style={{ borderBottom: '1.5px solid #F1F5F9' }}
        >
          <p
            className="text-[11px] font-bold uppercase tracking-[0.35em] mb-4"
            style={{ color: '#003366' }}
          >
            NEXO
          </p>

          <h1
            className="text-xl font-black uppercase"
            style={{ color: '#1E293B', letterSpacing: '0.06em' }}
          >
            Iniciar sesión
          </h1>
        </div>

        {/* Form */}
        <motion.form
          onSubmit={handleSubmit}
          variants={STAGGER.container}
          initial="hidden"
          animate="show"
          className="px-10 py-8 space-y-6"
        >
          <AnimatePresence mode="wait">
            {error && (
              <motion.div
                key="error"
                initial={{ opacity: 0, y: -6, scale: 0.98 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                exit={{ opacity: 0, y: -4, scale: 0.98 }}
                transition={{ duration: 0.25 }}
                className="flex items-start gap-3 p-4 text-sm font-semibold"
                style={{
                  backgroundColor: '#FEF2F2',
                  border: '1.5px solid #FEE2E2',
                  borderRadius: '4px',
                  color: '#DC2626',
                }}
              >
                <AlertCircle size={16} className="flex-shrink-0 mt-0.5" strokeWidth={2.5} />
                <p className="leading-snug">{error}</p>
              </motion.div>
            )}
          </AnimatePresence>

          {show2FA ? (
            <div className="space-y-4">
              <p className="text-sm font-semibold text-slate-600 text-center mb-4">Ingresa el código de 6 dígitos enviado a tu WhatsApp.</p>
              <input
                type="text"
                maxLength={6}
                value={otpCode}
                onChange={e => setOtpCode(e.target.value.replace(/\D/g, ''))}
                placeholder="Código OTP"
                className="block w-full pl-4 pr-4 py-3.5 text-center text-xl tracking-[0.3em] font-black outline-none transition-all duration-250"
                style={{
                  backgroundColor: '#F8FAFC',
                  border: '1.5px solid #E2E8F0',
                  borderRadius: '4px',
                  color: '#0F172A',
                  boxShadow: 'inset 0 1px 3px 0 rgba(0,51,102,0.04)',
                }}
              />
              <motion.button
                onClick={handleVerifyOTP}
                disabled={loading || otpCode.length < 6}
                type="button"
                className="w-full flex items-center justify-center gap-3 py-4 text-sm font-bold uppercase tracking-[0.18em] text-white transition-colors duration-250 disabled:opacity-60 disabled:cursor-not-allowed"
                style={{
                  backgroundColor: '#003366',
                  borderRadius: '4px',
                  border: '1.5px solid transparent',
                  boxShadow: '0 4px 24px -4px rgba(0,51,102,0.28)',
                }}
              >
                {loading ? 'Verificando...' : 'Verificar'}
              </motion.button>
            </div>
          ) : (
            (
              <>
              <FieldWrapper label="Correo Electrónico" icon={Mail}>
            <input
              type="email"
              required
              autoComplete="email"
              className="block w-full pl-10 pr-4 py-3.5 text-sm font-medium outline-none transition-all duration-250"
              style={{
                backgroundColor: '#F8FAFC',
                border: '1.5px solid #E2E8F0',
                borderRadius: '4px',
                color: '#0F172A',
                boxShadow: 'inset 0 1px 3px 0 rgba(0,51,102,0.04)',
              }}
              placeholder="usuario@institucion.edu.co"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              onFocus={(e) => {
                e.target.style.borderColor = '#003366';
                e.target.style.boxShadow   = '0 0 0 3px rgba(0,51,102,0.08), inset 0 1px 3px 0 rgba(0,51,102,0.04)';
              }}
              onBlur={(e) => {
                e.target.style.borderColor = '#E2E8F0';
                e.target.style.boxShadow   = 'inset 0 1px 3px 0 rgba(0,51,102,0.04)';
              }}
            />
          </FieldWrapper>

          <FieldWrapper label="Contraseña de Acceso" icon={Lock}>
            <input
              type="password"
              required
              autoComplete="current-password"
              className="block w-full pl-10 pr-4 py-3.5 text-sm font-medium outline-none transition-all duration-250"
              style={{
                backgroundColor: '#F8FAFC',
                border: '1.5px solid #E2E8F0',
                borderRadius: '4px',
                color: '#0F172A',
                boxShadow: 'inset 0 1px 3px 0 rgba(0,51,102,0.04)',
              }}
              placeholder="••••••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onFocus={(e) => {
                e.target.style.borderColor = '#003366';
                e.target.style.boxShadow   = '0 0 0 3px rgba(0,51,102,0.08), inset 0 1px 3px 0 rgba(0,51,102,0.04)';
              }}
              onBlur={(e) => {
                e.target.style.borderColor = '#E2E8F0';
                e.target.style.boxShadow   = 'inset 0 1px 3px 0 rgba(0,51,102,0.04)';
              }}
            />
          </FieldWrapper>

          <motion.div variants={STAGGER.item} className="pt-2">
            <motion.button
              type="submit"
              disabled={loading}
              whileTap={!loading ? { scale: 0.985 } : {}}
              whileHover={!loading ? { backgroundColor: '#052955' } : {}}
              className="w-full flex items-center justify-center gap-3 py-4 text-sm font-bold uppercase tracking-[0.18em] text-white transition-colors duration-250 disabled:opacity-60 disabled:cursor-not-allowed"
              style={{
                backgroundColor: '#003366',
                borderRadius: '4px',
                border: '1.5px solid transparent',
                boxShadow: '0 4px 24px -4px rgba(0,51,102,0.28)',
              }}
            >
              {loading ? (
                <span className="flex items-center gap-2.5">
                  <svg className="animate-spin h-4 w-4 text-white/70" viewBox="0 0 24 24" fill="none">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2.5" />
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                  </svg>
                  Verificando Credenciales
                </span>
              ) : (
                <>
                  <span>Ingresar</span>
                  <ArrowRight size={16} strokeWidth={2.5} />
                </>
              )}
            </motion.button>
          </motion.div>
          </>
            )
          )}
        </motion.form>

        {/* Footer */}
        <div
          className="px-10 py-5 flex items-center justify-center"
          style={{ borderTop: '1.5px solid #F1F5F9', backgroundColor: '#F8FAFC' }}
        >
          <p
            className="text-[9px] font-bold uppercase tracking-[0.18em] select-none"
            style={{ color: '#CBD5E1' }}
          >
            NEXO · Sistema de custodia estudiantil
          </p>
        </div>
      </motion.div>

      <motion.p
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.5, delay: 0.7 }}
        className="mt-8 text-[9px] font-bold uppercase tracking-[0.25em] select-none"
        style={{ color: '#CBD5E1' }}
      >
        © {new Date().getFullYear()} NEXO
      </motion.p>
    </div>
  );
};

export default Login;
