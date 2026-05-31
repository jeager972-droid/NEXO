import { useState, useEffect } from 'react';
import { Bell, CheckCircle2, Info, User, AlertTriangle, Loader2 } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '../hooks/useAuth';
import { notificationsApi } from '../api/notifications';

const Notifications = () => {
  const { user } = useAuth();
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);

  const isStaff = user?.role === 'PORTERO' || user?.role === 'AUXILIAR';

  useEffect(() => {
    const fetchNotifications = async () => {
      try {
        const data = await notificationsApi.getAll();
        setNotifications(data);
      } catch (error) {
        console.error('Error fetching notifications', error);
      } finally {
        setLoading(false);
      }
    };
    fetchNotifications();
  }, []);

  const getIcon = (type) => {
    switch (type) {
      case 'SOS': return { icon: AlertTriangle, color: '#DC2626', bg: '#FEF2F2', border: '#FECACA' };
      case 'INFO': return { icon: Info, color: '#003366', bg: '#F0F5FF', border: '#BFDBFE' };
      case 'SUCCESS': return { icon: CheckCircle2, color: '#00A67E', bg: '#ECFDF5', border: '#A7F3D0' };
      default: return { icon: Bell, color: '#64748B', bg: '#F8FAFC', border: '#E2E8F0' };
    }
  };

  if (loading) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-3">
        <Loader2 size={28} strokeWidth={1.5} className="text-[#003366] animate-spin" />
        <p className="text-xs text-slate-400 font-medium uppercase tracking-widest">Sincronizando datos institucionales…</p>
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
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none', marginTop: '4px' }}>
          {isStaff ? 'Instrucciones directas de directivos' : 'Alertas y mensajes del sistema institucional'}
        </p>
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
                    key={notif.id}
                    initial={{ opacity: 0, y: 4 }}
                    animate={{ opacity: 1, y: 0 }}
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
            <div className="flex flex-col items-center justify-center py-20 gap-4">
              <div className="w-14 h-14 flex items-center justify-center bg-slate-50 border border-slate-100">
                <Bell size={24} strokeWidth={1.5} className="text-slate-300" />
              </div>
              <div className="text-center space-y-1">
                <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Sin notificaciones nuevas</p>
                <p className="text-[10px] text-slate-400 uppercase tracking-wider">El buzón se encuentra vacío por el momento</p>
              </div>
            </div>
          )}
        </AnimatePresence>
      </div>
    </div>
  );
};

export default Notifications;

