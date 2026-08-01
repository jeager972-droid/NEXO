/**
 * SCR-NOT-01 Notifications
 * DEC-FE-07: Drawer unificado para detalle contextual.
 * Moodboard: mensajes llegados de NEXO — formato chat unificado para todos los roles.
 */
import { useState, useEffect } from 'react';
import { Trash2, ChevronRight } from 'lucide-react';
import { AnimatePresence } from 'framer-motion';
import { useAuth } from '../hooks/useAuth';
import { notificationsApi } from '../api/notifications';
import { ROLES, getRoleDisplay } from '../config/roles';
import { Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Drawer } from '../components/ui/Overlay';
import { NexoChatBubble, NexoChatSkeleton } from '../components/patterns/NexoChat';

const LAST_COUNT_KEY = 'nexo:last-notif-count';
const emitCount = (count) => window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count } }));

const parseMeta = (json) => {
  try { return json ? JSON.parse(json) : null; } catch { return null; }
};

const formatChatTime = (ts) => {
  if (!ts) return '';
  try {
    const date = new Date(ts);
    if (!isNaN(date.getTime())) {
      return date.toLocaleTimeString('es-CO', { hour: 'numeric', minute: '2-digit', hour12: true });
    }
  } catch { /* fallthrough */ }
  if (typeof ts === 'string' && ts.includes(':')) {
    const parts = ts.trim().split(' ');
    return parts[parts.length - 1];
  }
  return ts;
};

const humanizeMessage = (notif) => {
  const meta = parseMeta(notif.metadata_json);
  const message = notif.message || notif.desc || '';
  const action = meta?.action;

  const get = (k) => (meta?.[k] ? String(meta[k]).trim() : '');
  const student = get('student_name');
  const group = get('group_name');
  const teacher = get('teacher_name');
  const sender = get('sender_name');
  const reporter = get('reporter_name');
  const reporterRole = get('reporter_role');
  const reason = get('reason');
  const location = get('location');
  const motive = get('motivo');

  const withGroup = (base) => (group ? `${base} del grupo ${group}` : base);
  const by = (name, role) => {
    if (!name) return '';
    if (role && role !== name) return ` por ${name} (${role})`;
    return ` por ${name}`;
  };

  switch (action) {
    case 'permiso':
      return `Se ha registrado un permiso${withGroup(student ? ` para el estudiante ${student}` : '')}${by(teacher)}.`;
    case 'autorizar_salida':
      return `Se autorizó una salida${withGroup(student ? ` para el estudiante ${student}` : '')}${by(teacher)}.`;
    case 'sos':
      return `Se emitió una alerta SOS${by(reporter, reporterRole)}${location && location !== 'No especificada' ? `. Ubicación: ${location}` : ''}.`;
    case 'iniciar_seguimiento':
      return `Se inició un seguimiento${withGroup(student ? ` para el estudiante ${student}` : '')} solicitado${by(sender)}.`;
    case 'solicitud': {
      const from = by(sender, get('sender_role'));
      const roleText = get('sender_role') ? `${getRoleDisplay(get('sender_role')) || get('sender_role')}` : 'Personal de la institución';
      return `${from ? `El ${roleText} ${sender}` : 'El personal de la institución'} te envió una solicitud. Revisa los detalles.`;
    }
    case 'incidente':
      return `Se reportó un incidente${by(reporter, reporterRole)}${reason ? `: ${reason}` : ''}${location && location !== 'No especificada' ? `. Ubicación: ${location}` : ''}.`;
    case 'citacion_confirmada':
      return `El acudiente${withGroup(student ? ` de ${student}` : '')} confirmó la citación.`;
    case 'reagendar_motivo':
      return `El acudiente${withGroup(student ? ` de ${student}` : '')} pidió reagendar la citación${motive ? `: "${motive}"` : ''}.`;
    case 'salida_no_autorizada':
      return `Se marcó como error la salida autorizada${withGroup(student ? ` de ${student}` : '')}. Verificar de inmediato.`;
    default:
      if (message) return message.replace(/\.\s*Ver detalles\.?$/i, '').trim();
      return notif.title || 'Novedad institucional';
  }
};

const NotifItem = ({ notif, hasDetails, onClick }) => (
  <Surface className="p-4">
    <button onClick={onClick} className="w-full text-left">
      <NexoChatBubble
        message={humanizeMessage(notif)}
        timestamp={formatChatTime(notif.time || notif.created_at)}
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

  const getDetailMessage = (notif, meta) => {
    const raw = notif?.message || '';
    const cleanRaw = raw.replace(/\.?\s*Ver detalles\.?$/i, '').trim();
    const extra = meta?.message || meta?.reason || meta?.motivo || '';
    if (extra) return String(extra).trim();
    if (cleanRaw && cleanRaw !== humanizeMessage(notif)) return cleanRaw;
    return '';
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
            context={detail.time ? (detail.time.includes('/') ? detail.time : `Hoy a las ${detail.time}`) : undefined}
            onClose={() => setDetail(null)}
            size="sm"
          >
            <div className="p-5 space-y-3">
              <NexoChatBubble
                message={humanizeMessage(detail)}
                timestamp={formatChatTime(detail.time || detail.created_at)}
              />
              {(() => {
                const meta = parseMeta(detail.metadata_json);
                const detailMessage = getDetailMessage(detail, meta);
                if (!detailMessage) return null;
                return (
                  <NexoChatBubble
                    message={detailMessage}
                    timestamp={formatChatTime(detail.time || detail.created_at)}
                  />
                );
              })()}
            </div>
          </Drawer>
        )}
      </AnimatePresence>
    </div>
  );
};

export default Notifications;
