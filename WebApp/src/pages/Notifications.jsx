/**
 * Notifications page / NEXO Institucional
 * Responsabilidad: Bandeja de notificaciones del usuario: lista, lectura, limpieza,
 * enlace a TrackingModal y emisión del conteo no leído al Layout.
 * Dependencias: React, react-router-dom, framer-motion, useAuth, notificationsApi, trackingApi.
 */
import { useState, useEffect } from 'react';
import { Bell, CheckCircle2, Info, User, AlertTriangle, Loader2, Eye, X, Trash2 } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { ROLES } from '../config/roles';
import { notificationsApi } from '../api/notifications';
import { trackingApi } from '../api/tracking';

function parseMeta(raw) {
  if (!raw) return null;
  if (typeof raw === 'object') return raw;
  try { return JSON.parse(raw); } catch { return null; }
}

// Emite el conteo al Layout para mostrar/ocultar el punto verde
function emitCount(count) {
  window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count } }));
}

// Clave para guardar el último conteo de notificaciones vistas
const LAST_COUNT_KEY = 'nexo:notif-last-count';

const Notifications = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading]       = useState(true);
  const [clearing, setClearing]     = useState(false);
  const [detailNotif, setDetailNotif] = useState(null);

  const isStaff = user?.role === ROLES.PORTERO || user?.role === ROLES.AUXILIAR;

  useEffect(() => {
    const fetchNotifications = async () => {
      try {
        const data = await notificationsApi.getAll();
        const list = Array.isArray(data) ? data : [];
        setNotifications(list);
        // Al entrar a la pantalla de notificaciones, marcarlas como vistas:
        // el punto verde desaparece y se persiste el conteo actual
        emitCount(0);
        sessionStorage.setItem(LAST_COUNT_KEY, '0');
      } catch (error) {
        console.error('Error fetching notifications', error);
      } finally {
        setLoading(false);
      }
    };
    fetchNotifications();
  }, []);

  const handleClear = async () => {
    if (notifications.length === 0) return;
    setClearing(true);
    try {
      await notificationsApi.clearAll();
      setNotifications([]);
      emitCount(0);
    } catch (error) {
      console.error('Error clearing notifications', error);
    } finally {
      setClearing(false);
    }
  };

  const getIcon = (type) => {
    switch (type) {
      case 'SOS':     return { icon: AlertTriangle, color: '#DC2626', bg: '#FEF2F2', border: '#FECACA' };
      case 'INFO':    return { icon: Info,          color: '#003366', bg: '#F0F5FF', border: '#BFDBFE' };
      case 'SUCCESS': return { icon: CheckCircle2,  color: '#00A67E', bg: '#ECFDF5', border: '#A7F3D0' };
      default:        return { icon: Bell,           color: '#64748B', bg: '#F8FAFC', border: '#E2E8F0' };
    }
  };

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-3">
        <Loader2 size={28} strokeWidth={1.5} className="text-[#003366] animate-spin" />
        <p className="text-xs text-slate-400 font-medium uppercase tracking-widest">Cargando…</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', letterSpacing: '-0.01em' }} className="dark:text-slate-200">
          {isStaff ? 'Centro de Órdenes' : 'Notificaciones'}
        </p>
        <div className="flex items-center gap-4 mt-1">
          <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none' }}>
            {isStaff ? 'Instrucciones directas de directivos' : 'Alertas y mensajes del sistema institucional'}
          </p>
          {/* Botón vaciar — inline junto al subtítulo */}
          {notifications.length > 0 && (
            <button
              onClick={handleClear}
              disabled={clearing}
              className="flex items-center gap-1 shrink-0 transition-colors"
              style={{
                fontSize: '9px', fontWeight: 700, letterSpacing: '0.18em', textTransform: 'uppercase',
                color: clearing ? '#CBD5E1' : '#94A3B8',
                cursor: clearing ? 'not-allowed' : 'pointer',
                background: 'none', border: 'none', padding: '0',
              }}
            >
              {clearing
                ? <Loader2 size={10} className="animate-spin" />
                : <Trash2 size={10} strokeWidth={2} />
              }
              Vaciar
            </button>
          )}
        </div>
      </div>

      {/* Card */}
      <div className="bg-white dark:bg-slate-900" style={{ border: '1.5px solid #E2E8F0' }}>
        <AnimatePresence>
          {notifications.length > 0 ? (
            <div>
              {notifications.map((notif, i) => {
                const style = getIcon(notif.type);
                return (
                  <motion.div
                    key={notif.id ?? notif.notification_id ?? i}
                    initial={{ opacity: 0, y: 4 }}
                    animate={{ opacity: 1, y: 0 }}
                    exit={{ opacity: 0, x: -8 }}
                    transition={{ duration: 0.2, delay: i * 0.04 }}
                    className="flex items-start gap-4 px-5 py-4 hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors"
                    style={{ borderBottom: i < notifications.length - 1 ? '1px solid #F1F5F9' : 'none' }}
                  >
                    {/* Icon */}
                    <div
                      className="shrink-0 flex items-center justify-center w-10 h-10"
                      style={{ backgroundColor: style.bg, border: `1.5px solid ${style.border}` }}
                    >
                      <style.icon size={18} strokeWidth={2} style={{ color: style.color }} />
                    </div>

                    {/* Content */}
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-3 mb-1">
                        <h3 className="text-xs font-black uppercase tracking-tight text-slate-800 dark:text-white truncate">
                          {notif.title}
                        </h3>
                        <span className="shrink-0 text-[10px] font-bold text-slate-400 uppercase tracking-wider whitespace-nowrap">
                          {notif.time}
                        </span>
                      </div>
                      <p className="text-xs text-slate-600 dark:text-slate-400 leading-relaxed">
                        {notif.desc}
                      </p>
                      {parseMeta(notif.metadata_json) && (
                        <div className="mt-2 flex flex-wrap items-center gap-4">
                          <button
                            onClick={() => setDetailNotif(notif)}
                            className={`inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider transition-colors ${
                              notif.type === 'SOS' ? 'text-red-600 hover:text-red-700' : 'text-[#003366] hover:text-[#002855]'
                            }`}
                          >
                            <Eye size={12} /> Ver detalles
                          </button>
                        </div>
                      )}
                      {notif.sender && (
                        <div className="mt-2 flex items-center gap-2">
                          <div className="w-5 h-5 flex items-center justify-center bg-slate-100 dark:bg-slate-800" style={{ border: '1px solid #E2E8F0' }}>
                            <User size={10} className="text-slate-400" />
                          </div>
                          <span className="text-[10px] font-bold text-slate-400 uppercase tracking-wider">Origen: {notif.sender}</span>
                        </div>
                      )}
                    </div>
                  </motion.div>
                );
              })}
            </div>
          ) : (
            <motion.div
              key="empty"
              initial={{ opacity: 0 }} animate={{ opacity: 1 }}
              className="flex flex-col items-center justify-center py-20 gap-4"
            >
              <div className="w-14 h-14 flex items-center justify-center bg-slate-50 border border-slate-100">
                <Bell size={24} strokeWidth={1.5} className="text-slate-300" />
              </div>
              <div className="text-center space-y-1">
                <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Sin notificaciones nuevas</p>
                <p className="text-[10px] text-slate-400 uppercase tracking-wider">El buzón se encuentra vacío por el momento</p>
              </div>
            </motion.div>
          )}
        </AnimatePresence>
      </div>

      {/* Detail Drawer */}
      <AnimatePresence>
        {detailNotif && (
          <>
            <motion.div
              initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
              transition={{ duration: 0.2 }}
              className="fixed z-40"
              style={{ top: '52px', left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(2,6,23,0.45)' }}
              onClick={() => setDetailNotif(null)}
            />
            <motion.div
              initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
              transition={{ type: 'spring', damping: 30, stiffness: 300 }}
              className="fixed right-0 z-50 bg-white dark:bg-slate-900 w-full overflow-y-auto"
              style={{ top: '56px', bottom: 0, maxWidth: '420px', borderLeft: '1.5px solid #E2E8F0' }}
            >
              <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
                <div>
                  <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: '#1E293B' }}>
                    {detailNotif.title}
                  </p>
                  <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                    Detalles de la notificación
                  </p>
                </div>
                <button onClick={() => setDetailNotif(null)} className="p-1.5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                  <X size={18} strokeWidth={2} />
                </button>
              </div>
              <div className="p-6 space-y-5">
                {(() => {
                  const meta = parseMeta(detailNotif.metadata_json);
                  if (!meta) return <p className="text-xs text-slate-400">Sin detalles disponibles.</p>;
                  const fields = [
                    meta.student_name  && { label: 'Estudiante',      value: meta.student_name },
                    meta.group_name    && { label: 'Grupo',            value: meta.group_name },
                    meta.teacher_name  && { label: 'Generado por',     value: meta.teacher_name },
                    meta.reporter_name && { label: 'Reportado por',    value: meta.reporter_name },
                    meta.sender_name   && { label: 'Remitente',        value: meta.sender_name },
                    meta.reporter_role && !meta.teacher_name && !meta.sender_name && { label: 'Rol', value: meta.reporter_role },
                    meta.location      && { label: 'Ubicación',        value: meta.location },
                    meta.message && meta.action === 'sos'       && { label: 'Mensaje', value: meta.message },
                    meta.reason        && { label: meta.action === 'solicitud' ? 'Mensaje' : 'Motivo / Detalle', value: meta.reason },
                    meta.motivo        && { label: 'Motivo del reagendamiento', value: meta.motivo },
                    meta.time_start    && { label: 'Desde',            value: meta.time_start },
                    meta.time_end      && { label: 'Hasta',            value: meta.time_end },
                  ].filter(Boolean);
                  return fields.map((f, i) => (
                    <div key={i}>
                      <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }} className="mb-1">{f.label}</p>
                      <p className="text-sm font-bold text-slate-800 dark:text-slate-200">{f.value}</p>
                    </div>
                  ));
                })()}

                {/* Botón para iniciar seguimiento desde notificación */}
                {(() => {
                  const meta = parseMeta(detailNotif.metadata_json);
                  if (meta?.action === 'iniciar_seguimiento' && meta?.student_id) {
                    return (
                      <div className="pt-4 mt-2" style={{ borderTop: '1.5px solid #F1F5F9' }}>
                        <button
                          onClick={async () => {
                            try {
                              const data = await trackingApi.startTracking(meta.student_id);
                              if (data.status === 'ok') {
                                setDetailNotif(null);
                                navigate('/seguimiento');
                                // Refrescar notificaciones
                                window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count: -1 } }));
                              } else {
                                alert('Error al iniciar seguimiento: ' + (data.message || 'Error del servidor'));
                              }
                            } catch (e) {
                              console.error(e);
                              alert('Error al iniciar el seguimiento');
                            }
                          }}
                          className="w-full py-3 text-xs font-bold uppercase text-white transition-colors"
                          style={{ backgroundColor: '#003366', letterSpacing: '0.15em' }}
                        >
                          Empezar Seguimiento
                        </button>
                      </div>
                    );
                  }
                  return null;
                })()}
              </div>
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
};

export default Notifications;
