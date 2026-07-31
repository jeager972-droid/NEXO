/**
 * SCR-AUTH-01 Login · SCR-AUTH-02 Verificación 2FA
 * DEC-FE-03: sin saludo pre-auth; el login responde «cómo entro», no «qué hora es».
 * DEC-FE-02: humanizeError normaliza todo mensaje de servidor.
 * CMP-004: PasswordInput con toggle integrado (no posicionamiento absoluto).
 */
import { useState, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import { ShieldCheck, ArrowRight } from 'lucide-react';
import { authApi } from '../api/auth';
import LogoNexo from '../components/LogoNexo';
import { Input, PasswordInput } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { humanizeError } from '../utils/messages';

const Login = () => {
  const [email, setEmail]       = useState('');
  const [password, setPassword] = useState('');
  const [error, setError]       = useState('');
  const [loading, setLoading]   = useState(false);
  const [show2FA, setShow2FA]   = useState(false);
  const [otpCode, setOtpCode]   = useState('');
  const [masked, setMasked]     = useState('');
  const [resendTimer, setResendTimer] = useState(0);
  const { login, setUser }      = useAuth();
  const navigate    = useNavigate();

  useEffect(() => {
    if (resendTimer <= 0) return;
    const id = setInterval(() => setResendTimer((t) => t - 1), 1000);
    return () => clearInterval(id);
  }, [resendTimer]);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const result = await login(email, password);
      if (result?.status === '2fa_required' || result?.requires_2fa) {
        setShow2FA(true);
        setMasked(result?.masked_destination || '');
        setResendTimer(60);
        return;
      }
      navigate('/');
    } catch (err) {
      setError(humanizeError(err, 'Correo o contraseña no coinciden. Verifica ambos campos.'));
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
        setError('No pudimos verificar el código. Revisa e inténtalo de nuevo.');
      }
    } catch (err) {
      setError(humanizeError(err, 'El código no es válido o ya expiró.'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen flex-col items-center justify-center p-4 bg-[var(--nx-canvas)]">
      <motion.div
        initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
        className="w-full max-w-[420px] space-y-8"
      >
        <div className="flex flex-col items-center gap-3 text-center">
          <div className="h-14 w-14 rounded-surface bg-[var(--nx-accent)] text-[var(--nx-accent-text)] flex items-center justify-center">
            <LogoNexo className="h-8" showText={false} />
          </div>
        </div>

        <div className="rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] p-6 lg:p-8 shadow-medium">
          <AnimatePresence mode="wait">
            {!show2FA ? (
              <motion.form
                key="login"
                initial={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.15 }}
                onSubmit={handleSubmit} className="space-y-6"
              >
                <Input
                  label="Correo institucional"
                  type="email"
                  autoComplete="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  placeholder="tu@colegio.edu"
                />
                <PasswordInput
                  label="Contraseña"
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  placeholder="••••••••"
                />

                {error && (
                  <div className="rounded-control bg-[color-mix(in_oklch,var(--nx-danger)_var(--nx-subtle-mix),transparent)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">
                    {error}
                  </div>
                )}

                <Button type="submit" size="lg" block loading={loading} rightIcon={<ArrowRight size={18} />}>
                  Entrar
                </Button>
              </motion.form>
            ) : (
              <motion.form
                key="2fa"
                initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.15 }}
                onSubmit={handleVerifyOTP} className="space-y-6"
              >
                <div className="flex items-center gap-3 text-[var(--nx-accent)] mb-2">
                  <ShieldCheck size={22} />
                  <h2 className="text-h2">Verificación en dos pasos</h2>
                </div>
                <p className="text-body text-[var(--nx-text-muted)]">
                  Escribe el código de 6 dígitos enviado a {masked || 'tu dispositivo'}.
                </p>
                <Input
                  label="Código de verificación"
                  type="text"
                  inputMode="numeric"
                  maxLength={6}
                  value={otpCode}
                  onChange={(e) => setOtpCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                  placeholder="000000"
                />
                {error && (
                  <div className="rounded-control bg-[color-mix(in_oklch,var(--nx-danger)_var(--nx-subtle-mix),transparent)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">
                    {error}
                  </div>
                )}
                <Button type="submit" size="lg" block loading={loading}>
                  Verificar
                </Button>
                <button
                  type="button"
                  disabled={resendTimer > 0}
                  onClick={() => { setShow2FA(false); setOtpCode(''); }}
                  className="w-full text-center text-body-sm text-[var(--nx-text-muted)] hover:text-[var(--nx-accent)] disabled:opacity-50"
                >
                  {resendTimer > 0 ? `Reenviar en ${resendTimer}s` : 'Volver al inicio de sesión'}
                </button>
              </motion.form>
            )}
          </AnimatePresence>
        </div>

        <p className="text-center text-caption text-[var(--nx-text-muted)]">
          Si no puedes acceder, contacta al administrador de la institución.
        </p>
      </motion.div>
    </div>
  );
};

export default Login;
