/**
 * Login page / NEXO Institucional
 * Formulario de autenticación con email/contraseña, flujo 2FA, manejo de errores.
 * NEXO Quiet Operations — OKLCH tokens, sin glassmorphism.
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
    show:   { opacity: 1, y: 0, transition: { duration: 0.38, ease: [0.22, 1, 0.36, 1] } },
  },
};

const CARD = {
  hidden: { opacity: 0, y: 28, scale: 0.985 },
  show:   { opacity: 1, y: 0, scale: 1, transition: { duration: 0.52, ease: [0.22, 1, 0.36, 1] } },
};

const FieldWrapper = ({ label, icon: Icon, children }) => (
  <motion.div variants={STAGGER.item} className="space-y-2">
    <label className="flex items-center gap-1.5 text-xs font-semibold ml-0.5 select-none" style={{ color: 'var(--nx-text-muted)' }}>
      {label}
    </label>
    <div className="relative group">
      <span className="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none transition-colors" style={{ color: 'var(--nx-text-muted)' }}>
        <Icon size={17} strokeWidth={1.75} />
      </span>
      {children}
    </div>
  </motion.div>
);

const inputStyle = {
  backgroundColor: 'var(--nx-surface-subtle)',
  border: '1px solid var(--nx-border)',
  borderRadius: 'var(--nx-radius-control)',
  color: 'var(--nx-text)',
};

const focusStyle = (e) => {
  e.target.style.borderColor = 'var(--nx-accent)';
  e.target.style.boxShadow = '0 0 0 3px color-mix(in oklch, var(--nx-accent) 12%, transparent)';
};
const blurStyle = (e) => {
  e.target.style.borderColor = 'var(--nx-border)';
  e.target.style.boxShadow = 'none';
};

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
      className="min-h-screen flex flex-col items-center justify-center px-4 py-14"
      style={{ backgroundColor: 'var(--nx-canvas)' }}
    >
      <motion.div
        variants={CARD}
        initial="hidden"
        animate="show"
        className="w-full max-w-md"
        style={{
          backgroundColor: 'var(--nx-surface)',
          border: '1px solid var(--nx-border)',
          borderRadius: 'var(--nx-radius-panel)',
          boxShadow: 'var(--nx-shadow-medium)',
        }}
      >
        {/* Header */}
        <div
          className="px-10 pt-10 pb-8 text-center"
          style={{ borderBottom: '1px solid var(--nx-border)' }}
        >
          <p
            className="text-sm font-semibold mb-4"
            style={{ color: 'var(--nx-accent)', letterSpacing: '0.2em' }}
          >
            NEXO
          </p>

          <h1
            className="text-xl font-semibold"
            style={{ color: 'var(--nx-text)', letterSpacing: '0.02em' }}
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
                transition={{ duration: 0.2 }}
                className="flex items-start gap-3 p-4 text-sm font-medium"
                style={{
                  backgroundColor: 'color-mix(in oklch, var(--nx-danger) 8%, transparent)',
                  border: '1px solid color-mix(in oklch, var(--nx-danger) 20%, transparent)',
                  borderRadius: 'var(--nx-radius-control)',
                  color: 'var(--nx-danger)',
                }}
              >
                <AlertCircle size={16} className="flex-shrink-0 mt-0.5" strokeWidth={2} />
                <p className="leading-snug">{error}</p>
              </motion.div>
            )}
          </AnimatePresence>

          {show2FA ? (
            <div className="space-y-4">
              <p className="text-sm font-medium text-center mb-4" style={{ color: 'var(--nx-text-muted)' }}>
                Ingresa el código de 6 dígitos enviado a tu WhatsApp.
              </p>
              <input
                type="text"
                maxLength={6}
                value={otpCode}
                onChange={e => setOtpCode(e.target.value.replace(/\D/g, ''))}
                placeholder="Código OTP"
                className="block w-full pl-4 pr-4 py-3.5 text-center text-xl tracking-[0.3em] font-semibold outline-none transition-all"
                style={inputStyle}
                onFocus={focusStyle}
                onBlur={blurStyle}
              />
              <motion.button
                onClick={handleVerifyOTP}
                disabled={loading || otpCode.length < 6}
                type="button"
                className="w-full flex items-center justify-center gap-3 py-3.5 text-sm font-semibold transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                style={{
                  backgroundColor: 'var(--nx-accent)',
                  color: 'var(--nx-accent-text)',
                  borderRadius: 'var(--nx-radius-control)',
                  border: '1px solid transparent',
                }}
              >
                {loading ? 'Verificando...' : 'Verificar'}
              </motion.button>
            </div>
          ) : (
            <>
              <FieldWrapper label="Correo Electrónico" icon={Mail}>
                <input
                  type="email"
                  required
                  autoComplete="email"
                  className="block w-full pl-10 pr-4 py-3.5 text-sm font-medium outline-none transition-all"
                  style={inputStyle}
                  placeholder="usuario@institucion.edu.co"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  onFocus={focusStyle}
                  onBlur={blurStyle}
                />
              </FieldWrapper>

              <FieldWrapper label="Contraseña de Acceso" icon={Lock}>
                <input
                  type="password"
                  required
                  autoComplete="current-password"
                  className="block w-full pl-10 pr-4 py-3.5 text-sm font-medium outline-none transition-all"
                  style={inputStyle}
                  placeholder="••••••••••••"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  onFocus={focusStyle}
                  onBlur={blurStyle}
                />
              </FieldWrapper>

              <motion.div variants={STAGGER.item} className="pt-2">
                <motion.button
                  type="submit"
                  disabled={loading}
                  whileTap={!loading ? { scale: 0.985 } : {}}
                  className="w-full flex items-center justify-center gap-3 py-3.5 text-sm font-semibold transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
                  style={{
                    backgroundColor: 'var(--nx-accent)',
                    color: 'var(--nx-accent-text)',
                    borderRadius: 'var(--nx-radius-control)',
                    border: '1px solid transparent',
                  }}
                >
                  {loading ? (
                    <span className="flex items-center gap-2.5">
                      <svg className="animate-spin h-4 w-4 opacity-70" viewBox="0 0 24 24" fill="none">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="2.5" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                      </svg>
                      Verificando Credenciales
                    </span>
                  ) : (
                    <>
                      <span>Ingresar</span>
                      <ArrowRight size={16} strokeWidth={2} />
                    </>
                  )}
                </motion.button>
              </motion.div>
            </>
          )}
        </motion.form>

        {/* Footer */}
        <div
          className="px-10 py-5 flex items-center justify-center"
          style={{ borderTop: '1px solid var(--nx-border)', backgroundColor: 'var(--nx-surface-subtle)' }}
        >
          <p
            className="text-xs font-medium select-none"
            style={{ color: 'var(--nx-text-muted)' }}
          >
            NEXO · Sistema de custodia estudiantil
          </p>
        </div>
      </motion.div>

      <motion.p
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.5, delay: 0.7 }}
        className="mt-8 text-xs font-medium select-none"
        style={{ color: 'var(--nx-text-muted)' }}
      >
        © {new Date().getFullYear()} NEXO
      </motion.p>
    </div>
  );
};

export default Login;
