/**
 * SCR-PRO-01 Profile
 * Perfil limpio: datos censurados con toggle de ojo, verificación por contraseña,
 * cambio de contacto vía diálogo con OTP, y cierre de sesión.
 */
import { useState, useRef, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import { getRoleDisplay } from '../config/roles';
import { usersApi } from '../api/users';
import { Camera, Mail, Phone, Key, CheckCircle2, AlertCircle, Loader2, Eye, EyeOff, LogOut, Type, Sun, Moon, Settings, Clock, GraduationCap, Shield, MessageCircle } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Card } from '../components/ui/Card';
import { Input, PasswordInput } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Skeleton } from '../components/ui/Skeleton';
import { Dialog } from '../components/ui/Overlay';
import { ROLES } from '../config/roles';
import { humanizeError } from '../utils/messages';
import OnboardingFlow from './onboarding/OnboardingFlow';


const compressImage = (file, maxWidth = 800, quality = 0.85) =>
  new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = (e) => {
      const img = new Image();
      img.onload = () => {
        const canvas = document.createElement('canvas');
        const scale = Math.min(1, maxWidth / img.width);
        canvas.width = img.width * scale;
        canvas.height = img.height * scale;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('No se pudo comprimir'))), 'image/jpeg', quality);
      };
      img.onerror = reject;
      img.src = e.target.result;
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });

const censor = (str, type) => {
  if (!str) return '—';
  if (type === 'email') return '•'.repeat(8);
  if (type === 'phone') return '•'.repeat(10);
  return '••••••••';
};

const Toast = ({ toast }) => {
  if (!toast) return null;
  return (
    <motion.div initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="mt-3 flex items-center gap-2 text-body-sm">
      {toast.type === 'success' ? <CheckCircle2 size={16} className="text-[var(--nx-success)]" /> : <AlertCircle size={16} className="text-[var(--nx-danger)]" />}
      <span className={toast.type === 'success' ? 'text-[var(--nx-success)]' : 'text-[var(--nx-danger)]'}>{toast.message}</span>
    </motion.div>
  );
};

const RevealDialog = ({ onClose, onVerified, title }) => {
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const handleVerify = async () => {
    if (!password) return;
    setLoading(true);
    setError(null);
    try {
      const res = await usersApi.changePassword(password, password);
      if (res.status === 'ok') {
        onVerified();
      } else {
        setError(res.message || 'Contraseña incorrecta');
      }
    } catch (e) {
      setError(humanizeError(e, 'Contraseña incorrecta'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog
      title={title}
      description="Ingresa tu contraseña para desbloquear tus datos de contacto."
      onClose={onClose}
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose}>Cancelar</Button>
          <Button size="sm" loading={loading} onClick={handleVerify}>Ver</Button>
        </>
      }
    >
      <PasswordInput
        label="Contraseña"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        autoFocus
      />
      {error && <p className="mt-2 text-body-sm text-[var(--nx-danger)]">{error}</p>}
    </Dialog>
  );
};

const ChangeDialog = ({ field, currentLabel, onClose, onSaved }) => {
  const [newValue, setNewValue] = useState('');
  const [step, setStep] = useState('edit');
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);
  const [toast, setToast] = useState(null);
  const [countdown, setCountdown] = useState(0);

  const purpose = field === 'email' ? 'email_change' : field === 'phone' ? 'phone_change' : 'backup_email_change';
  const verifyPurpose = field === 'email' ? 'email' : field === 'phone' ? 'phone' : 'backup_email';
  const inputType = field === 'phone' ? 'tel' : 'email';
  const placeholder = field === 'phone' ? 'Nuevo teléfono' : 'Nuevo correo';

  useEffect(() => {
    if (countdown <= 0) return;
    const t = setTimeout(() => setCountdown((c) => c - 1), 1000);
    return () => clearTimeout(t);
  }, [countdown]);

  const handleSave = async () => {
    if (!newValue.trim()) return;
    setLoading(true);
    setToast(null);
    try {
      const res = await usersApi.updateProfile(purpose, newValue);
      if (res.status === 'ok') {
        setStep('verify');
        setToast({ type: 'success', message: 'Dato guardado. Ahora debes verificarlo.' });
        setCountdown(60);
      } else {
        setToast({ type: 'error', message: res.message || 'Error al actualizar' });
      }
    } catch (e) {
      setToast({ type: 'error', message: humanizeError(e, 'Error al actualizar') });
    } finally {
      setLoading(false);
    }
  };

  const sendCode = async () => {
    setLoading(true);
    setToast(null);
    try {
      const res = await usersApi.sendVerificationCode(verifyPurpose, newValue);
      if (res.status === 'ok') {
        setToast({ type: 'success', message: 'Código enviado. Válido 10 min.' });
        setCountdown(60);
      } else {
        setToast({ type: 'error', message: res.message || 'No se pudo enviar' });
      }
    } catch (e) {
      setToast({ type: 'error', message: e.message || 'Error de red' });
    } finally {
      setLoading(false);
    }
  };

  const verifyCode = async () => {
    if (code.length !== 6) return;
    setLoading(true);
    try {
      const res = await usersApi.verifyCode(verifyPurpose, code);
      if (res.status === 'ok') {
        setToast({ type: 'success', message: '¡Verificado correctamente!' });
        setTimeout(() => onSaved(newValue), 800);
      } else {
        setToast({ type: 'error', message: res.message || 'Código inválido' });
      }
    } catch (e) {
      setToast({ type: 'error', message: e.message || 'Error de red' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog
      title={`Cambiar ${currentLabel}`}
      description={step === 'edit'
        ? 'Ingresa el nuevo dato. Te enviaremos un código de verificación.'
        : 'Te enviamos un código de 6 dígitos por WhatsApp' + (field !== 'phone' ? ' y correo' : '') + '.'
      }
      onClose={onClose}
      size="sm"
      footer={
        step === 'edit' ? (
          <>
            <Button variant="ghost" size="sm" onClick={onClose}>Cancelar</Button>
            <Button size="sm" loading={loading} onClick={handleSave}>Guardar y verificar</Button>
          </>
        ) : (
          <>
            <Button variant="ghost" size="sm" onClick={onClose}>Cancelar</Button>
            <Button size="sm" loading={loading} onClick={verifyCode}>Verificar código</Button>
          </>
        )
      }
    >
      {step === 'edit' ? (
        <Input
          type={inputType}
          placeholder={placeholder}
          value={newValue}
          onChange={(e) => setNewValue(e.target.value)}
          autoFocus
        />
      ) : (
        <div className="space-y-3">
          <Input
            label="Código de 6 dígitos"
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
            placeholder="000000"
            autoFocus
          />
          {countdown > 0 ? (
            <p className="text-caption text-[var(--nx-text-muted)]">Reenviar en {countdown}s</p>
          ) : (
            <button
              onClick={sendCode}
              disabled={loading}
              className="text-caption text-[var(--nx-accent)] hover:underline"
            >
              Reenviar código
            </button>
          )}
        </div>
      )}
      <Toast toast={toast} />
    </Dialog>
  );
};

const ForgotPasswordDialog = ({ phone, onClose, onSuccess }) => {
  const [step, setStep] = useState('send');
  const [code, setCode] = useState('');
  const [newPass, setNewPass] = useState('');
  const [confirmPass, setConfirmPass] = useState('');
  const [loading, setLoading] = useState(false);
  const [toast, setToast] = useState(null);
  const [countdown, setCountdown] = useState(0);

  const maskedPhone = phone ? `••••••${phone.slice(-4)}` : 'tu teléfono';

  useEffect(() => {
    if (countdown <= 0) return;
    const t = setTimeout(() => setCountdown((c) => c - 1), 1000);
    return () => clearTimeout(t);
  }, [countdown]);

  const handleSend = async () => {
    setLoading(true);
    setToast(null);
    try {
      const res = await usersApi.sendVerificationCode('password_reset', phone || '');
      if (res.status === 'ok') {
        setStep('verify');
        setToast({ type: 'success', message: `Código enviado a ${maskedPhone}.` });
        setCountdown(60);
      } else {
        setToast({ type: 'error', message: res.message || 'No se pudo enviar el código' });
      }
    } catch (e) {
      setToast({ type: 'error', message: humanizeError(e, 'No se pudo enviar el código') });
    } finally {
      setLoading(false);
    }
  };

  const handleVerify = async () => {
    if (code.length !== 6) return;
    setLoading(true);
    setToast(null);
    try {
      const res = await usersApi.verifyCode('password_reset', code);
      if (res.status === 'ok') {
        setStep('reset');
        setToast(null);
      } else {
        setToast({ type: 'error', message: res.message || 'Código inválido' });
      }
    } catch (e) {
      setToast({ type: 'error', message: humanizeError(e, 'Código inválido') });
    } finally {
      setLoading(false);
    }
  };

  const handleReset = async () => {
    if (newPass.length < 8) {
      setToast({ type: 'error', message: 'La contraseña debe tener al menos 8 caracteres' });
      return;
    }
    if (newPass !== confirmPass) {
      setToast({ type: 'error', message: 'Las contraseñas no coinciden' });
      return;
    }
    setLoading(true);
    setToast(null);
    try {
      const res = await usersApi.resetPassword(code, newPass);
      if (res.status === 'ok') {
        setToast({ type: 'success', message: 'Contraseña actualizada correctamente' });
        setTimeout(() => onSuccess(), 800);
      } else {
        setToast({ type: 'error', message: res.message || 'Error al actualizar' });
      }
    } catch (e) {
      setToast({ type: 'error', message: humanizeError(e, 'Error al actualizar') });
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog
      title="Recuperar contraseña"
      description={
        step === 'send' ? `Enviaremos un código de verificación a ${maskedPhone}.`
        : step === 'verify' ? 'Ingresa el código de 6 dígitos que enviamos a tu teléfono.'
        : 'Ingresa tu nueva contraseña.'
      }
      onClose={onClose}
      size="sm"
      footer={
        step === 'send' ? (
          <>
            <Button variant="ghost" size="sm" onClick={onClose}>Cancelar</Button>
            <Button size="sm" loading={loading} onClick={handleSend}>Enviar código</Button>
          </>
        ) : step === 'verify' ? (
          <>
            <Button variant="ghost" size="sm" onClick={onClose}>Cancelar</Button>
            <Button size="sm" loading={loading} onClick={handleVerify}>Validar</Button>
          </>
        ) : (
          <>
            <Button variant="ghost" size="sm" onClick={onClose}>Cancelar</Button>
            <Button size="sm" loading={loading} onClick={handleReset}>Actualizar</Button>
          </>
        )
      }
    >
      {step === 'send' && (
        <p className="text-body-sm text-[var(--nx-text-muted)]">
          Se enviará un código de 6 dígitos por WhatsApp al número registrado en tu cuenta.
        </p>
      )}
      {step === 'verify' && (
        <div className="space-y-3">
          <Input
            label="Código de verificación"
            placeholder="6 dígitos"
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
            autoFocus
          />
          {countdown > 0 ? (
            <p className="text-caption text-[var(--nx-text-muted)]">Reenviar en {countdown}s</p>
          ) : (
            <button onClick={handleSend} className="text-caption text-[var(--nx-accent)] hover:underline">
              Reenviar código
            </button>
          )}
        </div>
      )}
      {step === 'reset' && (
        <div className="space-y-4">
          <PasswordInput label="Nueva contraseña" value={newPass} onChange={(e) => setNewPass(e.target.value)} autoFocus />
          <PasswordInput label="Confirmar contraseña" value={confirmPass} onChange={(e) => setConfirmPass(e.target.value)} />
        </div>
      )}
      <Toast toast={toast} />
    </Dialog>
  );
};

const Profile = () => {
  const { user, setUser, logout } = useAuth();
  const fileRef = useRef(null);

  const [profile, setProfile] = useState(null);
  const [loadingProfile, setLoadingProfile] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [photoToast, setPhotoToast] = useState(null);

  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');

  const [actionToast, setActionToast] = useState(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');

  const [unlocked, setUnlocked] = useState(false);
  const [revealed, setRevealed] = useState({ email: false, phone: false, password: false });
  const [revealDialog, setRevealDialog] = useState(null);
  const [changeDialog, setChangeDialog] = useState(null);
  const [forgotPassword, setForgotPassword] = useState(false);
  const [fontScale, setFontScale] = useState(() => {
    const saved = localStorage.getItem('nx-font-scale');
    return saved ? parseFloat(saved) : 1;
  });

  // Onboarding en modo actualización — scope: 'schedule'|'groups'|'risk'
  const [onboardingScope, setOnboardingScope] = useState(null);

  useEffect(() => {
    usersApi.getExtendedProfile().then((res) => {
      if (res.status === 'ok' && res.data) {
        setProfile(res.data);
        setEmail(res.data.email || '');
        setPhone(res.data.phone || '');
      }
      setLoadingProfile(false);
    }).catch(() => setLoadingProfile(false));

  }, [user]);

  const handleFileChange = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    setPhotoToast(null);
    try {
      const compressed = await compressImage(file);
      const res = await usersApi.uploadPhoto(compressed);
      if (res.status === 'ok') {
        setPhotoToast({ type: 'success', message: 'Foto de perfil actualizada' });
        setProfile((p) => (p ? { ...p, profile_photo_url: res.photo_url } : p));
        setUser((u) => (u ? { ...u, profile_photo_url: res.photo_url } : u));
      } else {
        setPhotoToast({ type: 'error', message: res.message || 'Error al subir foto' });
      }
    } catch (e) {
      setPhotoToast({ type: 'error', message: humanizeError(e, 'Error al subir foto') });
    } finally {
      setUploading(false);
    }
  };

  const changePassword = async (e) => {
    e.preventDefault();
    setActionToast(null);
    if (newPassword !== confirmPassword) {
      setActionToast({ type: 'error', message: 'Las contraseñas nuevas no coinciden' });
      return;
    }
    if (newPassword.length < 8) {
      setActionToast({ type: 'error', message: 'La contraseña debe tener al menos 8 caracteres' });
      return;
    }
    setActionLoading(true);
    try {
      const res = await usersApi.changePassword(currentPassword, newPassword);
      if (res.status === 'ok') {
        setActionToast({ type: 'success', message: 'Contraseña actualizada' });
        setCurrentPassword('');
        setNewPassword('');
        setConfirmPassword('');
      } else {
        setActionToast({ type: 'error', message: res.message || 'Error al cambiar contraseña' });
      }
    } catch (e) {
      setActionToast({ type: 'error', message: humanizeError(e, 'Error al cambiar contraseña') });
    } finally {
      setActionLoading(false);
    }
  };

  const handleReveal = (field) => {
    if (unlocked) {
      setRevealed((r) => ({ ...r, [field]: !r[field] }));
    } else {
      setRevealDialog(field);
    }
  };

  const handleRevealVerified = () => {
    setUnlocked(true);
    setRevealed((r) => ({ ...r, [revealDialog]: true }));
    setRevealDialog(null);
  };

  const handleChangedSaved = (newValue) => {
    if (changeDialog === 'email') {
      setEmail(newValue);
    } else if (changeDialog === 'phone') {
      setPhone(newValue);
    }
    setChangeDialog(null);
  };

  const handleFontScale = (val) => {
    setFontScale(val);
    document.documentElement.style.setProperty('--nx-font-scale', String(val));
    localStorage.setItem('nx-font-scale', String(val));
  };

  const initial = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';

  const rawShift = user?.work_shift || profile?.work_shift || '';
  const shiftStr = String(rawShift).toLowerCase();
  const isAfternoon = shiftStr.includes('tarde') || shiftStr.includes('afternoon') || shiftStr === 'pm';
  const isMorning = shiftStr.includes('mañana') || shiftStr.includes('manana') || shiftStr.includes('morning') || shiftStr === 'am';
  const workShiftLabel = isAfternoon ? 'Jornada tarde' : isMorning ? 'Jornada mañana' : '';
  const workShiftIcon = isAfternoon ? <Moon size={12} className="text-[var(--nx-text-muted)]" /> : isMorning ? <Sun size={12} className="text-[var(--nx-text-muted)]" /> : null;

  if (loadingProfile) {
    return (
      <div className="space-y-6 max-w-3xl">
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-48 w-full" />
      </div>
    );
  }

  return (
    <div className="space-y-8 max-w-3xl">
      {/* ── Foto + Nombre ── */}
      <Card className="flex items-center gap-5 p-5">
        <div className="relative">
          {profile?.profile_photo_url ? (
            <img src={profile.profile_photo_url} alt="Foto" className="h-20 w-20 rounded-full object-cover" />
          ) : (
            <div className="flex h-20 w-20 items-center justify-center rounded-full bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] border border-[var(--nx-border-accent)] text-h1">{initial}</div>
          )}
          <button
            onClick={() => fileRef.current?.click()}
            disabled={uploading}
            className="absolute -bottom-1 -right-1 flex h-8 w-8 items-center justify-center rounded-full bg-[var(--nx-surface)] border border-[var(--nx-border)] text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)]"
          >
            {uploading ? <Loader2 size={14} className="animate-spin" /> : <Camera size={14} />}
          </button>
          <input ref={fileRef} type="file" accept="image/*" className="hidden" onChange={handleFileChange} />
        </div>
        <div>
          <p className="text-h2 text-[var(--nx-text)]">{user?.nombre || 'Usuario'}</p>
          <p className="text-body text-[var(--nx-text-muted)]">{getRoleDisplay(user?.role)?.toLowerCase()}</p>
          {workShiftLabel && (
            <div className="mt-1.5 inline-flex items-center gap-1.5 rounded-control bg-[var(--nx-surface-subtle)] px-2 py-0.5">
              {workShiftIcon}
              <span className="text-caption text-[var(--nx-text-muted)]">{workShiftLabel}</span>
            </div>
          )}
          <Toast toast={photoToast} />
        </div>
      </Card>

      {/* ── Contacto ── */}
      <Card className="space-y-4 p-5">
        <p className="text-h3 text-[var(--nx-text)] flex items-center gap-2"><Mail size={18} className="text-[var(--nx-accent)]" /> Contacto</p>

        {/* Email */}
        <div className="flex items-center justify-between gap-3 py-2 border-b border-[var(--nx-border)]">
          <div className="flex items-center gap-3 min-w-0 flex-1">
            <Mail size={16} className="text-[var(--nx-text-muted)] shrink-0" />
            <div className="min-w-0">
              <p className="text-caption text-[var(--nx-text-muted)]">Correo electrónico</p>
              <p className="text-body text-[var(--nx-text)] truncate">
                {revealed.email ? (email || '—') : censor(email, 'email')}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <button
              onClick={() => handleReveal('email')}
              className="flex h-8 w-8 items-center justify-center rounded-control text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]"
            >
              {revealed.email ? <EyeOff size={16} /> : <Eye size={16} />}
            </button>
          </div>
        </div>
        <button
          onClick={() => setChangeDialog('email')}
          className="text-body-sm text-[var(--nx-accent)] hover:underline"
        >
          Cambiar correo electrónico
        </button>

        {/* Phone */}
        <div className="flex items-center justify-between gap-3 py-2 border-b border-[var(--nx-border)]">
          <div className="flex items-center gap-3 min-w-0 flex-1">
            <Phone size={16} className="text-[var(--nx-text-muted)] shrink-0" />
            <div className="min-w-0">
              <p className="text-caption text-[var(--nx-text-muted)]">Teléfono</p>
              <p className="text-body text-[var(--nx-text)] truncate">
                {revealed.phone ? (phone || '—') : censor(phone, 'phone')}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <button
              onClick={() => handleReveal('phone')}
              className="flex h-8 w-8 items-center justify-center rounded-control text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]"
            >
              {revealed.phone ? <EyeOff size={16} /> : <Eye size={16} />}
            </button>
          </div>
        </div>
        <button
          onClick={() => setChangeDialog('phone')}
          className="text-body-sm text-[var(--nx-accent)] hover:underline"
        >
          Cambiar teléfono
        </button>

        {/* Password */}
        <div className="flex items-center justify-between gap-3 py-2 border-b border-[var(--nx-border)]">
          <div className="flex items-center gap-3 min-w-0 flex-1">
            <Key size={16} className="text-[var(--nx-text-muted)] shrink-0" />
            <div className="min-w-0">
              <p className="text-caption text-[var(--nx-text-muted)]">Contraseña</p>
              <p className="text-body text-[var(--nx-text)]">••••••••</p>
            </div>
          </div>
          <Button size="sm" variant="secondary" onClick={() => setChangeDialog('password')}>
            Cambiar
          </Button>
        </div>

        <Toast toast={actionToast} />
      </Card>

      {/* ── Docente: criterios de aviso (re-edición) ── */}
      {(user?.role === ROLES.DOCENTE || user?.role === ROLES.PSICORIENTADOR) && (
        <Card className="p-5">
          <div className="flex items-center justify-between gap-4">
            <div className="min-w-0">
              <p className="text-h3 text-[var(--nx-text)]">Tus criterios de aviso</p>
              <p className="mt-0.5 text-caption text-[var(--nx-text-muted)]">
                Los casos que Nexus vigila en tus clases — ajusta umbrales o desactiva los que no quieras.
              </p>
            </div>
            <Button size="sm" onClick={() => setOnboardingScope('rules')} leftIcon={<Settings size={15} />}>
              Editar con Nexus
            </Button>
          </div>
        </Card>
      )}

      {/* ── Configuración institucional — cada bloque abre el flujo
             Nexus en modo actualización, acotado a su sección ── */}
      {(user?.role === ROLES.RECTOR || user?.role === ROLES.COORDINADOR) && (
        <>
          {[
            {
              scope: 'schedule',
              icon: Clock,
              title: 'Jornadas y horarios',
              desc: 'Entrada, salida, descanso y bloques por jornada.',
            },
            {
              scope: 'groups',
              icon: GraduationCap,
              title: 'Grados y grupos',
              desc: user?.role === ROLES.RECTOR
                ? 'La estructura del año con su docente asignado.'
                : 'La estructura del año — la edición completa es de rectoría.',
            },
            {
              scope: 'risk',
              icon: Shield,
              title: 'Umbrales de riesgo',
              desc: 'A partir de cuántas repeticiones Nexus alerta cada nivel.',
            },
            {
              scope: 'chat',
              icon: MessageCircle,
              title: 'Asistente Nexus',
              desc: 'Qué puede consultar y hacer cada rol con el chatbot.',
            },
          ].map(({ scope, icon: Icon, title, desc }) => (
            <Card key={scope} className="p-5">
              <div className="flex items-center justify-between gap-4">
                <div className="flex min-w-0 items-center gap-3">
                  <span className="grid h-10 w-10 shrink-0 place-items-center rounded-control bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]">
                    <Icon size={18} />
                  </span>
                  <div className="min-w-0">
                    <p className="text-h3 text-[var(--nx-text)]">{title}</p>
                    <p className="mt-0.5 text-caption text-[var(--nx-text-muted)]">{desc}</p>
                  </div>
                </div>
                <Button size="sm" onClick={() => setOnboardingScope(scope)} leftIcon={<Settings size={15} />}>
                  Editar con Nexus
                </Button>
              </div>
            </Card>
          ))}
        </>
      )}

      {/* ── Tamaño de fuente ── */}
      <Card className="space-y-4 p-5">
        <p className="text-h3 text-[var(--nx-text)] flex items-center gap-2"><Type size={18} className="text-[var(--nx-accent)]" /> Tamaño de fuente</p>
        <p className="text-body-sm text-[var(--nx-text-muted)]">Mueve la barra hasta tener el tamaño deseado</p>
        <div className="flex items-center gap-4">
          <span className="text-caption text-[var(--nx-text-muted)] shrink-0">A</span>
          <input
            type="range"
            min={0.85}
            max={1.3}
            step={0.05}
            value={fontScale}
            onChange={(e) => handleFontScale(parseFloat(e.target.value))}
            className="flex-1 accent-[var(--nx-accent)] cursor-pointer"
          />
          <span className="text-h3 text-[var(--nx-text)] shrink-0">A</span>
        </div>
        <div className="flex justify-between">
          <button
            onClick={() => handleFontScale(1)}
            className="text-caption text-[var(--nx-accent)] hover:underline"
          >
            Restablecer
          </button>
          <span className="text-caption text-[var(--nx-text-muted)] tabular-nums">{Math.round(fontScale * 100)}%</span>
        </div>
      </Card>

      {/* ── Cerrar sesión ── */}
      <div className="flex justify-center pb-4">
        <Button
          variant="ghost"
          onClick={logout}
          leftIcon={<LogOut size={16} />}
          className="text-[var(--nx-danger)] hover:bg-[var(--nx-subtle-bg-danger)]"
        >
          Cerrar sesión
        </Button>
      </div>

      {/* ── Diálogos ── */}
      <AnimatePresence>
        {revealDialog && (
          <RevealDialog
            title="Desbloquear contacto"
            onClose={() => setRevealDialog(null)}
            onVerified={handleRevealVerified}
          />
        )}
      </AnimatePresence>

      <AnimatePresence>
        {changeDialog === 'password' && (
          <Dialog
            title="Cambiar contraseña"
            onClose={() => setChangeDialog(null)}
            size="sm"
            footer={
              <>
                <Button variant="ghost" size="sm" onClick={() => setChangeDialog(null)}>Cancelar</Button>
                <Button size="sm" loading={actionLoading} onClick={changePassword}>Actualizar</Button>
              </>
            }
          >
            <form onSubmit={changePassword} className="space-y-4">
              <PasswordInput label="Contraseña actual" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} />
              <PasswordInput label="Nueva contraseña" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} />
              <PasswordInput label="Confirmar nueva contraseña" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} />
              <button
                type="button"
                onClick={() => { setChangeDialog(null); setForgotPassword(true); }}
                className="text-body-sm text-[var(--nx-accent)] hover:underline"
              >
                ¿Olvidaste tu contraseña?
              </button>
            </form>
          </Dialog>
        )}
      </AnimatePresence>

      <AnimatePresence>
        {changeDialog && changeDialog !== 'password' && (
          <ChangeDialog
            field={changeDialog}
            currentLabel={changeDialog === 'email' ? 'correo electrónico' : changeDialog === 'phone' ? 'teléfono' : 'correo de respaldo'}
            onClose={() => setChangeDialog(null)}
            onSaved={handleChangedSaved}
          />
        )}
      </AnimatePresence>

      <AnimatePresence>
        {forgotPassword && (
          <ForgotPasswordDialog
            phone={phone}
            onClose={() => setForgotPassword(false)}
            onSuccess={() => {
              setForgotPassword(false);
              setActionToast({ type: 'success', message: 'Contraseña actualizada correctamente' });
            }}
          />
        )}
      </AnimatePresence>

      {/* Onboarding en modo actualización — pantalla completa bloqueante */}
      {onboardingScope && (
        <div className="fixed inset-0 z-[70] overflow-y-auto bg-[var(--nx-canvas)]">
          <OnboardingFlow
            role={user?.role}
            missing={{
              schedule: onboardingScope === 'schedule',
              groups: onboardingScope === 'groups',
              risk: onboardingScope === 'risk',
              chat: onboardingScope === 'chat',
            }}
            mode="update"
            onCancel={() => setOnboardingScope(null)}
            onAllDone={() => {
              setOnboardingScope(null);
              window.location.reload();
            }}
          />
        </div>
      )}
    </div>
  );
};

export default Profile;
