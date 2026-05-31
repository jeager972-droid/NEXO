import { useState, useRef } from 'react';
import { useAuth } from '../hooks/useAuth';
import { usersApi } from '../api/users';
import { Camera, Loader2, CheckCircle2, AlertTriangle, User } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

const Profile = () => {
  const { user, login } = useAuth();
  const [uploading, setUploading] = useState(false);
  const [toast, setToast] = useState(null);
  const fileRef = useRef(null);

  const initial = user?.nombre?.charAt(0)?.toUpperCase() ?? '?';
  const roleDisplay = user?.role ?? '';

  const handleFileChange = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    setToast(null);
    try {
      const res = await usersApi.uploadPhoto(file);
      if (res.status === 'ok') {
        // Update local user context with new photo
        const updatedUser = { ...user, profile_photo_url: res.photo_url };
        // We can't directly update auth context, but the next /auth/me will reflect it
        setToast({ type: 'success', message: 'Foto de perfil actualizada' });
      } else {
        setToast({ type: 'error', message: res.message || 'Error al subir foto' });
      }
    } catch (err) {
      setToast({ type: 'error', message: 'Error de red al subir foto' });
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', letterSpacing: '-0.01em' }} className="dark:text-slate-200">Perfil</p>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none', marginTop: '4px' }}>
          Gestión de cuenta institucional
        </p>
      </div>

      <div className="bg-white dark:bg-slate-900 p-6 space-y-6" style={{ border: '1.5px solid #E2E8F0' }}>
        {/* Avatar */}
        <div className="flex items-center gap-5">
          <div
            className="relative flex items-center justify-center w-20 h-20 text-2xl font-black text-white overflow-hidden cursor-pointer group"
            style={{ backgroundColor: '#003366' }}
            onClick={() => fileRef.current?.click()}
          >
            {user?.profile_photo_url ? (
              <img src={user.profile_photo_url} alt="" className="w-full h-full object-cover" />
            ) : (
              initial
            )}
            <div className="absolute inset-0 bg-black/40 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
              <Camera size={20} className="text-white" />
            </div>
          </div>
          <div>
            <p className="text-sm font-black text-slate-800 dark:text-white uppercase tracking-tight">{user?.nombre}</p>
            <p className="text-[10px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{roleDisplay}</p>
            <p className="text-[10px] text-slate-400 mt-1">{user?.email}</p>
            <button
              onClick={() => fileRef.current?.click()}
              disabled={uploading}
              className="mt-2 flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider bg-[#003366] hover:bg-[#002855] text-white rounded transition-colors disabled:opacity-60"
            >
              {uploading ? <Loader2 size={12} className="animate-spin" /> : <Camera size={12} />}
              Cambiar foto
            </button>
            <input
              ref={fileRef}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={handleFileChange}
            />
          </div>
        </div>

        {/* Info */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <InfoRow label="Institución" value={user?.school_name || '—'} />
          <InfoRow label="Correo" value={user?.email || '—'} />
          <InfoRow label="Rol" value={roleDisplay} />
          <InfoRow label="Jornada" value={user?.work_shift ? capitalize(user.work_shift) : '—'} />
        </div>

        {/* Toast */}
        <AnimatePresence>
          {toast && (
            <motion.div
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: 10 }}
              transition={{ duration: 0.2 }}
              className={`px-4 py-2.5 rounded-lg text-xs font-semibold flex items-center gap-2 ${
                toast.type === 'success'
                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                  : 'bg-red-50 text-red-700 border border-red-200'
              }`}
            >
              {toast.type === 'success' ? <CheckCircle2 size={14} /> : <AlertTriangle size={14} />}
              {toast.message}
            </motion.div>
          )}
        </AnimatePresence>
      </div>
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
