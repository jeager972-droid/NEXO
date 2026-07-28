/**
 * SCR-PRO-01 Profile
 * B-09: una sola acción primaria por sección.
 * Usa PageHeader, PasswordInput, ConfirmDialog, humanizeError.
 */
import { useState, useRef, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import { usersApi } from '../api/users';
import { Camera, Mail, ShieldCheck, Key, CheckCircle2, AlertCircle, Loader2 } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Card } from '../components/ui/Card';
import { Input, PasswordInput } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { Skeleton } from '../components/ui/Skeleton';
import { ConfirmDialog } from '../components/ui/Overlay';
import { humanizeError } from '../utils/messages';

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

const Toast = ({ toast }) => {
  if (!toast) return null;
  return (
    <motion.div initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }} className="mt-3 flex items-center gap-2 text-body-sm">
      {toast.type === 'success' ? <CheckCircle2 size={16} className="text-[var(--nx-success)]" /> : <AlertCircle size={16} className="text-[var(--nx-danger)]" />}
      <span className={toast.type === 'success' ? 'text-[var(--nx-success)]' : 'text-[var(--nx-danger)]'}>{toast.message}</span>
    </motion.div>
  );
};

const OtpBlock = ({ purpose, target, label, onVerified, disabled }) => {
  const [code, setCode] = useState('');
  const [step, setStep] = useState('idle');
  const [toast, setToast] = useState(null);
  const [countdown, setCountdown] = useState(0);

  useEffect(() => {
    if (countdown <= 0) return;
    const t = setTimeout(() => setCountdown((c) => c - 1), 1000);
    return () => clearTimeout(t);
  }, [countdown]);

  const sendCode = async () => {
    setStep('sent');
    setToast({ type: 'info', message: 'Enviando código por WhatsApp…' });
    try {
      const res = await usersApi.sendVerificationCode(purpose, target);
      if (res.status === 'ok') {
        setToast({ type: 'success', message: 'Código enviado. Válido 10 min.' });
        setCountdown(60);
      } else {
        setToast({ type: 'error', message: res.message || 'No se pudo enviar' });
      }
    } catch (e) {
      setToast({ type: 'error', message: e.message || 'Error de red' });
    }
  };

  const verifyCode = async () => {
    if (code.length !== 6) return;
    setStep('verifying');
    try {
      const res = await usersApi.verifyCode(purpose, code);
      if (res.status === 'ok') {
        setStep('verified');
        setToast({ type: 'success', message: 'Código verificado' });
        onVerified?.();
      } else {
        setStep('sent');
        setToast({ type: 'error', message: res.message || 'Código inválido' });
      }
    } catch (e) {
      setStep('sent');
      setToast({ type: 'error', message: e.message || 'Error de red' });
    }
  };

  return (
    <div className="space-y-2">
      {step === 'idle' && (
        <Button variant="secondary" size="sm" onClick={sendCode} disabled={disabled} leftIcon={<ShieldCheck size={14} />}>
          Verificar {label}
        </Button>
      )}
      {(step === 'sent' || step === 'verifying') && (
        <div className="flex items-end gap-2">
          <Input
            label={`Código de 6 dígitos`}
            value={code}
            onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
            placeholder="000000"
            disabled={step === 'verifying'}
          />
          <Button size="sm" loading={step === 'verifying'} onClick={verifyCode}>Verificar</Button>
        </div>
      )}
      {step === 'verified' && <Badge scheme="success" dot>Verificado</Badge>}
      {countdown > 0 && <p className="text-caption text-[var(--nx-text-muted)]">Reenviar en {countdown}s</p>}
      <Toast toast={toast} />
    </div>
  );
};

const Profile = () => {
  const { user, setUser } = useAuth();
  const fileRef = useRef(null);

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

  const [verified, setVerified] = useState({ email: false, phone: false, backup: false });
  const [actionToast, setActionToast] = useState(null);
  const [contactToast, setContactToast] = useState(null);
  const [actionLoading, setActionLoading] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState(null);

  useEffect(() => {
    usersApi.getExtendedProfile().then((res) => {
      if (res.status === 'ok' && res.data) {
        setProfile(res.data);
        setEmail(res.data.email || '');
        setPhone(res.data.phone || '');
        setBackupEmail(res.data.backup_email || '');
        setVerified({
          email: !!res.data.email_verified,
          phone: !!res.data.phone_verified,
          backup: !!res.data.backup_email_verified,
        });
      }
      setLoadingProfile(false);
    }).catch(() => setLoadingProfile(false));
  }, []);

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

  const updateContact = async (purpose, value) => {
    if (!value.trim()) return;
    setActionLoading(true);
    setContactToast(null);
    try {
      const res = await usersApi.updateProfile(purpose, value);
      if (res.status === 'ok') {
        setContactToast({ type: 'success', message: 'Dato actualizado correctamente' });
        setProfile((p) => (p ? { ...p, [purpose === 'email_change' ? 'email' : purpose === 'phone_change' ? 'phone' : 'backup_email']: value } : p));
        setVerified((v) => ({ ...v, [purpose === 'email_change' ? 'email' : purpose === 'phone_change' ? 'phone' : 'backup']: false }));
      } else {
        setContactToast({ type: 'error', message: res.message || 'Error al actualizar' });
      }
    } catch (e) {
      setContactToast({ type: 'error', message: humanizeError(e, 'Error al actualizar') });
    } finally {
      setActionLoading(false);
    }
  };

  const deleteField = async (field) => {
    setActionLoading(true);
    setContactToast(null);
    try {
      const res = await usersApi.deleteField(field);
      if (res.status === 'ok') {
        setContactToast({ type: 'success', message: res.message || 'Eliminado correctamente' });
        setProfile((p) => (p ? { ...p, [field]: null } : p));
      } else {
        setContactToast({ type: 'error', message: res.message || 'Error al eliminar' });
      }
    } catch (e) {
      setContactToast({ type: 'error', message: humanizeError(e, 'Error al eliminar') });
    } finally {
      setActionLoading(false);
      setDeleteConfirm(null);
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

  const initial = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';

  if (loadingProfile) {
    return (
      <div className="space-y-6 max-w-3xl">
        <Skeleton className="h-40 w-full" />
        <Skeleton className="h-64 w-full" />
      </div>
    );
  }

  return (
    <div className="space-y-8 max-w-3xl">
      <Card className="flex items-center gap-5 p-5">
        <div className="relative">
          {profile?.profile_photo_url ? (
            <img src={profile.profile_photo_url} alt="Foto" className="h-20 w-20 rounded-full object-cover" />
          ) : (
            <div className="flex h-20 w-20 items-center justify-center rounded-full bg-[var(--nx-accent)] text-[var(--nx-accent-text)] text-h1">{initial}</div>
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
          <p className="text-body text-[var(--nx-text-muted)]">{user?.role}</p>
          <Toast toast={photoToast} />
        </div>
      </Card>

      <Card className="space-y-5 p-5">
        <p className="text-h3 text-[var(--nx-text)] flex items-center gap-2"><Mail size={18} className="text-[var(--nx-accent)]" /> Contacto</p>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div className="space-y-2">
            <Input label="Correo" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
            <div className="flex gap-2">
              <Button size="sm" loading={actionLoading} onClick={() => updateContact('email_change', email)}>Guardar</Button>
              <Button size="sm" variant="quiet" onClick={() => setDeleteConfirm('email')}>Eliminar</Button>
            </div>
            <OtpBlock purpose="email" target={email} label="correo" onVerified={() => setVerified((v) => ({ ...v, email: true }))} disabled={!email || verified.email} />
            {verified.email && <Badge scheme="success" dot>Verificado</Badge>}
          </div>
          <div className="space-y-2">
            <Input label="Teléfono" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
            <div className="flex gap-2">
              <Button size="sm" loading={actionLoading} onClick={() => updateContact('phone_change', phone)}>Guardar</Button>
              <Button size="sm" variant="quiet" onClick={() => setDeleteConfirm('phone')}>Eliminar</Button>
            </div>
            <OtpBlock purpose="phone" target={phone} label="teléfono" onVerified={() => setVerified((v) => ({ ...v, phone: true }))} disabled={!phone || verified.phone} />
            {verified.phone && <Badge scheme="success" dot>Verificado</Badge>}
          </div>
          <div className="space-y-2 md:col-span-2">
            <Input label="Correo de respaldo" type="email" value={backupEmail} onChange={(e) => setBackupEmail(e.target.value)} />
            <div className="flex gap-2">
              <Button size="sm" loading={actionLoading} onClick={() => updateContact('backup_email_change', backupEmail)}>Guardar</Button>
              <Button size="sm" variant="quiet" onClick={() => setDeleteConfirm('backup_email')}>Eliminar</Button>
            </div>
            <OtpBlock purpose="backup_email" target={backupEmail} label="correo de respaldo" onVerified={() => setVerified((v) => ({ ...v, backup: true }))} disabled={!backupEmail || verified.backup} />
            {verified.backup && <Badge scheme="success" dot>Verificado</Badge>}
          </div>
        </div>
        <Toast toast={contactToast} />
      </Card>

      <Card className="space-y-5 p-5">
        <p className="text-h3 text-[var(--nx-text)] flex items-center gap-2"><Key size={18} className="text-[var(--nx-accent)]" /> Cambiar contraseña</p>
        <form onSubmit={changePassword} className="space-y-4">
          <PasswordInput label="Contraseña actual" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} />
          <PasswordInput label="Nueva contraseña" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} />
          <PasswordInput label="Confirmar nueva contraseña" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} />
          <Button type="submit" loading={actionLoading}>Actualizar contraseña</Button>
          <Toast toast={actionToast} />
        </form>
      </Card>

      <AnimatePresence>
        {deleteConfirm && (
          <ConfirmDialog
            title="¿Eliminar dato de contacto?"
            description="Se eliminará el contacto seleccionado de tu perfil. Esta acción no se puede deshacer."
            confirmLabel="Eliminar"
            destructive
            loading={actionLoading}
            onConfirm={() => deleteField(deleteConfirm)}
            onClose={() => setDeleteConfirm(null)}
          />
        )}
      </AnimatePresence>
    </div>
  );
};

export default Profile;
