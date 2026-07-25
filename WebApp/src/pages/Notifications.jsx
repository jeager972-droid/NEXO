/**
 * SCR-NOT-01 Notifications
 * Centro de notificaciones: lista, detalle, acciones y conteo.
 */
import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Bell, CheckCircle2, Info, AlertTriangle, Eye, X, Trash2, Loader2 } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '../hooks/useAuth';
import { notificationsApi } from '../api/notifications';
import { trackingApi } from '../api/tracking';
import { ROLES } from '../config/roles';
import { Section, Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton } from '../components/ui/Skeleton';

const LAST_COUNT_KEY = 'nexo:last-notif-count';
const emitCount = (count) => window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count } }));

const typeMeta = (type) => {
  switch (type) {
    case 'SOS': return { icon: AlertTriangle, scheme: 'danger', label: 'SOS' };
    case 'ALERT': return { icon: AlertTriangle, scheme: 'warning', label: 'Alerta' };
    case 'SUCCESS': return { icon: CheckCircle2, scheme: 'success', label: 'Éxito' };
    default: return { icon: Info, scheme: 'info', label: 'Info' };
  }
};

const parseMeta = (json) => {
  try { return json ? JSON.parse(json) : null; } catch { return null; }
};

const Notifications = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [clearing, setClearing] = useState(false);
  const [detail, setDetail] = useState(null);
  const isStaff = user?.role === ROLES.PORTERO || user?.role === ROLES.AUXILIAR;

  useEffect(() => {
    const fetch = async () => {
      try {
        const data = await notificationsApi.getAll();
        setNotifications(Array.isArray(data) ? data : []);
        emitCount(0);
        sessionStorage.setItem(LAST_COUNT_KEY, '0');
      } catch (e) {
        console.error(e);
      } finally {
        setLoading(false);
      }
    };
    fetch();
  }, []);

  const handleClear = async () => {
    if (!notifications.length) return;
    setClearing(true);
    try {
      await notificationsApi.clearAll();
      setNotifications([]);
      emitCount(0);
    } catch (e) {
      console.error(e);
    } finally {
      setClearing(false);
    }
  };

  const markRead = (id) => {
    setNotifications((prev) => prev.map((n) => (n.id === id || n.notification_id === id ? { ...n, read: true } : n)));
  };

  const startTrackingFromNotif = async (studentId) => {
    try {
      const res = await trackingApi.startTracking(studentId);
      if (res.status === 'ok') {
        setDetail(null);
        navigate('/casos');
        window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count: -1 } }));
      }
    } catch (e) {
      console.error(e);
    }
  };

  if (loading) {
    return (
      <div className="space-y-6">
        <Section title="Notificaciones" subtitle="Centro de novedades" />
        <div className="space-y-3">
          {[1, 2, 3].map((i) => <Skeleton key={i} className="h-20 w-full" />)}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <Section title="Notificaciones" subtitle="Centro de novedades institucionales" />
        {notifications.length > 0 && (
          <Button variant="secondary" loading={clearing} onClick={handleClear} leftIcon={<Trash2 size={16} />}>
            Vaciar
          </Button>
        )}
      </div>

      {notifications.length === 0 ? (
        <Surface>
          <EmptyState icon={<Bell size={32} className="text-[var(--nx-border)]" />} title="Sin notificaciones" description="No tienes novedades pendientes." />
        </Surface>
      ) : (
        <Surface className="divide-y divide-[var(--nx-border)]">
          {notifications.map((notif, i) => {
            const meta = typeMeta(notif.type);
            const Icon = meta.icon;
            return (
              <button
                key={notif.id ?? notif.notification_id ?? i}
                onClick={() => setDetail(notif)}
                className="flex w-full items-start gap-4 px-5 py-4 text-left transition-colors hover:bg-[var(--nx-surface-subtle)]"
              >
                <div className="mt-1">
                  <Icon size={20} style={{ color: `var(--nx-${meta.scheme})` }} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2">
                    <p className="text-body text-[var(--nx-text)] truncate">{notif.title || notif.message}</p>
                    {!notif.read && <Badge scheme={meta.scheme} dot>{meta.label}</Badge>}
                  </div>
                  <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5 line-clamp-2">{notif.message}</p>
                  <p className="text-caption text-[var(--nx-text-muted)] mt-1">{notif.created_at && new Date(notif.created_at).toLocaleString('es-CO')}</p>
                </div>
                <Eye size={16} className="mt-1 text-[var(--nx-text-muted)]" />
              </button>
            );
          })}
        </Surface>
      )}

      <AnimatePresence>
        {detail && (
          <>
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              exit={{ opacity: 0 }}
              onClick={() => setDetail(null)}
              className="fixed inset-0 z-40 bg-[color-mix(in_oklch,var(--nx-text)_45%,transparent)]"
            />
            <motion.div
              initial={{ x: '100%' }}
              animate={{ x: 0 }}
              exit={{ x: '100%' }}
              transition={{ type: 'spring', damping: 30, stiffness: 300 }}
              className="fixed right-0 top-0 z-50 flex h-full w-full max-w-[480px] flex-col overflow-hidden border-l border-[var(--nx-border)] bg-[var(--nx-surface)]"
            >
              <div className="flex items-center justify-between border-b border-[var(--nx-border)] px-6 py-4">
                <p className="text-h3 text-[var(--nx-text)]">Detalles de la notificación</p>
                <button onClick={() => setDetail(null)} className="p-2 rounded-control hover:bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]">
                  <X size={20} />
                </button>
              </div>
              <div className="flex-1 overflow-y-auto p-6 space-y-6">
                <Surface className="p-4 space-y-2">
                  <p className="text-label text-[var(--nx-text-muted)] uppercase">Mensaje</p>
                  <p className="text-body text-[var(--nx-text)]">{detail.message}</p>
                  <p className="text-caption text-[var(--nx-text-muted)]">{detail.created_at && new Date(detail.created_at).toLocaleString('es-CO')}</p>
                </Surface>
                {(() => {
                  const meta = parseMeta(detail.metadata_json);
                  if (meta?.action === 'iniciar_seguimiento' && meta.student_id && !isStaff) {
                    return (
                      <Button className="w-full" onClick={() => startTrackingFromNotif(meta.student_id)}>
                        Iniciar seguimiento
                      </Button>
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
