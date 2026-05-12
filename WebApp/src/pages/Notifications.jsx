import { useState, useEffect } from 'react';
import { Bell, CheckCircle2, Info, User, AlertTriangle } from 'lucide-react';
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
      case 'SOS': return { icon: AlertTriangle, color: 'text-red-600', bg: 'bg-red-50', darkColor: 'dark:text-red-400', darkBg: 'dark:bg-red-900/20' };
      case 'INFO': return { icon: Info, color: 'text-blue-600', bg: 'bg-blue-50', darkColor: 'dark:text-blue-400', darkBg: 'dark:bg-blue-900/20' };
      case 'SUCCESS': return { icon: CheckCircle2, color: 'text-green-600', bg: 'bg-green-50', darkColor: 'dark:text-green-400', darkBg: 'dark:bg-green-900/20' };
      default: return { icon: Bell, color: 'text-institutional-600', bg: 'bg-institutional-50', darkColor: 'dark:text-institutional-400', darkBg: 'dark:bg-institutional-900/20' };
    }
  };

  if (loading) return <div className="min-h-[60vh] flex items-center justify-center font-black text-institutional-900 uppercase tracking-widest animate-pulse">Sincronizando datos institucionales...</div>;

  return (
    <div className="space-y-8 py-4 animate-in fade-in duration-500">
      <div className="text-center space-y-4">
        <h2 className="text-5xl font-black text-gray-900 dark:text-white uppercase tracking-tight">
          {isStaff ? 'Centro de Órdenes' : 'Notificaciones'}
        </h2>
        <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.2em]">
          {isStaff ? 'Instrucciones directas de directivos' : 'Alertas y mensajes del sistema institucional'}
        </p>
      </div>

      <div className="bg-white dark:bg-slate-900 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50 overflow-hidden">
        <div className="divide-y divide-gray-100 dark:divide-slate-800">
          {notifications.length > 0 ? (
            notifications.map((notif) => {
              const style = getIcon(notif.type);
              return (
                <div key={notif.id} className="p-10 hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors flex gap-8">
                  <div className={`flex-shrink-0 w-16 h-16 rounded-2xl ${style.bg} ${style.darkBg} ${style.color} ${style.darkColor} flex items-center justify-center`}>
                    <style.icon size={32} />
                  </div>
                  <div className="flex-1">
                    <div className="flex flex-col md:flex-row md:items-center justify-between gap-1 md:gap-4 mb-3">
                      <h3 className="text-xl font-black text-gray-900 dark:text-white uppercase tracking-tight">{notif.title}</h3>
                      <span className="text-xs font-black text-gray-400 dark:text-slate-500 uppercase tracking-widest">{notif.time}</span>
                    </div>
                    <p className="text-gray-600 dark:text-gray-400 leading-relaxed text-lg font-medium">{notif.desc}</p>
                    
                    {notif.sender && (
                      <div className="mt-6 flex items-center gap-3">
                        <div className="w-8 h-8 rounded-full bg-gray-100 dark:bg-slate-800 flex items-center justify-center border border-gray-200 dark:border-slate-700">
                          <User size={14} className="text-gray-400" />
                        </div>
                        <span className="text-xs font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em]">Origen: {notif.sender}</span>
                      </div>
                    )}
                  </div>
                </div>
              );
            })
          ) : (
            <div className="p-32 text-center space-y-6">
              <div className="inline-flex p-10 bg-gray-50 dark:bg-slate-800/50 text-gray-200 dark:text-slate-700 rounded-full shadow-inner">
                <Bell size={64} strokeWidth={1} />
              </div>
              <div className="space-y-2">
                <p className="text-gray-400 dark:text-slate-500 font-black uppercase tracking-[0.3em] text-sm">Sin notificaciones nuevas</p>
                <p className="text-gray-300 dark:text-slate-600 text-xs font-bold uppercase tracking-widest">El buzón se encuentra vacío por el momento</p>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
};

export default Notifications;

