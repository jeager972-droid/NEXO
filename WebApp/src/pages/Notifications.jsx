/**
 * SCR-NOT-01 Notifications
 * DEC-FE-07: Drawer unificado para detalle contextual.
 * Moodboard: mensajes llegados de NEXO — formato chat unificado para todos los roles.
 */
import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Trash2, ChevronRight } from 'lucide-react';
import { AnimatePresence } from 'framer-motion';
import { useAuth } from '../hooks/useAuth';
import { notificationsApi } from '../api/notifications';
import { trackingApi } from '../api/tracking';
import { ROLES } from '../config/roles';
import { Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Drawer } from '../components/ui/Overlay';
import { NexoChatBubble, NexoChatSkeleton } from '../components/patterns/NexoChat';

const LAST_COUNT_KEY = 'nexo:last-notif-count';
const emitCount = (count) => window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count } }));

const parseMeta = (json) => {
  try { return json ? JSON.parse(json) : null; } catch { return null; }
};

const relTime = (iso) => {
  if (!iso) return '';
  const diff = Date.now() - new Date(iso).getTime();
  const min = Math.floor(diff / 60000);
  if (min < 1) return 'ahora';
  if (min < 60) return `hace ${min} min`;
  const h = Math.floor(min / 60);
  if (h < 24) return `hace ${h} h`;
  const d = Math.floor(h / 24);
  return `hace ${d} d`;
};

const NotifItem = ({ notif, hasDetails, onClick }) => (
  <Surface className="p-4">
    <button onClick={onClick} className="w-full text-left">
      <NexoChatBubble
        message={notif.message || notif.title || 'Novedad institucional'}
        timestamp={relTime(notif.created_at)}
      />
    </button>
    {hasDetails && (
      <button
        onClick={(e) => { e.stopPropagation(); onClick(); }}
        className="mt-2 ml-13 flex items-center gap-1 text-caption text-[var(--nx-accent)] font-semibold hover:underline"
      >
        Ver detalles <ChevronRight size={12} />
      </button>
    )}
  </Surface>
);

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

  const renderMetaList = (meta) => {
    if (!meta || !Object.keys(meta).length) return null;
    return (
      <div className="space-y-2 mt-4">
        {Object.entries(meta).map(([key, value]) => (
          <div key={key} className="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-3 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3">
            <span className="text-caption uppercase text-[var(--nx-text-muted)] font-medium shrink-0 sm:w-32">{key.replace(/_/g, ' ')}</span>
            <span className="text-body text-[var(--nx-text)] break-words">{String(value)}</span>
          </div>
        ))}
      </div>
    );
  };

  if (loading) {
    return (
      <div className="space-y-6">
        <Surface className="p-6">
          <NexoChatSkeleton />
        </Surface>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {notifications.length > 0 && (
        <div className="flex justify-end">
          <Button variant="ghost" size="sm" loading={clearing} onClick={handleClear} leftIcon={<Trash2 size={14} />}>
            Vaciar
          </Button>
        </div>
      )}

      {notifications.length === 0 ? (
        <Surface className="p-6">
          <NexoChatBubble message="¡Todo está al día! No tienes notificaciones pendientes. Cuando haya novedades institucionales, aparecerán aquí." />
        </Surface>
      ) : (
        <div className="space-y-3">
          {notifications.map((notif, i) => {
            const meta = parseMeta(notif.metadata_json);
            const hasDetails = meta && Object.keys(meta).length > 0;
            return (
              <NotifItem
                key={notif.id ?? notif.notification_id ?? i}
                notif={notif}
                hasDetails={hasDetails}
                onClick={() => { setDetail(notif); if (!notif.read) markRead(notif.id ?? notif.notification_id); }}
              />
            );
          })}
        </div>
      )}

      <AnimatePresence>
        {detail && (
          <Drawer
            title={detail.title || 'Notificación'}
            context={detail.created_at ? new Date(detail.created_at).toLocaleString('es-CO') : undefined}
            onClose={() => setDetail(null)}
            size="sm"
          >
            <div className="p-5 space-y-3">
              <NexoChatBubble
                message={detail.message || detail.title || 'Novedad institucional'}
                timestamp={relTime(detail.created_at)}
              />
              {renderMetaList(parseMeta(detail.metadata_json))}
              {(() => {
                const meta = parseMeta(detail.metadata_json);
                if (meta?.action === 'iniciar_seguimiento' && meta.student_id && !isStaff) {
                  return (
                    <Button className="w-full mt-4" onClick={() => startTrackingFromNotif(meta.student_id)}>
                      Iniciar seguimiento
                    </Button>
                  );
                }
                return null;
              })()}
            </div>
          </Drawer>
        )}
      </AnimatePresence>
    </div>
  );
};

export default Notifications;
