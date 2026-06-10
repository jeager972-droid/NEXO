import { useState, useRef, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import { usersApi } from '../api/users';
import {
  Camera, Loader2, CheckCircle2, AlertTriangle,
  Lock, Save, Send, Eye, EyeOff, Trash2
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

/* ─── Section wrapper ─── */
const SectionCard = ({ title, subtitle, children }) => (
  <div className="bg-white dark:bg-slate-900 p-5 space-y-4" style={{ border: '1.5px solid #E2E8F0' }}>
    <div>
      <p className="text-xs font-black uppercase tracking-tight text-slate-800 dark:text-white">{title}</p>
      <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mt-0.5">{subtitle}</p>
    </div>
    {children}
  </div>
);

/* ─── Text input ─── */
const TextField = ({ label, value, onChange, type = 'text', placeholder, disabled, rightElement }) => (
  <div>
    <label style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase', display: 'block', marginBottom: '6px' }}>{label}</label>
    <div className="relative">
      <input
        type={type}
        value={value}
        onChange={onChange}
        disabled={disabled}
        placeholder={placeholder}
        className="w-full px-3 py-2.5 text-sm font-medium text-slate-800 dark:text-white bg-slate-50 dark:bg-slate-800 outline-none disabled:opacity-50"
        style={{ border: '1.5px solid #E2E8F0', fontSize: '13px' }}
      />
      {rightElement && <div className="absolute right-2 top-1/2 -translate-y-1/2">{rightElement}</div>}
    </div>
  </div>
);

/* ─── Toast ─── */
const InlineToast = ({ toast }) => {
  if (!toast) return null;
  return (
    <motion.div
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      exit={{ opacity: 0, y: 8 }}
      className={`px-4 py-2.5 text-xs font-semibold flex items-center gap-2 ${
        toast.type === 'success'
          ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
          : toast.type === 'info'
          ? 'bg-blue-50 text-blue-700 border border-blue-200'
          : 'bg-red-50 text-red-700 border border-red-200'
      }`}
    >
      {toast.type === 'success' ? <CheckCircle2 size={14} /> : toast.type === 'info' ? <Send size={14} /> : <AlertTriangle size={14} />}
      {toast.message}
    </motion.div>
  );
};

/* ─── OTP Verification Block ─── */
const OtpBlock = ({ purpose, target, label, onVerified, disabled }) => {
  const [code, setCode] = useState('');
  const [step, setStep] = useState('idle'); // idle | sent | verifying | verified
  const [toast, setToast] = useState(null);
  const [countdown, setCountdown] = useState(0);

  useEffect(() => {
    if (countdown <= 0) return;
    const t = setTimeout(() => setCountdown(c => c - 1), 1000);
    return () => clearTimeout(t);
  }, [countdown]);

  const sendCode = async () => {
    if (!target || countdown > 0) return;
    setStep('sent');
    setToast({ type: 'info', message: 'Enviando código por WhatsApp…' });
    try {
      const res = await usersApi.sendVerificationCode(purpose, target);
      if (res.status === 'ok') {
        setToast({ type: 'success', message: `Código enviado a tu WhatsApp. Válido 10 min.` });
        setCountdown(60);
      } else {
        setToast({ type: 'error', message: res.message || 'Error enviando código' });
        setStep('idle');
      }
    } catch (err) {
      const backendMsg = err?.response?.data?.message;
      setToast({ type: 'error', message: backendMsg || 'Error de red al enviar código' });
      setStep('idle');
    }
  };

  const verifyCode = async () => {
    if (code.length !== 6) return;
    setStep('verifying');
    try {
      const res = await usersApi.verifyCode(purpose, code);
      if (res.status === 'ok') {
        setStep('verified');
        setToast({ type: 'success', message: 'Código verificado correctamente' });
        onVerified?.();
        // Auto-hide after 3 seconds
        setTimeout(() => {
          setStep('idle');
          setCode('');
          setToast(null);
        }, 3000);
      } else {
        setToast({ type: 'error', message: res.message || 'Código incorrecto' });
        setStep('sent');
      }
    } catch (err) {
      setToast({ type: 'error', message: 'Error verificando código' });
      setStep('sent');
    }
  };

  return (
    <div className="space-y-2">
      {step === 'idle' && (
        <button
          onClick={sendCode}
          disabled={disabled || !target || countdown > 0}
          className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-[#003366] hover:text-[#002855] disabled:opacity-40 transition-colors"
        >
          <Send size={12} />
          {countdown > 0 ? `Reenviar en ${countdown}s` : 'Verificar vía WhatsApp'}
        </button>
      )}

      {(step === 'sent' || step === 'verifying') && (
        <div className="flex items-center gap-2">
          <input
            type="text"
            maxLength={6}
            value={code}
            onChange={e => setCode(e.target.value.replace(/\D/g, ''))}
            placeholder="000000"
            className="w-24 px-2 py-1.5 text-sm font-bold text-center tracking-widest bg-slate-50 dark:bg-slate-800 outline-none"
            style={{ border: '1.5px solid #E2E8F0' }}
          />
          <button
            onClick={verifyCode}
            disabled={code.length !== 6 || step === 'verifying'}
            className="px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider bg-[#003366] text-white hover:bg-[#002855] disabled:opacity-50 transition-colors"
          >
            {step === 'verifying' ? <Loader2 size={12} className="animate-spin" /> : 'Confirmar'}
          </button>
          <button
            onClick={sendCode}
            disabled={countdown > 0}
            className="text-[10px] font-bold text-slate-400 hover:text-slate-600 disabled:opacity-40"
          >
            {countdown > 0 ? `${countdown}s` : 'Reenviar'}
          </button>
        </div>
      )}

      {step === 'verified' && (
        <div className="flex items-center gap-1.5 text-[10px] font-bold text-emerald-600 uppercase tracking-wider">
          <CheckCircle2 size={12} /> Verificado
        </div>
      )}

      <InlineToast toast={toast} />
    </div>
  );
};

/* ─── Colombian phone helpers ─── */
function formatColPhone(raw) {
  const digits = (raw || '').replace(/\D/g, '');
  // Remove leading 57 if present
  const body = digits.startsWith('57') && digits.length >= 12 ? digits.slice(2) : digits;
  if (body.length !== 10) return raw || '';
  return `+57 ${body.slice(0, 3)} ${body.slice(3, 6)} ${body.slice(6)}`;
}
function stripToDigits(v) {
  return v.replace(/\D/g, '');
}
function handlePhoneInput(v, prev = '') {
  const digits = stripToDigits(v);
  if (digits === '') return '';
  // Colombian mobile numbers: 10 digits starting with 3
  if (digits.length <= 10) {
    if (digits[0] !== '3' && digits.length > 1) return prev; // reject non-mobile
    return digits;
  }
  // If already has 57 prefix
  if (digits.startsWith('57') && digits.length <= 12) return digits;
  return prev;
}

/* ═══════════════════════════════════════════════════════════════════════════ */
const Profile = () => {
  const { user, setUser } = useAuth();
  const fileRef = useRef(null);

  /* ─── Local state ─── */
  const [profile, setProfile] = useState(null);
  const [loadingProfile, setLoadingProfile] = useState(true);

  const [uploading, setUploading] = useState(false);
  const [photoToast, setPhotoToast] = useState(null);

  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [backupEmail, setBackupEmail] = useState('');
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);

  const [verified, setVerified] = useState({ email: false, phone: false, backup: false });
  const [actionToast, setActionToast] = useState(null);
  const [contactToast, setContactToast] = useState(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState(null);

  /* ─── Load extended profile ─── */
  useEffect(() => {
    usersApi.getExtendedProfile().then(res => {
      if (res.status === 'ok' && res.data) {
        setProfile(res.data);
        setEmail(res.data.email || '');
        setPhone(res.data.phone || '');
        setBackupEmail(res.data.backup_email || '');
      }
      setLoadingProfile(false);
    }).catch(() => setLoadingProfile(false));
  }, []);

  /* ─── Photo upload ─── */
  // FIX: comprimir/redimensionar en navegador antes de enviar (evita límite Railway)
  const compressImage = (file, maxWidth = 400, maxHeight = 400, quality = 0.8) => {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = (event) => {
        const img = new Image();
        img.onload = () => {
          let { width, height } = img;
          if (width > maxWidth || height > maxHeight) {
            const ratio = Math.min(maxWidth / width, maxHeight / height);
            width = Math.round(width * ratio);
            height = Math.round(height * ratio);
          }
          const canvas = document.createElement('canvas');
          canvas.width = width;
          canvas.height = height;
          const ctx = canvas.getContext('2d');
          ctx.drawImage(img, 0, 0, width, height);
          canvas.toBlob((blob) => {
            if (blob) {
              resolve(new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), { type: 'image/jpeg' }));
            } else {
              reject(new Error('No se pudo comprimir la imagen'));
            }
          }, 'image/jpeg', quality);
        };
        img.onerror = () => reject(new Error('No se pudo leer la imagen'));
        img.src = event.target.result;
      };
      reader.onerror = () => reject(new Error('No se pudo leer el archivo'));
      reader.readAsDataURL(file);
    });
  };

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
        setProfile(p => p ? { ...p, profile_photo_url: res.photo_url } : p);
        setUser(u => u ? { ...u, profile_photo_url: res.photo_url } : u);
      } else {
        setPhotoToast({ type: 'error', message: res.message || 'Error al subir foto' });
      }
    } catch (err) {
      const backendMsg = err?.response?.data?.message;
      const status = err?.response?.status;
      let msg = backendMsg || err.message || 'Error de red al subir foto';
      if (status === 413) msg = 'La imagen es demasiado grande. Máximo permitido: 10MB';
      else if (status === 400 && !backendMsg) msg = 'Formato o tamaño de imagen no válido';
      setPhotoToast({ type: 'error', message: msg });
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  /* ─── Update contact field ─── */
  const updateField = async (purpose, value) => {
    setActionLoading(true);
    setContactToast(null);
    try {
      const res = await usersApi.updateProfile(purpose, value);
      if (res.status === 'ok') {
        setContactToast({ type: 'success', message: 'Dato actualizado correctamente' });
        setProfile(p => p ? { ...p, [purpose === 'email_change' ? 'email' : purpose === 'phone_change' ? 'phone' : 'backup_email']: value } : p);
      } else {
        setContactToast({ type: 'error', message: res.message || 'Error al actualizar' });
      }
    } catch (err) {
      setContactToast({ type: 'error', message: 'Error de red' });
    } finally {
      setActionLoading(false);
    }
  };

  /* ─── Delete contact field ─── */
  const confirmDelete = (field) => setDeleteConfirm(field);
  const cancelDelete = () => setDeleteConfirm(null);

  const deleteField = async (field) => {
    setActionLoading(true);
    setContactToast(null);
    try {
      const res = await usersApi.deleteField(field);
      if (res.status === 'ok') {
        setContactToast({ type: 'success', message: res.message || 'Eliminado correctamente' });
        setProfile(p => {
          if (!p) return p;
          const next = { ...p };
          if (field === 'phone') { next.phone = ''; next.phone_verified = false; }
          if (field === 'email') { next.email = ''; next.email_verified = false; }
          if (field === 'backup_email') next.backup_email = '';
          return next;
        });
        if (field === 'phone') setPhone('');
        if (field === 'email') setEmail('');
        if (field === 'backup_email') setBackupEmail('');
      } else {
        setContactToast({ type: 'error', message: res.message || 'Error al eliminar' });
      }
    } catch (err) {
      setContactToast({ type: 'error', message: 'Error de red' });
    } finally {
      setActionLoading(false);
      setDeleteConfirm(null);
    }
  };

  /* ─── Change password ─── */
  const changePassword = async (e) => {
    e.preventDefault();
    if (newPassword.length < 8) {
      setActionToast({ type: 'error', message: 'Mínimo 8 caracteres para la nueva contraseña' });
      return;
    }
    if (newPassword !== confirmPassword) {
      setActionToast({ type: 'error', message: 'Las contraseñas nuevas no coinciden' });
      return;
    }
    setActionLoading(true);
    setActionToast(null);
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
    } catch (err) {
      setActionToast({ type: 'error', message: 'Error de red o contraseña incorrecta' });
    } finally {
      setActionLoading(false);
    }
  };

  const initial = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';

  if (loadingProfile) {
    return (
      <div className="space-y-5">
        <div className="h-6 w-32 bg-slate-200 dark:bg-slate-800 animate-pulse" />
        <div className="h-40 bg-slate-100 dark:bg-slate-800 animate-pulse" style={{ border: '1.5px solid #E2E8F0' }} />
      </div>
    );
  }

  return (
    <div className="space-y-5 max-w-3xl">
      {/* Header */}
      <div>
        <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', letterSpacing: '-0.01em' }} className="dark:text-slate-200">Perfil</p>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none', marginTop: '4px' }}>
          Gestión de cuenta institucional
        </p>
      </div>

      {/* ─── Sección 1: Información Personal ─── */}
      <SectionCard title="Información Personal" subtitle="Datos básicos de tu cuenta">
        <div className="flex items-center gap-5">
          <div
            className="relative flex items-center justify-center w-20 h-20 text-2xl font-black text-white overflow-hidden cursor-pointer group shrink-0"
            style={{ backgroundColor: '#003366' }}
            onClick={() => fileRef.current?.click()}
          >
            {profile?.profile_photo_url ? (
              <img src={profile.profile_photo_url} alt="" className="w-full h-full object-cover" />
            ) : (
              initial
            )}
            <div className="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
              <Camera size={20} className="text-white" />
            </div>
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-black text-slate-800 dark:text-white uppercase tracking-tight">{user?.nombre}</p>
            <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{user?.role}</p>
            <p className="text-[10px] text-slate-400 mt-1 truncate">{user?.school_name}</p>
            <button
              onClick={() => fileRef.current?.click()}
              disabled={uploading}
              className="mt-2 flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider bg-[#003366] hover:bg-[#002855] text-white rounded transition-colors disabled:opacity-60"
            >
              {uploading ? <Loader2 size={12} className="animate-spin" /> : <Camera size={12} />}
              Cambiar foto
            </button>
            <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={handleFileChange} />
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <InfoRow label="Institución" value={user?.school_name || '—'} />
          <InfoRow label="Rol" value={user?.role || '—'} />
          <InfoRow label="Jornada" value={profile?.work_shift ? capitalize(profile.work_shift) : '—'} />
        </div>

        <InlineToast toast={photoToast} />
      </SectionCard>

      {/* ─── Sección 2: Seguridad ─── */}
      <SectionCard title="Seguridad" subtitle="Contraseña de acceso">
        <form onSubmit={changePassword} className="space-y-4">
          <TextField
            label="Contraseña actual"
            type={showCurrent ? 'text' : 'password'}
            value={currentPassword}
            onChange={e => setCurrentPassword(e.target.value)}
            placeholder="••••••••"
            rightElement={
              <button type="button" onClick={() => setShowCurrent(v => !v)} className="text-slate-400 hover:text-slate-600">
                {showCurrent ? <EyeOff size={14} /> : <Eye size={14} />}
              </button>
            }
          />
          <TextField
            label="Nueva contraseña (mín. 8 caracteres)"
            type={showNew ? 'text' : 'password'}
            value={newPassword}
            onChange={e => setNewPassword(e.target.value)}
            placeholder="••••••••"
            rightElement={
              <button type="button" onClick={() => setShowNew(v => !v)} className="text-slate-400 hover:text-slate-600">
                {showNew ? <EyeOff size={14} /> : <Eye size={14} />}
              </button>
            }
          />
          <TextField
            label="Confirmar nueva contraseña"
            type={showConfirm ? 'text' : 'password'}
            value={confirmPassword}
            onChange={e => setConfirmPassword(e.target.value)}
            placeholder="••••••••"
            rightElement={
              <button type="button" onClick={() => setShowConfirm(v => !v)} className="text-slate-400 hover:text-slate-600">
                {showConfirm ? <EyeOff size={14} /> : <Eye size={14} />}
              </button>
            }
          />
          <button
            type="submit"
            disabled={actionLoading || !currentPassword || newPassword.length < 8 || !confirmPassword}
            className="flex items-center gap-2 px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider bg-[#003366] hover:bg-[#002855] text-white transition-colors disabled:opacity-50"
          >
            {actionLoading ? <Loader2 size={12} className="animate-spin" /> : <Lock size={12} />}
            Actualizar contraseña
          </button>
        </form>
        <InlineToast toast={actionToast} />
      </SectionCard>

      {/* ─── Sección 3: Contacto ─── */}
      <SectionCard title="Contacto" subtitle="Correo, teléfono y verificación por WhatsApp">
        {/* Email */}
        <div className="space-y-3">
          <TextField
            label="Correo electrónico"
            type="email"
            value={email}
            onChange={e => setEmail(e.target.value)}
            placeholder="usuario@institucion.edu.co"
          />
          <div className="flex items-center gap-2">
            <button
              onClick={() => updateField('email_change', email)}
              disabled={actionLoading || !email || email === profile?.email}
              className="flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider bg-emerald-600 hover:bg-emerald-700 text-white transition-colors disabled:opacity-50"
            >
              <Save size={12} /> Guardar
            </button>

          </div>
        </div>

        <div style={{ height: '1px', backgroundColor: '#F1F5F9' }} />

        {/* Phone */}
        <div className="space-y-3">
          {profile?.phone && profile?.phone_verified ? (
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <span className="text-sm font-bold text-slate-800 dark:text-white">{formatColPhone(profile.phone)}</span>
                <span className="inline-flex items-center gap-1 text-[10px] font-bold text-emerald-600 uppercase tracking-wider">
                  <CheckCircle2 size={12} /> Verificado
                </span>
              </div>
              <button
                onClick={() => confirmDelete('phone')}
                disabled={actionLoading}
                className="flex items-center gap-1 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-red-600 hover:text-red-700 transition-colors disabled:opacity-50"
                title="Eliminar teléfono"
              >
                <Trash2 size={12} />
              </button>
            </div>
          ) : (
            <>
              <TextField
                label="Teléfono (WhatsApp)"
                type="tel"
                value={phone}
                onChange={e => setPhone(handlePhoneInput(e.target.value, phone))}
                placeholder="300 123 4567"
              />
              {phone && (
                <p className="text-[10px] font-semibold text-slate-500">
                  Se enviará a: {formatColPhone(phone)}
                </p>
              )}
              <div className="flex items-center justify-between">
                <OtpBlock
                  purpose="phone_change"
                  target={phone}
                  label="teléfono"
                  disabled={!phone || phone === profile?.phone}
                  onVerified={() => setVerified(v => ({ ...v, phone: true }))}
                />
                <div className="flex items-center gap-2">
                  {verified.phone && (
                    <button
                      onClick={() => updateField('phone_change', phone)}
                      disabled={actionLoading}
                      className="flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider bg-emerald-600 hover:bg-emerald-700 text-white transition-colors disabled:opacity-50"
                    >
                      <Save size={12} /> Guardar
                    </button>
                  )}
                </div>
              </div>
            </>
          )}
        </div>

        <div style={{ height: '1px', backgroundColor: '#F1F5F9' }} />

        {/* Backup email */}
        <div className="space-y-3">
          <TextField
            label="Correo de respaldo"
            type="email"
            value={backupEmail}
            onChange={e => setBackupEmail(e.target.value)}
            placeholder="personal@gmail.com"
          />
          <div className="flex items-center justify-between">
            <OtpBlock
              purpose="backup_email"
              target={backupEmail}
              label="correo de respaldo"
              disabled={!backupEmail || backupEmail === profile?.backup_email}
              onVerified={() => setVerified(v => ({ ...v, backup: true }))}
            />
            <div className="flex items-center gap-2">
              {verified.backup && (
                <button
                  onClick={() => updateField('backup_email', backupEmail)}
                  disabled={actionLoading}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider bg-emerald-600 hover:bg-emerald-700 text-white transition-colors disabled:opacity-50"
                >
                  <Save size={12} /> Guardar
                </button>
              )}
              {profile?.backup_email && (
                <button
                  onClick={() => confirmDelete('backup_email')}
                  disabled={actionLoading}
                  className="flex items-center gap-1 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-red-600 hover:text-red-700 transition-colors disabled:opacity-50"
                  title="Eliminar correo de respaldo"
                >
                  <Trash2 size={12} />
                </button>
              )}
            </div>
          </div>
        </div>
        <InlineToast toast={contactToast} />
      </SectionCard>

      {/* Delete confirmation modal */}
      {deleteConfirm && (
        <div className="fixed inset-0 z-50 flex items-center justify-center" style={{ backgroundColor: 'rgba(2,6,23,0.45)', backdropFilter: 'blur(2px)' }}>
          <div className="bg-white dark:bg-slate-900 p-6 w-full max-w-sm" style={{ border: '1.5px solid #E2E8F0' }}>
            <div className="flex items-center gap-2 mb-4">
              <div className="w-8 h-8 flex items-center justify-center bg-red-50" style={{ border: '1px solid #FECACA' }}>
                <AlertTriangle size={16} className="text-red-500" />
              </div>
              <p className="text-xs font-black uppercase tracking-tight text-slate-800 dark:text-white">Confirmar eliminación</p>
            </div>
            <p className="text-xs text-slate-500 mb-6 leading-relaxed">
              ¿Eliminar {deleteConfirm === 'phone' ? 'el número de teléfono' : deleteConfirm === 'email' ? 'el correo electrónico' : 'el correo de respaldo'}? Esta acción no se puede deshacer.
            </p>
            <div className="flex gap-3">
              <button
                onClick={cancelDelete}
                className="flex-1 py-2.5 text-[10px] font-bold uppercase tracking-wider text-slate-500 hover:text-slate-700 transition-colors"
                style={{ border: '1.5px solid #E2E8F0' }}
              >
                Cancelar
              </button>
              <button
                onClick={() => deleteField(deleteConfirm)}
                disabled={actionLoading}
                className="flex-1 py-2.5 text-[10px] font-bold uppercase tracking-wider text-white bg-red-600 hover:bg-red-700 transition-colors disabled:opacity-50"
              >
                {actionLoading ? <Loader2 size={12} className="animate-spin mx-auto" /> : 'Eliminar'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

function InfoRow({ label, value }) {
  return (
    <div className="px-4 py-3 bg-slate-50 dark:bg-slate-800/50" style={{ border: '1px solid #F1F5F9' }}>
      <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">{label}</p>
      <p className="text-xs font-semibold text-slate-700 dark:text-slate-200">{value}</p>
    </div>
  );
}

function capitalize(s) {
  if (!s) return '';
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export default Profile;
