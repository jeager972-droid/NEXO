/**
 * SCR-NOT-01 Notifications
 * DEC-FE-07: Drawer unificado para detalle contextual.
 * Moodboard: mensajes llegados de NEXO — barras desplegables agrupadas,
 * icono NEXO en cada item, timestamp relativo, acción contextual.
 */
import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { CheckCircle2, Info, AlertTriangle, Trash2, ChevronRight, Eye } from 'lucide-react';
import { AnimatePresence, motion } from 'framer-motion';
import { useAuth } from '../hooks/useAuth';
import { notificationsApi } from '../api/notifications';
import { trackingApi } from '../api/tracking';
import { ROLES } from '../config/roles';
import { Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { Drawer, Dialog } from '../components/ui/Overlay';
import { NexoChatBubble, NexoChatSkeleton } from '../components/patterns/NexoChat';

const LAST_COUNT_KEY = 'nexo:last-notif-count';
const emitCount = (count) => window.dispatchEvent(new CustomEvent('nexo:notif-count', { detail: { count } }));

const typeMeta = (type) => {
  switch (type) {
    case 'SOS': return { icon: AlertTriangle, scheme: 'danger', label: 'SOS', group: 'Operaciones' };
    case 'ALERT': return { icon: AlertTriangle, scheme: 'warning', label: 'Alerta', group: 'Alertas' };
    case 'SUCCESS': return { icon: CheckCircle2, scheme: 'success', label: 'Éxito', group: 'Operaciones' };
    case 'WHATSAPP': return { icon: CheckCircle2, scheme: 'success', label: 'WhatsApp', group: 'WhatsApp' };
    default: return { icon: Info, scheme: 'info', label: 'Info', group: 'Información' };
  }
};

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

const GROUP_ORDER = ['Alertas', 'Operaciones', 'WhatsApp', 'Información'];

const NotifItem = ({ notif, onClick, onShowDetails }) => {
  const meta = typeMeta(notif.type);
  const Icon = meta.icon;
  const isNexo = notif.type === 'ALERT' || notif.type === 'INFO';
  const hasDetails = (() => {
    const m = parseMeta(notif.metadata_json);
    return m && Object.keys(m).length > 0;
  })();
  return (
    <div
      className="group flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-[var(--nx-surface-subtle)] cursor-pointer"
      onClick={onClick}
    >
      <div
        className={`grid h-8 w-8 shrink-0 place-items-center rounded-full text-caption font-bold ${isNexo ? 'bg-[var(--nx-accent)] text-[var(--nx-accent-text)]' : ''}`}
        style={!isNexo ? { backgroundColor: `color-mix(in oklch, var(--nx-${meta.scheme}) 12%, transparent)`, color: `var(--nx-${meta.scheme})` } : undefined}
      >
        {isNexo ? 'N' : <Icon size={14} />}
      </div>
      <div className="flex-1 min-w-0">
        <div className="rounded-surface rounded-bl-xs border border-[var(--nx-border)] bg-[var(--nx-surface)] px-3 py-2.5">
          <div className="flex items-center gap-2">
            <p className="text-body text-[var(--nx-text)] truncate font-medium">{notif.title || notif.message}</p>
            {!notif.read && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-[var(--nx-accent)] nx-blink" />}
          </div>
          <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5 line-clamp-2">{notif.message}</p>
          {hasDetails && (
            <button
              onClick={(e) => { e.stopPropagation(); onShowDetails(notif); }}
              className="mt-2 flex items-center gap-1 text-caption text-[var(--nx-accent)] font-semibold hover:underline"
            >
              <Eye size={12} /> Ver detalles
            </button>
          )}
        </div>
        <div className="flex items-center gap-2 mt-1 px-1">
          <p className="text-caption text-[var(--nx-text-muted)] font-medium tabular-nums">{relTime(notif.created_at)}</p>
          <span className="text-caption text-[var(--nx-accent)] font-semibold flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity duration-fast">Ver <ChevronRight size={10} /></span>
        </div>
      </div>
    </div>
  );
};

const NotifGroup = ({ title, items, onOpen, onShowDetails }) => {
  const [open, setOpen] = useState(true);
  return (
    <Surface className="overflow-hidden">
      <button
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between px-5 py-3.5 text-left transition-colors hover:bg-[var(--nx-surface-subtle)]"
      >
        <div className="flex items-center gap-2">
          <span className="text-label text-[var(--nx-text)]">{title}</span>
          <span className="grid h-5 min-w-5 place-items-center rounded-full bg-[var(--nx-surface-subtle)] px-1.5 text-caption font-medium text-[var(--nx-text-muted)]">
            {items.length}
          </span>
        </div>
        <ChevronRight
          size={16}
          className={`text-[var(--nx-text-muted)] transition-transform duration-fast ${open ? 'rotate-90' : ''}`}
        />
      </button>
      <AnimatePresence initial={false}>
        {open && (
          <motion.div
            initial={{ height: 0, opacity: 0 }}
            animate={{ height: 'auto', opacity: 1 }}
            exit={{ height: 0, opacity: 0 }}
            transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
            className="overflow-hidden"
          >
            <div className="divide-y divide-[var(--nx-border)] border-t border-[var(--nx-border)]">
              {items.map((notif, i) => (
                <NotifItem
                  key={notif.id ?? notif.notification_id ?? i}
                  notif={notif}
                  onClick={() => onOpen(notif)}
                  onShowDetails={onShowDetails}
                />
              ))}
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </Surface>
  );
};

const Notifications = () => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [clearing, setClearing] = useState(false);
  const [detail, setDetail] = useState(null);
  const [detailsDialog, setDetailsDialog] = useState(null);
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

  const grouped = notifications.reduce((acc, n) => {
    const g = typeMeta(n.type).group;
    if (!acc[g]) acc[g] = [];
    acc[g].push(n);
    return acc;
  }, {});

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
        <div className="space-y-4">
          {GROUP_ORDER.filter((g) => grouped[g]?.length).map((g) => (
            <NotifGroup key={g} title={g} items={grouped[g]} onOpen={(n) => { setDetail(n); if (!n.read) markRead(n.id ?? n.notification_id); }} onShowDetails={(n) => setDetailsDialog(n)} />
          ))}
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
              <div className="flex items-center gap-3">
                <div
                  className={`grid h-10 w-10 shrink-0 place-items-center rounded-full font-bold text-body ${detail.type === 'ALERT' || detail.type === 'INFO' ? 'bg-[var(--nx-accent)] text-[var(--nx-accent-text)]' : ''}`}
                  style={!(detail.type === 'ALERT' || detail.type === 'INFO') ? { backgroundColor: `color-mix(in oklch, var(--nx-${typeMeta(detail.type).scheme}) 12%, transparent)`, color: `var(--nx-${typeMeta(detail.type).scheme})` } : undefined}
                >
                  {detail.type === 'ALERT' || detail.type === 'INFO' ? 'N' : (() => { const I = typeMeta(detail.type).icon; return <I size={18} />; })()}
                </div>
                <div>
                  <Badge scheme={typeMeta(detail.type).scheme} dot>{typeMeta(detail.type).label}</Badge>
                  <p className="text-caption text-[var(--nx-text-muted)] mt-1 tabular-nums">{relTime(detail.created_at)}</p>
                </div>
              </div>
              <div className="rounded-surface rounded-bl-xs border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 py-3">
                <p className="text-body text-[var(--nx-text)] leading-relaxed">{detail.message}</p>
              </div>
              {(!detail.message || detail.message.trim() === '') && (
                <NexoChatBubble message="No se agregaron detalles en el mensaje." />
              )}
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
          </Drawer>
        )}
      </AnimatePresence>

      <AnimatePresence>
        {detailsDialog && (
          <Dialog
            title="Detalles"
            onClose={() => setDetailsDialog(null)}
            size="md"
            footer={<Button variant="ghost" size="sm" onClick={() => setDetailsDialog(null)}>Cerrar</Button>}
          >
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {Object.entries(parseMeta(detailsDialog.metadata_json) || {}).map(([key, value]) => (
                <div key={key} className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3">
                  <p className="text-caption uppercase text-[var(--nx-text-muted)] font-medium">{key.replace(/_/g, ' ')}</p>
                  <p className="text-body text-[var(--nx-text)] mt-1 break-words">{String(value)}</p>
                </div>
              ))}
            </div>
          </Dialog>
        )}
      </AnimatePresence>
    </div>
  );
};

export default Notifications;
