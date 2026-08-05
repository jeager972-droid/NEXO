/**
 * SCR-HOME-01 Dashboard / Inicio por rol
 * DEC-FE-05: contenido por rol. A-07: tarjetas accionables.
 * Usa PageHeader, StatCard, SituationLine, Drawer unificado.
 */
import { useState, useEffect, useRef } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import {
  Users, Activity, AlertTriangle, UserMinus, ChevronRight,
  Search, X, CalendarDays, CheckCircle2, FileText, Sparkles, ClipboardCheck, Clock
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { dashboardApi } from '../api/dashboard';
import { trackingApi } from '../api/tracking';
import { schoolApi } from '../api/school';
import { ROLES } from '../config/roles';
import { Skeleton, SkeletonMetrics, SkeletonRows } from '../components/ui/Skeleton';
import { EmptyState } from '../components/ui/EmptyState';
import { Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Input } from '../components/ui/Input';
import { Drawer } from '../components/ui/Overlay';
import { StatCard } from '../components/patterns/StatCard';
import { SituationLine } from '../components/patterns/SituationLine';
import { NexoChatBubble, NexoChatSkeleton } from '../components/patterns/NexoChat';
import { ScheduleTask, isTaskActive, isTaskDoneToday } from '../components/patterns/ScheduleTask';
import { OnboardingScheduleModal } from '../components/patterns/OnboardingScheduleModal';
import { formatGroupName } from '../utils/groupFormat';
import { humanizeError } from '../utils/messages';

const EMPTY_STATS = {
  presentCount: 0, absentCount: 0, alertsCount: 0, permCount: 0, lateCount: 0,
  pendingTasks: [], studentsByGroup: {}, teacherGroups: [],
  groupStats: { present: 0, absent: 0, alerts: 0, permisos: 0, late: 0, outside: 0 },
};

const TasksEmptyState = ({ loading }) => {
  if (loading) {
    return (
      <Surface className="p-6">
        <div className="flex flex-col items-center justify-center space-y-4 py-10">
          <Skeleton className="h-13 w-13 rounded-surface" />
          <Skeleton className="h-6 w-48" />
          <Skeleton className="h-4 w-64" />
        </div>
      </Surface>
    );
  }
  return (
    <Surface className="p-6">
      <EmptyState
        icon={<ClipboardCheck size={22} strokeWidth={1.75} className="text-[var(--nx-success)]" />}
        title="Todo en orden"
        description="Parece que todas tus tareas están al día."
      />
    </Surface>
  );
};

const BIOMETRIC_EVENT_LABELS = {
  INGRESO_NORMAL: 'Ingreso normal',
  INGRESO_TARDE: 'Ingreso tarde',
  CHECK_IN: 'Entrada',
  CHECK_OUT: 'Salida',
  LATE_ARRIVAL: 'Llegada tarde',
  EARLY_EXIT: 'Salida anticipada',
  WRONG_CLASSROOM: 'Salón incorrecto',
  EVASION_INTERNA: 'Evasión interna',
  SPAM_BIOMETRIC: 'Spam biométrico',
  BIOMETRIC_FAILURE: 'Falla de biometría',
};

const eventToMessage = (ev) => {
  // Limpiar el label de cualquier "group: null" o pares clave-valor crudos del backend
  let label = ev.label || '';
  // Si el label contiene pares crudos como "group: null", limpiarlos
  label = label.replace(/\s*\b\w+:\s*null\b,?/gi, '').replace(/\s*\b\w+:\s*undefined\b,?/gi, '').trim();
  // Si después de limpiar quedó vacío o solo punctuation, usar el tipo de evento humanizado
  if (!label || label === '.' || label === ',') {
    const evType = ev.type || ev.event_type || ev.event_result;
    label = BIOMETRIC_EVENT_LABELS[evType] || BIOMETRIC_EVENT_LABELS[String(evType)?.toUpperCase()] || evType || 'Novedad';
  }
  // Construir grupo: si es null/undefined, omitir o usar "Sin grupo asignado"
  const group = ev.group || ev.group_name;
  const groupText = group ? ` del grupo ${formatGroupName(String(group))}` : '';
  const student = ev.student_name || ev.student;
  const studentText = student ? ` de ${student}` : '';
  const issuer = ev.issuer ? ` · por ${ev.issuer}` : '';
  const time = ev.time ? ` a las ${ev.time}` : '';
  return `${label}${studentText}${groupText}${time}${issuer}.`;
};

const StreamList = ({ events, loading, showIssuer, onItemClick }) => {
  return (
    <div className="space-y-4">
      {/* Barra azul vertical — indicador visual siempre visible */}
      <div className="flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
        <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
        <p className="text-label text-[var(--nx-text)]">Novedades</p>
      </div>
      {loading ? (
        <Surface className="p-6">
          <NexoChatSkeleton />
        </Surface>
      ) : !events.length ? (
        <Surface className="p-6">
          <NexoChatBubble message="No hay novedades para mostrar." />
        </Surface>
      ) : (
        <div className="space-y-3">
          {events.map((ev, i) => (
            <Surface key={i} className="p-4">
              <NexoChatBubble
                message={eventToMessage(ev)}
                timestamp={ev.time || 'Ahora'}
              />
              {onItemClick && (
                <button
                  onClick={() => onItemClick(ev)}
                  className="mt-2 ml-13 flex items-center gap-1 text-caption text-[var(--nx-accent)] font-semibold hover:underline"
                >
                  Ver detalles <ChevronRight size={12} />
                </button>
              )}
            </Surface>
          ))}
        </div>
      )}
    </div>
  );
};

const Dashboard = () => {
  const { user } = useAuth();
  const [stats, setStats] = useState(EMPTY_STATS);
  const [loading, setLoading] = useState(true);
  const [onboardingRequired, setOnboardingRequired] = useState(false);
  const [onboardingLoading, setOnboardingLoading] = useState(true);

  useEffect(() => {
    const load = async () => {
      try { setStats({ ...EMPTY_STATS, ...(await dashboardApi.getStats() || {}) }); }
      catch (e) { console.error(e); setStats(EMPTY_STATS); }
      finally { setLoading(false); }
    };
    load();

    // Verificar onboarding solo para RECTOR y COORDINADOR
    if (user?.role === ROLES.RECTOR || user?.role === ROLES.COORDINADOR) {
      const checkOnboarding = async () => {
        try {
          const config = await schoolApi.getConfig();
          setOnboardingRequired(!config.onboarding_completed);
        } catch (e) {
          console.error('Onboarding check failed:', e);
          // Si falla la verificación, no bloquear (mejor permisivo que bloquear por error)
        } finally {
          setOnboardingLoading(false);
        }
      };
      checkOnboarding();
    } else {
      setOnboardingLoading(false);
    }
  }, [user]);

  // Onboarding bloqueante para RECTOR/COORDINADOR
  if (!onboardingLoading && onboardingRequired && (user?.role === ROLES.RECTOR || user?.role === ROLES.COORDINADOR)) {
    return (
      <OnboardingScheduleModal
        schoolId={user?.school_id}
        userId={user?.id}
        role={user?.role}
        onCompleted={() => {
          setOnboardingRequired(false);
          // Recargar stats después del onboarding
          setLoading(true);
          dashboardApi.getStats().then(s => { setStats({ ...EMPTY_STATS, ...s }); }).finally(() => setLoading(false));
        }}
      />
    );
  }

  switch (user?.role) {
    case ROLES.RECTOR:
    case ROLES.COORDINADOR:
      return <AdminDashboard stats={stats} loading={loading} />;
    case ROLES.SECRETARIA:
      return <SecretaryDashboard stats={stats} loading={loading} />;
    case ROLES.PSICORIENTADOR:
      return <SecretaryDashboard stats={stats} loading={loading} />;
    case ROLES.DOCENTE:
      return <TeacherDashboard stats={stats} loading={loading} />;
    case ROLES.PORTERO:
    case ROLES.AUXILIAR:
      return <StaffDashboard stats={stats} loading={loading} />;
    default:
      return (
        <EmptyState
          title="Rol no autorizado"
          description={user?.role ?? 'No se detectó rol'}
        />
      );
  }
};

// ── Command Center — Admin / Rector / Coordinador ─────────────────────────────

const todayLabel = () =>
  new Date().toLocaleDateString('es-CO', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

const getGreeting = () => {
  const h = new Date().getHours();
  if (h < 12) return 'Buenos días';
  if (h < 18) return 'Buenas tardes';
  return 'Buenas noches';
};

const AdminDashboard = ({ stats, loading }) => {
  const { user } = useAuth();
  const [events, setEvents] = useState([]);
  const [eventsLoading, setEventsLoading] = useState(true);
  const [activeCategory, setActiveCategory] = useState(null);
  const [detailData, setDetailData] = useState([]);
  const [detailLoading, setDetailLoading] = useState(false);
  const [showScheduleTask, setShowScheduleTask] = useState(false);

  useEffect(() => {
    if (user?.role === ROLES.COORDINADOR && isTaskActive(user) && !isTaskDoneToday(user)) {
      setShowScheduleTask(true);
    }
  }, [user]);

  const localDateStr = (date = new Date()) => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  };

  const openDetail = async (category) => {
    setActiveCategory(category);
    setDetailLoading(true);
    try {
      const res = await dashboardApi.getTeacherGroupDetail('', category, localDateStr(), localDateStr());
      if (res?.status === 'ok') setDetailData(res.data || []);
      else setDetailData([]);
    } catch (e) {
      console.error(e);
      setDetailData([]);
    } finally {
      setDetailLoading(false);
    }
  };

  const kpis = [
    { key: 'present',  label: 'Presentes',      value: stats.presentCount, icon: <Users size={18} strokeWidth={1.75} />,         tone: 'accent',  statusText: 'Alumnos en clase' },
    { key: 'absent',   label: 'Inasistentes',   value: stats.absentCount,  icon: <UserMinus size={18} strokeWidth={1.75} />,     tone: 'warning', statusText: stats.absentCount === 0 && stats.presentCount === 0 ? 'No hay estudiantes' : 'Sin registro de entrada' },
    { key: 'late',     label: 'Llegadas tarde', value: stats.lateCount,    icon: <Clock size={18} strokeWidth={1.75} />,         tone: 'warning', statusText: stats.lateCount === 0 ? 'Sin llegadas tarde' : 'Ingresos después de hora' },
    { key: 'alert',    label: 'Alertas',        value: stats.alertsCount,  icon: <AlertTriangle size={18} strokeWidth={1.75} />, tone: 'danger',  statusText: stats.alertsCount === 0 && stats.presentCount === 0 ? 'No hay estudiantes' : 'Requieren atención' },
    { key: 'permiso',  label: 'Permisos',       value: stats.permCount,    icon: <FileText size={18} strokeWidth={1.75} />,      tone: 'success', statusText: stats.permCount === 0 && stats.presentCount === 0 ? 'No hay estudiantes' : 'Permisos activos hoy' },
  ];

  useEffect(() => {
    const loadEvents = async () => {
      try {
        const res = await dashboardApi.getEvents();
        setEvents(res?.status === 'ok' ? res.data || [] : []);
      } catch (e) {
        console.error(e);
        setEvents([]);
      } finally {
        setEventsLoading(false);
      }
    };
    loadEvents();
  }, []);

  const stream = events.slice(0, 8).map((ev, i) => ({ ...ev, index: i }));

  return (
    <div className="space-y-8">
      {showScheduleTask && (
        <ScheduleTask onDismiss={() => setShowScheduleTask(false)} />
      )}
      {loading ? (
        <SkeletonMetrics count={5} />
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
          {kpis.map((k) => (
            <StatCard
              key={k.key}
              icon={k.icon}
              label={k.label}
              value={k.value}
              tone={k.tone}
              statusText={k.statusText}
              onClick={() => openDetail(k.key)}
            />
          ))}
        </div>
      )}

      <StreamList events={stream} loading={eventsLoading} showIssuer />

      <AnimatePresence>
        {activeCategory && (
          <TeacherDetailDrawer
            category={activeCategory}
            groupName="Toda la Institución"
            scopeLabel="institución"
            data={detailData}
            loading={detailLoading}
            emptyWarning={false}
            onClose={() => setActiveCategory(null)}
          />
        )}
      </AnimatePresence>
    </div>
  );
};

// ── Secretaria ────────────────────────────────────────────────────────────────

const SecretaryDashboard = ({ stats, loading: parentLoading }) => {
  const [events, setEvents] = useState([]);
  const [eventsLoading, setEventsLoading] = useState(true);

  useEffect(() => {
    const loadEvents = async () => {
      try {
        const res = await dashboardApi.getEvents();
        setEvents(res?.status === 'ok' ? res.data || [] : []);
      } catch (e) {
        console.error(e);
        setEvents([]);
      } finally {
        setEventsLoading(false);
      }
    };
    loadEvents();
  }, []);

  return (
    <div className="space-y-8">
      <TasksEmptyState loading={parentLoading} />
      <StreamList events={events.slice(0, 8)} loading={eventsLoading} showIssuer />
    </div>
  );
};

// ── Psicorientador ────────────────────────────────────────────────────────────

const CounselorDashboard = ({ stats, loading: parentLoading }) => {
  const [events, setEvents] = useState([]);
  const [eventsLoading, setEventsLoading] = useState(true);

  useEffect(() => {
    const loadEvents = async () => {
      try {
        const res = await dashboardApi.getEvents();
        setEvents(res?.status === 'ok' ? res.data || [] : []);
      } catch (e) {
        console.error(e);
        setEvents([]);
      } finally {
        setEventsLoading(false);
      }
    };
    loadEvents();
  }, []);

  return (
    <div className="space-y-8">
      {parentLoading ? (
        <SkeletonMetrics count={4} />
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <StatCard icon={<Users size={18} strokeWidth={1.75} />} label="Estudiantes" value={Object.keys(stats?.studentsByGroup || {}).length} tone="accent" />
          <StatCard icon={<FileText size={18} strokeWidth={1.75} />} label="Permisos" value={stats?.permCount ?? 0} tone="success" />
          <StatCard icon={<AlertTriangle size={18} strokeWidth={1.75} />} label="Alertas" value={stats?.alertsCount ?? 0} tone="danger" />
          <StatCard icon={<Activity size={18} strokeWidth={1.75} />} label="Seguimientos" value={0} tone="warning" />
        </div>
      )}
      <StreamList events={events.slice(0, 8)} loading={eventsLoading} showIssuer />
    </div>
  );
};

// ── Docente / Psicorientador ──────────────────────────────────────────────────

const CATEGORY_LABELS = {
  present:  { label: 'Presentes',      accent: 'var(--nx-accent)', icon: Users },
  absent:   { label: 'Inasistentes',   accent: 'var(--nx-accent)', icon: UserMinus },
  late:     { label: 'Llegadas tarde', accent: 'var(--nx-warning)', icon: Clock },
  alert:    { label: 'Alertas',        accent: 'var(--nx-danger)', icon: AlertTriangle },
  permiso:  { label: 'Permisos',       accent: 'var(--nx-success)', icon: Activity },
};

// Helper: fecha local en formato YYYY-MM-DD (timezone-safe, no UTC shift)
const localDateStr = (date = new Date()) => {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

const GROUP_KEY = 'nexo:teacher:selected-group';

const TeacherDashboard = ({ stats, loading: parentLoading }) => {
  const [selectedGroup, setSelectedGroup] = useState(() => {
    try { return localStorage.getItem(GROUP_KEY) || ''; } catch { return ''; }
  });
  const [groupStats, setGroupStats]       = useState(null);
  const [groupLoading, setGroupLoading]   = useState(false);

  const [activeCategory, setActiveCategory] = useState(null);
  const [detailData, setDetailData]         = useState([]);
  const [detailLoading, setDetailLoading]   = useState(false);

  const [events, setEvents] = useState([]);
  const [eventsLoading, setEventsLoading] = useState(true);

  // Group search dropdown state
  const [groupOpen, setGroupOpen]   = useState(false);
  const [groupQuery, setGroupQuery] = useState('');

  // Normalizar nombre de grupo: quitar guiones, espacios y convertir a minúsculas para comparar
  const normalizeGroup = g => String(g || '').replace(/[\s-]/g, '').toLowerCase();
  const todayStr = localDateStr();
  const rawGroups = (stats?.teacherGroups?.length > 0)
    ? stats.teacherGroups
    : Object.keys(stats?.studentsByGroup || {});
  // Deduplicar por nombre normalizado — si hay "6A" y "6-A", preferir el que tiene datos (teacherGroups es API-first)
  const groupNames = Object.values(
    rawGroups.reduce((acc, g) => {
      const key = normalizeGroup(g);
      if (!acc[key]) acc[key] = g;
      return acc;
    }, {})
  );
  const groupDisplay = groupNames.map(formatGroupName);

  const filteredGroups = groupQuery.trim()
    ? groupNames.filter((g, i) => groupDisplay[i].toLowerCase().includes(groupQuery.toLowerCase()))
    : groupNames;

  // Persist selected group and keep selector bar usable
  useEffect(() => {
    if (selectedGroup) localStorage.setItem(GROUP_KEY, selectedGroup);
    else localStorage.removeItem(GROUP_KEY);
  }, [selectedGroup]);

  // Fetch per-group stats when group changes
  useEffect(() => {
    if (!selectedGroup) {
      setGroupStats(null);
      return;
    }
    setGroupLoading(true);
    dashboardApi.getStats(selectedGroup)
      .then(res => {
        if (res?.status === 'ok') {
          setGroupStats(res.groupStats || EMPTY_STATS.groupStats);
        }
      })
      .catch(err => console.error('Error fetching group stats', err))
      .finally(() => setGroupLoading(false));
  }, [selectedGroup]);

  // Load events
  useEffect(() => {
    const loadEvents = async () => {
      try {
        const res = await dashboardApi.getEvents();
        if (res?.status === 'ok') {
          setEvents(res.data || []);
        } else {
          setEvents([]);
        }
      } catch (e) {
        console.error(e);
        setEvents([]);
      } finally {
        setEventsLoading(false);
      }
    };
    loadEvents();
  }, []);

  const openDetail = async (category) => {
    if (!selectedGroup) return;
    setActiveCategory(category);
    setDetailLoading(true);
    try {
      const res = await dashboardApi.getTeacherGroupDetail(selectedGroup, category, todayStr, todayStr);
      if (res?.status === 'ok') setDetailData(res.data || []);
      else setDetailData([]);
    } catch (e) {
      console.error(e);
      setDetailData([]);
    } finally {
      setDetailLoading(false);
    }
  };

  const hasActivity = groupStats && (groupStats.present + groupStats.absent + groupStats.alerts + groupStats.permisos + (groupStats.late || 0)) > 0;

  const cards = [
    { key: 'present',  label: 'Presentes',    value: groupStats?.present  ?? 0, icon: <Users size={18} strokeWidth={1.75} />,         tone: 'accent',  statusText: 'Alumnos en clase' },
    { key: 'absent',   label: 'Inasistentes', value: groupStats?.absent   ?? 0, icon: <UserMinus size={18} strokeWidth={1.75} />,     tone: 'warning', statusText: !hasActivity ? 'No hay estudiantes' : undefined },
    { key: 'late',     label: 'Llegadas tarde', value: groupStats?.late   ?? 0, icon: <Clock size={18} strokeWidth={1.75} />,         tone: 'warning', statusText: !hasActivity ? 'No hay estudiantes' : (groupStats?.late ? 'Ingresos después de hora' : undefined) },
    { key: 'alert',    label: 'Alertas',      value: groupStats?.alerts   ?? 0, icon: <AlertTriangle size={18} strokeWidth={1.75} />, tone: 'danger',  statusText: !hasActivity ? 'No hay estudiantes' : undefined },
    { key: 'permiso',  label: 'Permisos',     value: groupStats?.permisos ?? 0, icon: <Activity size={18} strokeWidth={1.75} />,      tone: 'success', statusText: !hasActivity ? 'No hay estudiantes' : undefined },
  ];

  return (
    <div className="space-y-8">
      {parentLoading ? (
        <Surface className="relative">
          <div className="flex w-full items-center justify-between px-5 py-4">
            <div className="space-y-2">
              <Skeleton className="h-3 w-48" />
              <Skeleton className="h-5 w-40" />
            </div>
            <Skeleton className="h-5 w-5 rounded-control" />
          </div>
        </Surface>
      ) : (
        <>
          <Surface className="relative">
            <button
              onClick={() => setGroupOpen((v) => !v)}
              className="flex w-full items-center justify-between px-5 py-4 text-left"
            >
              <div className="flex items-center gap-2">
                <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
                <div>
                  <p className="text-label text-[var(--nx-text)]">Asistencia diaria</p>
                  <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">{selectedGroup ? formatGroupName(selectedGroup) : 'Elegir grupo'}</p>
                </div>
              </div>
              <Search size={18} className="text-[var(--nx-text-muted)]" />
            </button>

            <AnimatePresence>
              {groupOpen && (
                <motion.div
                  initial={{ opacity: 0, y: -6 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -6 }}
                  transition={{ duration: 0.15 }}
                  className="absolute left-0 right-0 top-full z-30 overflow-hidden border-t border-[var(--nx-border)] bg-[var(--nx-surface)]"
                  style={{ maxHeight: '260px', overflowY: 'auto' }}
                >
                  <div className="sticky top-0 border-b border-[var(--nx-border)] bg-[var(--nx-surface)] p-3">
                    <Input
                      autoFocus
                      placeholder="Buscar grupo…"
                      value={groupQuery}
                      onChange={(e) => setGroupQuery(e.target.value)}
                      leftIcon={<Search size={14} className="text-[var(--nx-text-muted)]" />}
                    />
                  </div>
                  {filteredGroups.length === 0 ? (
                    <p className="px-4 py-3 text-center text-body-sm text-[var(--nx-text-muted)]">Sin resultados</p>
                  ) : (
                    filteredGroups.map((g) => (
                      <button
                        key={g}
                        onClick={() => { setSelectedGroup(g); setGroupOpen(false); setGroupQuery(''); }}
                        className="w-full border-b border-[var(--nx-border)] px-4 py-3 text-left text-body text-[var(--nx-text)] transition-colors last:border-0 hover:bg-[var(--nx-surface-subtle)]"
                        style={{ fontWeight: g === selectedGroup ? '600' : undefined }}
                      >
                        {formatGroupName(g)}
                      </button>
                    ))
                  )}
                </motion.div>
              )}
            </AnimatePresence>
          </Surface>

          {groupNames.length === 0 && (
            <SituationLine
              icon={<AlertTriangle size={18} />}
              label="Atención"
              value="No se encontraron grupos asignados"
              detail="Verifique su asignación en horarios"
              scheme="warning"
            />
          )}

          {selectedGroup && !groupLoading && groupStats && !hasActivity && (
            <Surface className="p-4">
              <NexoChatBubble message={`El grupo ${formatGroupName(selectedGroup)} no tiene registros de ingreso hoy.`} />
            </Surface>
          )}

          {selectedGroup && (
            groupLoading ? (
              <SkeletonMetrics count={5} />
            ) : (
              <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                {cards.map((s) => (
                  <StatCard
                    key={s.key}
                    icon={s.icon}
                    label={s.label}
                    value={s.value}
                    tone={s.tone}
                    onClick={() => openDetail(s.key)}
                  />
                ))}
              </div>
            )
          )}

          <StreamList events={events.slice(0, 8)} loading={eventsLoading} showIssuer />

          <AnimatePresence>
            {activeCategory && (
              <TeacherDetailDrawer
                category={activeCategory}
                groupName={selectedGroup}
                data={detailData}
                loading={detailLoading}
                emptyWarning={selectedGroup && !hasActivity}
                onClose={() => setActiveCategory(null)}
              />
            )}
          </AnimatePresence>
        </>
      )}
    </div>
  );
};

/* ── Teacher Detail Drawer ── */

const MONTHS_ES_D = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
function fmtDetailDate(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d)) return String(iso);
  const day = d.getDate();
  const month = MONTHS_ES_D[d.getMonth()];
  const year = d.getFullYear();
  let h = d.getHours();
  const mm = String(d.getMinutes()).padStart(2,'0');
  const ampm = h >= 12 ? 'pm' : 'am';
  h = h % 12 || 12;
  if (h === 12 && mm === '00' && ampm === 'am') return `${day} ${month} ${year}`;
  return `${day} ${month} ${year}, ${h}:${mm} ${ampm}`;
}
const ENUM_ES_D = {
  SUCCESS: 'Exitoso', FAILED: 'Fallido', PENDING: 'Pendiente', APPROVED: 'Aprobado', REJECTED: 'Rechazado',
  SOS_WEBAPP: 'Alerta SOS', SOS_DEVICE: 'Alerta SOS (dispositivo)', WRONG_CLASSROOM: 'Salón incorrecto',
  INGRESO_NORMAL: 'Ingreso normal', INGRESO_TARDE: 'Ingreso tarde',
  class: 'Salida de clase', school: 'Salida del colegio', trip: 'Salida pedagógica',
  RISK_ALERT_HIGH: 'Riesgo Alto', RISK_ALERT_MEDIUM: 'Riesgo Medio', RISK_ALERT_LOW: 'Riesgo Bajo',
  LATE_ARRIVAL: 'Llegada Tarde', EARLY_EXIT: 'Salida Temprana', EVASION_INTERNA: 'Evasión Interna',
  SPAM_BIOMETRIC: 'Spam Biométrico', BIOMETRIC_FAILURE: 'Falla Biometría', UNAUTHORIZED_ABSENCE: 'Fuga'
};
function humanizeDetailVal(v) {
  if (v === null || v === undefined) return '—';
  const s = String(v).trim();
  return ENUM_ES_D[s] || ENUM_ES_D[s.toUpperCase()] || s;
}

const TeacherDetailDrawer = ({ category, groupName, scopeLabel = 'grupo', data, loading, emptyWarning, onClose }) => {
  const { user } = useAuth();
  const config = CATEGORY_LABELS[category];
  const [searchQuery, setSearchQuery] = useState('');
  const [localData, setLocalData] = useState([]);
  const navigate = useNavigate();
  // BUG-07 FIX: ref para limpiar el timeout si el componente se desmonta antes de que expire
  const successTimerRef = useRef(null);

  useEffect(() => {
    setLocalData(data || []);
  }, [data]);

  // Cleanup del timer al desmontar — evita memory leak y crash en iOS/Tauri
  useEffect(() => {
    return () => {
      if (successTimerRef.current) clearTimeout(successTimerRef.current);
    };
  }, []);

  // Sort alphabetically by last_name then first_name
  const sorted = [...localData].sort((a, b) => {
    const la = (a.last_name || '').toLowerCase();
    const lb = (b.last_name || '').toLowerCase();
    if (la !== lb) return la.localeCompare(lb, 'es');
    return (a.first_name || '').toLowerCase().localeCompare((b.first_name || '').toLowerCase(), 'es');
  });

  const filteredData = searchQuery.trim()
    ? sorted.filter(row =>
        (`${row.last_name} ${row.first_name}`).toLowerCase().includes(searchQuery.toLowerCase())
      )
    : sorted;

  const getColumns = () => {
    const base = [
      { key: '_student', label: 'Estudiante' },
      { key: 'group_name', label: 'Grupo' },
    ];
    switch (category) {
      case 'present':  return [...base, { key: 'last_entry',  label: 'Último ingreso' }];
      case 'absent':   return [...base, { key: 'absent_since', label: 'Desde' }];
      case 'late':     return [...base, { key: 'late_at', label: 'Hora de llegada' }];
      case 'alert':    return [...base, { key: 'alert_type', label: 'Evento' }, { key: 'alert_at', label: 'Fecha' }, user?.role !== ROLES.DOCENTE ? { key: '_action', label: 'Acción' } : null].filter(Boolean);
      case 'permiso':  return [...base, { key: 'permiso_type', label: 'Tipo' }, { key: 'permiso_at', label: 'Fecha' }, { key: 'reason', label: 'Motivo' }];
      default:         return base;
    }
  };

  const columns = getColumns();

  const [trackedStudents, setTrackedStudents] = useState(new Set());
  const [successModal, setSuccessModal] = useState(null); // { studentName }
  const [submitError, setSubmitError] = useState(null);

  const handleStartTracking = async (studentId, studentName) => {
    setTrackedStudents(prev => new Set(prev).add(studentId));
    try {
      const res = await trackingApi.startTracking(studentId);
      if (res.status === 'ok') {
        // Show success mini-modal
        setSuccessModal({ studentName });
        // BUG-07 FIX: guardar ref del timer para poder cancelarlo al desmontar
        successTimerRef.current = setTimeout(() => {
          setLocalData(prev => prev.filter(r => r.student_id !== studentId));
        }, 1500);
        // Notificar a la página de Seguimiento que debe refrescar
        window.dispatchEvent(new CustomEvent('nexo:tracking-refresh'));
      } else {
        setSubmitError(humanizeError(res, 'No se pudo iniciar el seguimiento'));
        setTrackedStudents(prev => {
          const next = new Set(prev);
          next.delete(studentId);
          return next;
        });
      }
    } catch (e) {
      console.error(e);
      setSubmitError(humanizeError(e, 'Error al iniciar el seguimiento'));
      setTrackedStudents(prev => {
        const next = new Set(prev);
        next.delete(studentId);
        return next;
      });
    }
  };

  const renderCell = (col, row) => {
    if (col.key === '_student') {
      return (
        <span className="font-bold">{row.last_name} {row.first_name}</span>
      );
    }
    if (col.key === '_action') {
      // Hide tracking button for DOCENTE role
      if (user?.role === ROLES.DOCENTE) {
        return null;
      }
      const isTracked = trackedStudents.has(row.student_id);
      return (
        <button
          onClick={() => handleStartTracking(row.student_id, `${row.last_name} ${row.first_name}`)}
          disabled={isTracked}
          className={`text-[10px] font-bold uppercase tracking-widest px-2 py-1 rounded transition-colors flex items-center justify-end gap-1 w-full ${
            isTracked
              ? "text-[var(--nx-success)] bg-[var(--nx-success)]/10"
              : "text-[var(--nx-accent)] hover:bg-[var(--nx-accent)]/10"
          }`}
        >
          {isTracked ? (
            <>✓ En Seguimiento</>
          ) : (
            <>Empezar Seguimiento</>
          )}
        </button>
      );
    }
    const v = row[col.key];
    if (col.key === 'group_name' || col.key === 'group' || col.key === 'grupo') {
      return formatGroupName(String(v));
    }
    if (v === null || v === undefined) return '—';
    if (col.key.includes('_at') || col.key.includes('entry') || col.key.includes('since')) {
      return fmtDetailDate(v);
    }
    return humanizeDetailVal(v);
  };

  return (
    <>
      <Drawer
        title={config?.label}
        context={`${filteredData.length} estudiante${filteredData.length !== 1 ? 's' : ''}`}
        onClose={onClose}
        size="lg"
      >
        <div className="p-6">
          <div className="mb-5 flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
            <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
            <p className="text-label text-[var(--nx-text)]">{groupName}</p>
          </div>
          <div className="mb-4">
            <Input
              placeholder="Buscar estudiante…"
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />}
            />
          </div>

          {loading ? (
            <SkeletonRows count={5} />
          ) : filteredData.length > 0 ? (
            <Surface className="overflow-x-auto">
              <table className="w-full min-w-[440px]">
                <thead>
                  <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                    {columns.map((col) => (
                      <th
                        key={col.key}
                        className={`px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)] ${col.key === '_action' ? 'text-right' : ''}`}
                      >
                        {col.label}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--nx-border)]">
                  {filteredData.map((row, i) => (
                    <tr key={i} className="hover:bg-[var(--nx-surface-subtle)] transition-colors">
                      {columns.map((col) => (
                        <td key={col.key} className={`px-4 py-3 text-body text-[var(--nx-text)] whitespace-nowrap ${col.key === '_action' ? 'text-right' : ''}`}>
                          {renderCell(col, row)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="border-t border-[var(--nx-border)] px-4 py-2">
                <p className="text-caption text-[var(--nx-text-muted)]">
                  {filteredData.length} estudiante{filteredData.length !== 1 ? 's' : ''}
                </p>
              </div>
            </Surface>
          ) : (
            <EmptyState
              icon={<Sparkles size={32} className="text-[var(--nx-success)]" />}
              title={searchQuery ? 'Sin coincidencias' : emptyWarning ? 'Atención' : 'No hay nada para mostrar.'}
              description={
                searchQuery
                  ? 'Ningún estudiante coincide con tu búsqueda.'
                  : emptyWarning
                    ? `La ${scopeLabel} ${groupName} no tiene registros de ingreso hoy.`
                    : category === 'present'
                      ? `Esta ${scopeLabel} no ha tenido ingresos el día de hoy.`
                      : `No hay estudiantes en esta categoría para la ${scopeLabel} y el período seleccionado.`
              }
            />
          )}
        </div>
      </Drawer>

      <AnimatePresence>
        {successModal && (
          <motion.div
            initial={{ opacity: 0, y: 50, scale: 0.9 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 20, scale: 0.9 }}
            className="fixed bottom-6 right-6 z-[60] w-full max-w-sm rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4 shadow-high"
          >
            <div className="flex items-start gap-3">
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--nx-subtle-bg-success)] text-[var(--nx-success)]">
                <CheckCircle2 size={18} strokeWidth={2.5} />
              </div>
              <div className="flex-1">
                <p className="text-label text-[var(--nx-text)]">En Seguimiento</p>
                <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">
                  <span className="font-medium text-[var(--nx-text)]">{successModal.studentName}</span> ha sido agregado al módulo de seguimiento.
                </p>
              </div>
              <button onClick={() => setSuccessModal(null)} className="p-1 text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]">
                <X size={16} />
              </button>
            </div>
            <div className="mt-4 flex justify-end">
              <Button size="sm" onClick={() => navigate('/casos')}>Ir a Casos Activos</Button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      <AnimatePresence>
        {submitError && (
          <motion.div
            initial={{ opacity: 0, y: 50, scale: 0.9 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 20, scale: 0.9 }}
            className="fixed bottom-6 right-6 z-[60] w-full max-w-sm rounded-surface border border-[var(--nx-danger)] bg-[var(--nx-surface)] p-4 shadow-high"
          >
            <div className="flex items-start gap-3">
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]">
                <AlertTriangle size={18} strokeWidth={2.5} />
              </div>
              <div className="flex-1">
                <p className="text-label text-[var(--nx-text)]">Error</p>
                <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">{submitError}</p>
              </div>
              <button onClick={() => setSubmitError(null)} className="p-1 text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]">
                <X size={16} />
              </button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>
    </>
  );
};


// ── Portero / Auxiliar ────────────────────────────────────────────────────────

const StaffDashboard = ({ stats, loading: parentLoading }) => {
  const [events, setEvents] = useState([]);
  const [eventsLoading, setEventsLoading] = useState(true);

  useEffect(() => {
    const loadEvents = async () => {
      try {
        const res = await dashboardApi.getEvents();
        setEvents(res?.status === 'ok' ? res.data || [] : []);
      } catch (e) {
        console.error(e);
        setEvents([]);
      } finally {
        setEventsLoading(false);
      }
    };
    loadEvents();
  }, []);

  return (
    <div className="space-y-8">
      <TasksEmptyState loading={parentLoading} />
      <StreamList events={events.slice(0, 8)} loading={eventsLoading} showIssuer />
    </div>
  );
};

export default Dashboard;
