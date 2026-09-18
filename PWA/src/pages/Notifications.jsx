/**
 * SCR-NOT-01 Notifications
 * DEC-FE-07: Drawer unificado para detalle contextual.
 * Moodboard: mensajes llegados de NEXO — formato chat unificado para todos los roles.
 */
import { useState, useEffect } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import { Trash2, ChevronRight, Loader2, CheckCircle2, XCircle, Check } from 'lucide-react';
import { AnimatePresence } from 'framer-motion';
import { clsx } from 'clsx';
import { useAuth } from '../hooks/useAuth';
import { notificationsApi } from '../api/notifications';
import { riskApi } from '../api/risk';
import { trackingApi } from '../api/tracking';
import { ROLES, getRoleDisplay } from '../config/roles';
import { Surface } from '../components/ui/Surface';
import { Drawer } from '../components/ui/Overlay';
import { Button } from '../components/ui/Button';
import { Select } from '../components/ui/Select';
import { NexoChatBubble, NexoChatSkeleton } from '../components/patterns/NexoChat';
import { humanizeError } from '../utils/messages';

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
  const humanizeRole = (r) => {
    if (!r) return '';
    const display = getRoleDisplay(r);
    return display && display !== r ? display.toLowerCase() : '';
  };
  const by = (name, role) => {
    if (!name) return '';
    const roleLower = humanizeRole(role);
    if (roleLower && role !== name) return ` por ${name} (${roleLower})`;
    return ` por ${name}`;
  };

  switch (action) {
    case 'permiso':
      return `Se ha registrado un permiso${withGroup(student ? ` para el estudiante ${student}` : '')}${by(teacher)}.`;
    case 'autorizar_salida':
      return `Se autorizó una salida${withGroup(student ? ` para el estudiante ${student}` : '')}${by(teacher)}.`;
    case 'sos':
      return `Se emitió una alerta SOS${by(reporter, reporterRole)}${location && location !== 'No especificada' ? `. Ubicación: ${location}` : ''}.`;
    case 'situacion_critica':
      return `Se reportó una situación crítica${by(reporter, reporterRole)}${location && location !== 'No especificada' ? `. Ubicación: ${location}` : ''}${reason ? `. Detalle: ${reason}` : ''}.`;
    case 'iniciar_seguimiento':
      return `Se inició un seguimiento${withGroup(student ? ` para el estudiante ${student}` : '')} solicitado${by(sender)}.`;
    case 'solicitud': {
      const from = by(sender, get('sender_role'));
      const roleText = get('sender_role') ? (humanizeRole(get('sender_role')) || 'personal de la institución') : 'personal de la institución';
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
    case 'late_arrival':
      return `El estudiante ${student || 'un estudiante'} llegó tarde a clase.`;
    case 'sensor_configurado': {
      const devName = get('device_name');
      const devLoc = get('location');
      const byName = get('configured_by_name');
      return `Se configuró el sensor${devName ? ` "${devName}"` : ''}${devLoc ? ` en ${devLoc}` : ''}${by(byName)}.`;
    }
    case 'sensor_eliminado': {
      const devName = get('device_name');
      const devLoc = get('location');
      const byName = get('deleted_by_name');
      const auto = meta?.auto_revoked;
      if (auto) return `Se eliminó el sensor${devName ? ` "${devName}"` : ''}${devLoc ? ` (${devLoc})` : ''} tras completarse el tiempo de espera.`;
      return `Se eliminó el sensor${devName ? ` "${devName}"` : ''}${devLoc ? ` (${devLoc})` : ''}${by(byName)}.`;
    }
    case 'sensor_revocacion_iniciada': {
      const devName = get('device_name');
      const byName = get('requested_by_name');
      return `Se inició la eliminación del sensor${devName ? ` "${devName}"` : ''}${by(byName)}. Se completará en 1 hora.`;
    }
    default:
      if (message) return message.replace(/\.\s*Ver detalles\.?$/i, '').trim();
      return notif.title || 'Novedad institucional';
  }
};

const ACTIONS_WITH_DETAILS = [
  'permiso', 'autorizar_salida', 'sos', 'iniciar_seguimiento',
  'solicitud', 'incidente', 'citacion_confirmada', 'reagendar_motivo', 'salida_no_autorizada',
  'situacion_critica', 'daño', 'pedagogica',
  'sensor_configurado', 'sensor_eliminado', 'sensor_revocacion_iniciada',
];

// Severidad por tipo de aviso — colores del sistema (punto lateral)
const severityOf = (notif) => {
  const meta = parseMeta(notif.metadata_json);
  const action = meta?.action || '';
  const type = String(notif.type || '').toUpperCase();
  if (['sos', 'situacion_critica', 'daño', 'evasion_interna', 'salida_no_autorizada'].includes(action)
      || type.includes('CRIT') || type.includes('MUY_ALTA')) return 'crit';
  if (['iniciar_seguimiento', 'incidente', 'reagendar_motivo', 'late_arrival'].includes(action)
      || type.includes('RISK') || type.includes('ALTA') || type.includes('ALERT')) return 'alta';
  return 'info';
};
const SEV_DOT = {
  crit: 'bg-[var(--nx-danger)]',
  alta: 'bg-[var(--nx-warning)]',
  info: 'bg-[var(--nx-accent)]',
};
const SEV_TAG = {
  crit: { label: 'Alta', cls: 'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]' },
  alta: { label: 'Moderada', cls: 'bg-[var(--nx-subtle-bg-warning)] text-[var(--nx-warning)]' },
  info: { label: 'Informativa', cls: 'bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]' },
};

const DEPENDENCIES = [
  { value: 'coordinacion', label: 'Coordinación' },
  { value: 'psicoorientacion', label: 'Psicoorientación' },
  { value: 'rectoria', label: 'Rectoría' },
  { value: 'docencia', label: 'Docencia' },
];

const NotifItem = ({ notif, hasDetails, onClick, onAction, onDerive, onMarkRead, canDerive }) => {
  const meta = parseMeta(notif.metadata_json);
  const actions = meta?.actions;
  const [actionLoading, setActionLoading] = useState(false);
  const sev = severityOf(notif);
  const sevTag = SEV_TAG[sev];
  const studentName = meta?.student_name;
  const canDeriveThis = canDerive && !!meta?.student_id;

  const handleAction = async (e, actionId) => {
    e.stopPropagation();
    const notifId = notif.id ?? notif.notification_id;
    if (!notifId) return;
    setActionLoading(true);
    try {
      await notificationsApi.executeAction(notifId, actionId);
      if (onAction) onAction();
    } catch (err) {
      console.error('Error al procesar acción de notificación:', err);
    } finally {
      setActionLoading(false);
    }
  };

  return (
    <Surface className={clsx(
      'grid grid-cols-[10px_1fr_auto] items-start gap-4 p-4 transition-colors',
      !notif.read && 'bg-[var(--nx-subtle-bg-accent)] border-[var(--nx-border-accent)]'
    )}>
      <span className={clsx('mt-2 h-2 w-2 rounded-full', SEV_DOT[sev])} aria-hidden />

      <div className="min-w-0">
        <p className="text-[14.5px] font-[620] text-[var(--nx-text)]">
          {notif.title || 'Novedad'}{studentName ? ` — ${studentName}` : ''}
        </p>
        <p className="mt-0.5 text-[13.5px] text-[var(--nx-text-muted)]">{humanizeMessage(notif)}</p>
        <div className="mt-2 flex flex-wrap items-center gap-2.5 text-[12px] text-[var(--nx-text-muted)]">
          <span>{formatChatTime(notif.time || notif.created_at)}</span>
          <span className={clsx('rounded-full px-2.5 py-0.5 font-semibold', sevTag.cls)}>{sevTag.label}</span>
          {notif.read && <span className="rounded-full border border-[var(--nx-border)] px-2.5 py-0.5">Leída</span>}
        </div>
        {actions && Array.isArray(actions) && actions.length > 0 && (
          <div className="mt-3 flex items-center gap-2">
            {actions.map((act) => {
              const styleClasses = act.style === 'success'
                ? 'bg-[var(--nx-surface-success)] text-[color-mix(in_oklch,var(--nx-success)_80%,var(--nx-text))] border-[var(--nx-border-success)] hover:bg-[color-mix(in_oklch,var(--nx-success)_15%,var(--nx-surface-success))]'
                : 'bg-[var(--nx-surface-danger)] text-[color-mix(in_oklch,var(--nx-danger)_80%,var(--nx-text))] border-[var(--nx-border-danger)] hover:bg-[color-mix(in_oklch,var(--nx-danger)_15%,var(--nx-surface-danger))]';
              return (
                <button
                  key={act.id}
                  onClick={(e) => handleAction(e, act.id)}
                  disabled={actionLoading}
                  className={`flex items-center gap-1.5 rounded-control border px-3 py-1.5 text-caption font-semibold transition-all disabled:opacity-45 ${styleClasses}`}
                >
                  {actionLoading && <Loader2 size={12} className="animate-spin" />}
                  {act.label}
                </button>
              );
            })}
          </div>
        )}
      </div>

      <div className="flex flex-col items-end gap-2">
        <div className="flex gap-2">
          {canDeriveThis && (
            <Button size="sm" onClick={() => onDerive(notif, meta)}>Derivar a seguimiento</Button>
          )}
          {hasDetails && (
            <Button variant="secondary" size="sm" onClick={onClick}>
              Detalle <ChevronRight size={13} />
            </Button>
          )}
        </div>
        {!notif.read && (
          <button
            type="button"
            onClick={onMarkRead}
            className="flex items-center gap-1 text-[12px] font-semibold text-[var(--nx-accent)] hover:underline"
          >
            <Check size={12} /> Marcar leída
          </button>
        )}
      </div>
    </Surface>
  );
};

const Notifications = () => {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [clearing, setClearing] = useState(false);
  const [detail, setDetail] = useState(null);
  const [resolving, setResolving] = useState(false);
  const [resolveError, setResolveError] = useState('');
  const [deriveTarget, setDeriveTarget] = useState(null); // {notif, meta}
  const [deriveForm, setDeriveForm] = useState({ dependency: 'coordinacion', assigned_to_user_id: '', reason: '' });
  const [deriveSaving, setDeriveSaving] = useState(false);
  const [deriveError, setDeriveError] = useState('');
  const navigate = useNavigate();
  const canResolveEvasion = user?.role === ROLES.COORDINADOR || user?.role === ROLES.RECTOR;
  const canDerive = canResolveEvasion || user?.role === ROLES.PSICORIENTADOR;

  useEffect(() => {
    const fetch = async () => {
      try {
        const data = await notificationsApi.getAll();
        const arr = Array.isArray(data) ? data : [];
        setNotifications(arr);
        sessionStorage.setItem(LAST_COUNT_KEY, String(arr.length));
        emitCount(0);
      } catch (e) {
        console.error(e);
      } finally {
        setLoading(false);
      }
    };
    fetch();
  }, []);

  // Auto-abrir detalle si viene notif_id en query params
  useEffect(() => {
    const notifId = searchParams.get('notif_id');
    if (notifId && !loading && notifications.length > 0 && !detail) {
      const target = notifications.find((n) => String(n.id) === String(notifId) || String(n.notification_id) === String(notifId));
      if (target) {
        setDetail(target);
        if (!target.read) markRead(target.id ?? target.notification_id);
      }
      // Limpiar el query param
      setSearchParams({}, { replace: true });
    }
  }, [searchParams, loading, notifications, detail, setSearchParams]);

  const handleClear = async () => {
    if (!notifications.length) return;
    setClearing(true);
    try {
      await notificationsApi.clearAll();
      setNotifications([]);
      sessionStorage.setItem(LAST_COUNT_KEY, '0');
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

  const openDerive = (notif, meta) => {
    setDeriveTarget({ notif, meta });
    setDeriveForm({ dependency: 'coordinacion', assigned_to_user_id: '', reason: '' });
    setDeriveError('');
    if (!notif.read) markRead(notif.id ?? notif.notification_id);
  };

  const submitDerive = async () => {
    const meta = deriveTarget?.meta || {};
    setDeriveSaving(true);
    setDeriveError('');
    try {
      const alertId = meta.alert_id || meta.risk_alert_id || null;
      const incidentId = meta.incident_id || null;
      if (alertId || incidentId) {
        await trackingApi.derive({
          studentId: meta.student_id,
          alertId, incidentId,
          dependency: deriveForm.dependency,
          assignedToUserId: deriveForm.assigned_to_user_id || null,
          reason: deriveForm.reason || null,
        });
      } else {
        // Sin origen estructurado: seguimiento directo del estudiante
        await trackingApi.startTracking(meta.student_id, deriveForm.reason || null);
      }
      setDeriveTarget(null);
      navigate('/casos');
    } catch (e) {
      setDeriveError(humanizeError(e, 'No se pudo crear el caso de seguimiento.'));
    } finally {
      setDeriveSaving(false);
    }
  };

  const handleResolveEvasion = async (incidentId, resolution) => {
    setResolving(true);
    setResolveError('');
    try {
      await riskApi.resolveIncident(incidentId, resolution);
      setDetail(null);
      await refreshNotifications();
    } catch (e) {
      setResolveError(e?.response?.data?.message || 'Error al resolver el incidente');
    } finally {
      setResolving(false);
    }
  };

  const refreshNotifications = async () => {
    try {
      const data = await notificationsApi.getAll();
      const arr = Array.isArray(data) ? data : [];
      setNotifications(arr);
      sessionStorage.setItem(LAST_COUNT_KEY, String(arr.length));
      emitCount(0);
    } catch (e) {
      console.error(e);
    }
  };

  const getDetailMessage = (notif, meta) => {
    const raw = notif?.message || '';
    const cleanRaw = raw.replace(/\.?\s*Ver detalles\.?$/i, '').trim();
    const extra = meta?.message || meta?.reason || meta?.motivo || '';
    if (extra) return String(extra).trim();

    // Detalles específicos para sensores
    const action = meta?.action;
    if (action === 'sensor_configurado' || action === 'sensor_eliminado' || action === 'sensor_revocacion_iniciada') {
      const parts = [];
      if (meta?.device_name) parts.push(`Sensor: "${meta.device_name}"`);
      if (meta?.location) parts.push(`Ubicación: ${meta.location}`);
      const byName = meta?.configured_by_name || meta?.deleted_by_name || meta?.requested_by_name;
      if (byName) parts.push(`Realizado por: ${byName}`);
      if (meta?.auto_revoked) parts.push('Eliminado automáticamente tras completarse el tiempo de espera.');
      if (parts.length > 0) return parts.join('. ') + '.';
    }

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
      <div className="flex items-center justify-between gap-4 border-b border-[var(--nx-border)] pb-3">
        <div className="border-l-2 border-[var(--nx-accent)] pl-3">
          <p className="text-label text-[var(--nx-text)]">Notificaciones</p>
        </div>
        {notifications.length > 0 && (
          <button
            onClick={handleClear}
            disabled={clearing}
            className="flex items-center gap-1 text-label text-[var(--nx-danger)] font-medium hover:underline disabled:opacity-45"
          >
            <Trash2 size={14} />
            Vaciar
          </button>
        )}
      </div>

      {notifications.length === 0 ? (
        <Surface className="p-6">
          <NexoChatBubble message="¡Todo está al día! No tienes notificaciones pendientes. Cuando haya novedades institucionales, aparecerán aquí." />
        </Surface>
      ) : (
        <div className="space-y-3">
          {notifications.map((notif, i) => {
            const meta = parseMeta(notif.metadata_json);
            const action = meta?.action;
            const detailMessage = getDetailMessage(notif, meta);
            const hasDetails = !!detailMessage || ACTIONS_WITH_DETAILS.includes(action);
            return (
              <NotifItem
                key={notif.id ?? notif.notification_id ?? i}
                notif={notif}
                hasDetails={hasDetails}
                onClick={() => { setDetail(notif); if (!notif.read) markRead(notif.id ?? notif.notification_id); }}
                onAction={refreshNotifications}
                onDerive={openDerive}
                onMarkRead={() => markRead(notif.id ?? notif.notification_id)}
                canDerive={canDerive}
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
            <div className="p-5 space-y-4">
              <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                <p className="text-label text-[var(--nx-text-muted)]">Detalles</p>
              </div>
              <div className="space-y-3">
                {(() => {
                  const meta = parseMeta(detail.metadata_json);
                  const detailMessage = getDetailMessage(detail, meta);
                  if (!detailMessage) {
                    return (
                      <NexoChatBubble
                        message="No se agregaron detalles extra."
                        timestamp={formatChatTime(detail.time || detail.created_at)}
                      />
                    );
                  }
                  return (
                    <NexoChatBubble
                      message={detailMessage}
                      timestamp={formatChatTime(detail.time || detail.created_at)}
                    />
                  );
                })()}
              </div>

              {(() => {
                const meta = parseMeta(detail.metadata_json);
                if (canResolveEvasion && meta?.action === 'evasion_interna' && meta?.incident_id) {
                  return (
                    <div className="space-y-3 border-t border-[var(--nx-border)] pt-4">
                      <div className="border-l-2 border-[var(--nx-danger)] pl-3">
                        <p className="text-label text-[var(--nx-text)]">Resolver evasión</p>
                      </div>
                      <p className="text-body-sm text-[var(--nx-text-muted)]">
                        Marca este incidente como resuelto. Si es justificada, no se guardará para análisis de riesgo. Si es injustificada, se registrará para el análisis y será consultable.
                      </p>
                      {resolveError && (
                        <p className="text-body-sm text-[var(--nx-danger)]">{resolveError}</p>
                      )}
                      <div className="flex gap-3">
                        <Button
                          variant="secondary"
                          size="sm"
                          disabled={resolving}
                          onClick={() => handleResolveEvasion(meta.incident_id, 'justificada')}
                        >
                          <CheckCircle2 size={14} />
                          Justificada
                        </Button>
                        <Button
                          variant="primary"
                          size="sm"
                          disabled={resolving}
                          onClick={() => handleResolveEvasion(meta.incident_id, 'injustificada')}
                        >
                          <XCircle size={14} />
                          Injustificada
                        </Button>
                      </div>
                      {resolving && (
                        <p className="text-caption text-[var(--nx-text-muted)] flex items-center gap-1">
                          <Loader2 size={12} className="animate-spin" />
                          Resolviendo...
                        </p>
                      )}
                    </div>
                  );
                }
                return null;
              })()}
            </div>
          </Drawer>
        )}
      </AnimatePresence>

      {/* Drawer: derivar a seguimiento */}
      <AnimatePresence>
        {deriveTarget && (
          <Drawer
            title={`Derivar a seguimiento — ${deriveTarget.meta?.student_name || 'Estudiante'}`}
            context="El sistema detectó; la decisión es tuya"
            onClose={() => setDeriveTarget(null)}
            size="sm"
          >
            <div className="p-5 space-y-4">
              <div className="space-y-3">
                <div>
                  <p className="text-label text-[var(--nx-text-muted)]">Origen</p>
                  <p className="mt-1 text-body-sm text-[var(--nx-text)]">
                    {deriveTarget.notif?.title || 'Notificación'} · {humanizeMessage(deriveTarget.notif)}
                  </p>
                </div>
                <Select
                  label="Dependencia responsable"
                  value={deriveForm.dependency}
                  onChange={(e) => setDeriveForm((f) => ({ ...f, dependency: e.target.value }))}
                  options={DEPENDENCIES}
                />
                <div>
                  <p className="text-label text-[var(--nx-text-muted)]">Asignar a (opcional)</p>
                  <p className="mt-1 text-caption text-[var(--nx-text-muted)]">
                    La asignación a una persona específica se completa desde la ficha del caso.
                  </p>
                </div>
                <div>
                  <label className="text-label text-[var(--nx-text-muted)]" htmlFor="derive-reason">Motivo / nota inicial</label>
                  <textarea
                    id="derive-reason"
                    rows={3}
                    value={deriveForm.reason}
                    onChange={(e) => setDeriveForm((f) => ({ ...f, reason: e.target.value }))}
                    placeholder="Contexto para quien recibe el caso…"
                    className="mt-1.5 w-full rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-3 py-2.5 text-body-sm text-[var(--nx-text)] focus:outline-none focus:ring-2 focus:ring-[var(--nx-ring)]"
                  />
                </div>
                <p className="text-caption text-[var(--nx-text-muted)]">
                  El sistema detectó y escaló — la derivación y la decisión son tuyas.
                </p>
                {deriveError && <p role="alert" className="text-body-sm text-[var(--nx-danger)]">{deriveError}</p>}
              </div>
              <div className="flex justify-end gap-3 border-t border-[var(--nx-border)] pt-4">
                <Button variant="secondary" size="sm" onClick={() => setDeriveTarget(null)}>Cancelar</Button>
                <Button size="sm" loading={deriveSaving} onClick={submitDerive}>Crear caso</Button>
              </div>
            </div>
          </Drawer>
        )}
      </AnimatePresence>
    </div>
  );
};

export default Notifications;
