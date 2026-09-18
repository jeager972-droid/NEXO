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
import { NexusInsights } from '../components/patterns/NexusInsights';
import { trackingApi } from '../api/tracking';
import { ROLES } from '../config/roles';
import { Skeleton, SkeletonMetrics, SkeletonRows } from '../components/ui/Skeleton';
import { EmptyState } from '../components/ui/EmptyState';
import { Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Input } from '../components/ui/Input';
import { Drawer } from '../components/ui/Overlay';
import { Badge } from '../components/ui/Badge';
import { StatCard } from '../components/patterns/StatCard';
import { SituationLine } from '../components/patterns/SituationLine';
import { NexoChatBubble } from '../components/patterns/NexoChat';
import { ScheduleTask, isTaskActive, isTaskDoneToday } from '../components/patterns/ScheduleTask';
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

const Dashboard = () => {
  const { user } = useAuth();
  const [stats, setStats] = useState(EMPTY_STATS);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const controller = new AbortController();
    const signal = controller.signal;
    const load = async () => {
      try {
        const data = await dashboardApi.getStats('', { signal });
        if (!signal.aborted) setStats({ ...EMPTY_STATS, ...(data || {}) });
      }
      catch (e) {
        if (!signal.aborted) { console.error(e); setStats(EMPTY_STATS); }
      }
      finally {
        if (!signal.aborted) setLoading(false);
      }
    };
    load();
    return () => controller.abort();
  }, [user]);

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
    // Bloque 1: Azul (presentes + permisos)
    { key: 'present',  label: 'Presentes',      value: stats.presentCount, icon: <CheckCircle2 size={18} strokeWidth={1.75} />, tone: 'accent',  statusText: stats.presentCount === 0 ? 'Sin ingresos registrados' : 'En clase ahora' },
    { key: 'permiso',  label: 'Permisos',       value: stats.permCount,    icon: <FileText size={18} strokeWidth={1.75} />,    tone: 'accent',  statusText: stats.permCount === 0 && stats.presentCount === 0 ? 'No hay estudiantes' : 'Permisos activos hoy' },
    // Bloque 2: Naranja (inasistentes + tardanzas)
    { key: 'absent',   label: 'Inasistentes',   value: stats.absentCount,  icon: <UserMinus size={18} strokeWidth={1.75} />,  tone: 'warning', statusText: stats.absentCount === 0 ? 'Sin inasistencias' : 'No registraron ingreso' },
    { key: 'late',     label: 'Llegadas tarde',  value: stats.lateCount,    icon: <Clock size={18} strokeWidth={1.75} />,      tone: 'warning', statusText: stats.lateCount === 0 ? 'Sin llegadas tarde' : 'Ingresos después de hora' },
    // Bloque 3: Rojo (alertas + evasiones fusionadas)
    { key: 'alert',    label: 'Alertas',        value: stats.alertsCount,  icon: <AlertTriangle size={18} strokeWidth={1.75} />, tone: 'danger', statusText: stats.alertsCount === 0 ? 'Sin alertas' : 'Requieren atención' },
  ];

  return (
    <div className="space-y-8">
      {showScheduleTask && (
        <ScheduleTask onDismiss={() => setShowScheduleTask(false)} />
      )}
      {loading ? (
        <SkeletonMetrics count={5} />
      ) : (
        <div className="space-y-4">          {/* PC: todas en una fila */}
          <div className="hidden md:grid md:grid-cols-5 gap-4">
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
          {/* Mobile: 2x2 normales + Alertas como barra larga abajo */}
          <div className="md:hidden space-y-4">
            <div className="grid grid-cols-2 gap-4">
              {kpis.filter(k => k.tone !== 'danger').map((k) => (
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
            {kpis.filter(k => k.tone === 'danger').map((k) => (
              <button
                key={k.key}
                type="button"
                onClick={() => openDetail(k.key)}
                className="nx-pressable flex w-full items-center gap-2.5 rounded-surface border border-[var(--nx-danger)] bg-[var(--nx-surface-danger)] px-3 py-2 text-left cursor-pointer hover:shadow-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--nx-accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--nx-canvas)]"
              >
                <span className="grid h-7 w-7 shrink-0 place-items-center rounded-control bg-[var(--nx-icon-bg-danger)] text-[color-mix(in_oklch,var(--nx-danger)_72%,var(--nx-icon-mix))]">
                  {k.icon}
                </span>
                <span className="nx-tnum text-body text-[var(--nx-text)]">
                  {typeof k.value === 'number' ? k.value.toLocaleString('es-CO') : k.value}
                </span>
                <span className="text-caption font-medium uppercase text-[var(--nx-text-muted)]">{k.label}</span>
              </button>
            ))}
          </div>
        </div>
      )}

      <NexusInsights />

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
  const { user } = useAuth();
  const navigate = useNavigate();

  return (
    <div className="space-y-8">
      <div className="border-b border-[var(--nx-border)] pb-3">
        <div className="border-l-2 border-[var(--nx-accent)] pl-3">
          <p className="text-label text-[var(--nx-text)]">Tareas pendientes</p>
        </div>
      </div>
      <TasksEmptyState loading={parentLoading} />
      <NexusInsights />
    </div>
  );
};

// ── Psicorientador ────────────────────────────────────────────────────────────

const CounselorDashboard = ({ stats, loading: parentLoading }) => {
  const { user } = useAuth();
  const navigate = useNavigate();

  return (
    <div className="space-y-8">      {parentLoading ? (
        <SkeletonMetrics count={4} />
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <StatCard icon={<Users size={18} strokeWidth={1.75} />} label="Estudiantes" value={Object.keys(stats?.studentsByGroup || {}).length} tone="accent" />
          <StatCard icon={<FileText size={18} strokeWidth={1.75} />} label="Permisos" value={stats?.permCount ?? 0} tone="accent" />
          <StatCard icon={<AlertTriangle size={18} strokeWidth={1.75} />} label="Alertas" value={stats?.alertsCount ?? 0} tone="danger" />
          <StatCard icon={<Activity size={18} strokeWidth={1.75} />} label="Seguimientos" value={0} tone="warning" />
        </div>
      )}
      <NexusInsights />
    </div>
  );
};

// ── Docente / Psicorientador ──────────────────────────────────────────────────

const CATEGORY_LABELS = {
  present:  { label: 'Presentes',      accent: 'var(--nx-accent)', icon: Users },
  absent:   { label: 'Inasistentes',   accent: 'var(--nx-accent)', icon: UserMinus },
  late:     { label: 'Llegadas tarde', accent: 'var(--nx-warning)', icon: Clock },
  alert:    { label: 'Alertas',        accent: 'var(--nx-danger)', icon: AlertTriangle },
  permiso:  { label: 'Permisos',       accent: 'var(--nx-accent)', icon: Activity },
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
  const { user } = useAuth();
  const navigate = useNavigate();
  const [selectedGroup, setSelectedGroup] = useState(() => {
    try { return localStorage.getItem(GROUP_KEY) || ''; } catch { return ''; }
  });
  const [groupStats, setGroupStats]       = useState(null);
  const [groupLoading, setGroupLoading]   = useState(false);

  const [activeCategory, setActiveCategory] = useState(null);
  const [detailData, setDetailData]         = useState([]);
  const [detailLoading, setDetailLoading]   = useState(false);

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
    // Bloque 1: Azul (presentes + permisos)
    { key: 'present',  label: 'Presentes',    value: groupStats?.present  ?? 0, icon: <Users size={18} strokeWidth={1.75} />,         tone: 'accent',  statusText: 'Alumnos en clase' },
    { key: 'permiso',  label: 'Permisos',     value: groupStats?.permisos ?? 0, icon: <Activity size={18} strokeWidth={1.75} />,      tone: 'accent',  statusText: !hasActivity ? 'No hay estudiantes' : undefined },
    // Bloque 2: Naranja (inasistentes + tardanzas)
    { key: 'absent',   label: 'Inasistentes', value: groupStats?.absent   ?? 0, icon: <UserMinus size={18} strokeWidth={1.75} />,     tone: 'warning', statusText: !hasActivity ? 'No hay estudiantes' : undefined },
    { key: 'late',     label: 'Llegadas tarde', value: groupStats?.late   ?? 0, icon: <Clock size={18} strokeWidth={1.75} />,         tone: 'warning', statusText: !hasActivity ? 'No hay estudiantes' : (groupStats?.late ? 'Ingresos después de hora' : undefined) },
    // Bloque 3: Rojo (alertas)
    { key: 'alert',    label: 'Alertas',      value: groupStats?.alerts   ?? 0, icon: <AlertTriangle size={18} strokeWidth={1.75} />, tone: 'danger',  statusText: !hasActivity ? 'No hay estudiantes' : undefined },
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
              <div className="flex items-center gap-3 border-l-2 border-[var(--nx-accent)] pl-3">
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
              <div className="space-y-4">                {/* PC: todas en una fila */}
                <div className="hidden md:grid md:grid-cols-5 gap-4">
                  {cards.map((s) => (
                    <StatCard
                      key={s.key}
                      icon={s.icon}
                      label={s.label}
                      value={s.value}
                      tone={s.tone}
                      statusText={s.statusText}
                      onClick={() => openDetail(s.key)}
                    />
                  ))}
                </div>
                {/* Mobile: 2x2 normales + Alertas como barra larga abajo */}
                <div className="md:hidden space-y-4">
                  <div className="grid grid-cols-2 gap-4">
                    {cards.filter(s => s.tone !== 'danger').map((s) => (
                      <StatCard
                        key={s.key}
                        icon={s.icon}
                        label={s.label}
                        value={s.value}
                        tone={s.tone}
                        statusText={s.statusText}
                        onClick={() => openDetail(s.key)}
                      />
                    ))}
                  </div>
                  {cards.filter(s => s.tone === 'danger').map((s) => (
                    <button
                      key={s.key}
                      type="button"
                      onClick={() => openDetail(s.key)}
                      className="nx-pressable flex w-full items-center gap-2.5 rounded-surface border border-[var(--nx-danger)] bg-[var(--nx-surface-danger)] px-3 py-2 text-left cursor-pointer hover:shadow-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--nx-accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--nx-canvas)]"
                    >
                      <span className="grid h-7 w-7 shrink-0 place-items-center rounded-control bg-[var(--nx-icon-bg-danger)] text-[color-mix(in_oklch,var(--nx-danger)_72%,var(--nx-icon-mix))]">
                        {s.icon}
                      </span>
                      <span className="nx-tnum text-body text-[var(--nx-text)]">
                        {typeof s.value === 'number' ? s.value.toLocaleString('es-CO') : s.value}
                      </span>
                      <span className="text-caption font-medium uppercase text-[var(--nx-text-muted)]">{s.label}</span>
                    </button>
                  ))}
                </div>
              </div>
            )
          )}

          <NexusInsights />

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

const CATEGORY_SCHEMES = {
  present: 'success',
  absent: 'warning',
  late: 'warning',
  alert: 'danger',
  permiso: 'accent',
};

const getInitials = (first, last) => {
  const f = (first?.[0] || '').toUpperCase();
  const l = (last?.[0] || '').toUpperCase();
  return (f + l) || '?';
};

const getCategoryStatusText = (category) => {
  switch (category) {
    case 'present': return 'En clase ahora mismo';
    case 'absent': return 'Inasistente';
    case 'late': return 'Llegó tarde';
    case 'alert': return 'Estudiante en alerta';
    case 'permiso': return 'En permiso';
    default: return '';
  }
};

const renderProfileFields = (category, row) => {
  switch (category) {
    case 'present':
      return (
        <div>
          <p className="text-caption text-[var(--nx-text-muted)]">Último ingreso</p>
          <p className="text-body text-[var(--nx-text)]">{fmtDetailDate(row.last_entry)}</p>
        </div>
      );
    case 'absent':
      return (
        <div>
          <p className="text-caption text-[var(--nx-text-muted)]">Ausente desde</p>
          <p className="text-body text-[var(--nx-text)]">{fmtDetailDate(row.absent_since)}</p>
        </div>
      );
    case 'late':
      return (
        <div>
          <p className="text-caption text-[var(--nx-text-muted)]">Hora de llegada</p>
          <p className="text-body text-[var(--nx-text)]">{fmtDetailDate(row.late_at)}</p>
        </div>
      );
    case 'alert':
      return (
        <>
          <div>
            <p className="text-caption text-[var(--nx-text-muted)]">Tipo de alerta</p>
            <p className="text-body text-[var(--nx-text)]">{humanizeDetailVal(row.alert_type)}</p>
          </div>
          <div>
            <p className="text-caption text-[var(--nx-text-muted)]">Fecha</p>
            <p className="text-body text-[var(--nx-text)]">{fmtDetailDate(row.alert_at)}</p>
          </div>
          {row.classroom && (
            <div>
              <p className="text-caption text-[var(--nx-text-muted)]">Salón</p>
              <p className="text-body text-[var(--nx-text)]">{row.classroom}</p>
            </div>
          )}
          {row.detected_by && (
            <div>
              <p className="text-caption text-[var(--nx-text-muted)]">Detectado por</p>
              <p className="text-body text-[var(--nx-text)]">{humanizeDetailVal(row.detected_by)}</p>
            </div>
          )}
        </>
      );
    case 'permiso':
      return (
        <>
          <div>
            <p className="text-caption text-[var(--nx-text-muted)]">Tipo</p>
            <p className="text-body text-[var(--nx-text)]">{humanizeDetailVal(row.permiso_type)}</p>
          </div>
          <div>
            <p className="text-caption text-[var(--nx-text-muted)]">Motivo</p>
            <p className="text-body text-[var(--nx-text)]">{row.reason || '—'}</p>
          </div>
        </>
      );
    default: return null;
  }
};

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

  const [trackedStudents, setTrackedStudents] = useState(new Set());
  const [successModal, setSuccessModal] = useState(null); // { studentName }
  const [submitError, setSubmitError] = useState(null);
  const [profileStudent, setProfileStudent] = useState(null);

  const openStudentProfile = (row) => {
    setProfileStudent(row);
  };

  const closeStudentProfile = () => {
    setProfileStudent(null);
  };

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

  return (
    <>
      <Drawer
        title={config?.label}
        context={`${filteredData.length} estudiante${filteredData.length !== 1 ? 's' : ''}`}
        onClose={onClose}
        size="md"
      >
        <div className="p-6">
          <div className="mb-5 border-b border-[var(--nx-border)] pb-3">
            <div className="border-l-2 border-[var(--nx-accent)] pl-3">
              <p className="text-label text-[var(--nx-text)]">{groupName}</p>
            </div>
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
            <div className="space-y-2">
              {filteredData.map((row, i) => (
                <div
                  key={row.student_id || i}
                  onClick={() => openStudentProfile(row)}
                  className="flex items-center gap-3 rounded-control border bg-[var(--nx-surface-card)] p-3 cursor-pointer hover:shadow-low transition-shadow"
                  style={{
                    borderLeftWidth: '3px',
                    borderLeftColor: `var(--nx-${CATEGORY_SCHEMES[category]})`,
                    borderColor: `var(--nx-border-${CATEGORY_SCHEMES[category]})`,
                  }}
                >
                  {/* Avatar circular con iniciales */}
                  <div className="h-9 w-9 shrink-0 rounded-full bg-[var(--nx-surface-subtle)] flex items-center justify-center text-caption font-semibold text-[var(--nx-text-muted)]">
                    {getInitials(row.first_name, row.last_name)}
                  </div>

                  {/* Nombre y grupo */}
                  <div className="min-w-0 flex-1">
                    <p className="text-body-sm font-medium text-[var(--nx-text)] truncate">
                      {row.last_name} {row.first_name}
                    </p>
                    <p className="text-caption text-[var(--nx-text-muted)] truncate">
                      {row.group_name ? formatGroupName(row.group_name) : 'Sin grupo'}
                    </p>
                  </div>

                  {/* Acción de seguimiento para alertas (no docentes) */}
                  {category === 'alert' && user?.role !== ROLES.DOCENTE && (
                    <button
                      onClick={(e) => {
                        e.stopPropagation();
                        handleStartTracking(row.student_id, `${row.last_name} ${row.first_name}`);
                      }}
                      disabled={trackedStudents.has(row.student_id)}
                      className="text-caption font-semibold text-[var(--nx-accent)] hover:underline shrink-0 disabled:opacity-60"
                    >
                      {trackedStudents.has(row.student_id) ? 'En seguimiento' : 'Seguir'}
                    </button>
                  )}

                  {/* Botón Ver detalles */}
                  <button
                    onClick={(e) => { e.stopPropagation(); openStudentProfile(row); }}
                    className="flex items-center gap-1 text-caption text-[var(--nx-accent)] font-semibold hover:underline shrink-0"
                  >
                    Ver detalles <ChevronRight size={12} />
                  </button>
                </div>
              ))}
            </div>
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

      {profileStudent && (
        <Drawer
          title="Detalle del estudiante"
          onClose={closeStudentProfile}
          size="md"
        >
          <div className="p-6 space-y-6">
            <div className="border-b border-[var(--nx-border)] pb-3">
              <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                <p className="text-label text-[var(--nx-text)]">Datos del estudiante</p>
              </div>
            </div>
            <div className="flex items-center gap-4">
              <div className="h-16 w-16 shrink-0 rounded-full bg-[var(--nx-subtle-bg-accent)] flex items-center justify-center text-h2 font-semibold text-[var(--nx-accent)]">
                {getInitials(profileStudent.first_name, profileStudent.last_name)}
              </div>
              <div className="min-w-0 flex-1">
                <p className="text-h2 text-[var(--nx-text)]">
                  {profileStudent.last_name} {profileStudent.first_name}
                </p>
                <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">
                  {profileStudent.group_name ? formatGroupName(profileStudent.group_name) : 'Sin grupo'}
                </p>
                <div className="mt-2">
                  <Badge scheme={CATEGORY_SCHEMES[category]} dot>
                    {getCategoryStatusText(category)}
                  </Badge>
                </div>
              </div>
            </div>

            <Surface className="divide-y divide-[var(--nx-border)]">
              {[
                { label: 'Documento', value: profileStudent.document || profileStudent.documento || '—' },
                { label: 'Grupo', value: profileStudent.group_name ? formatGroupName(profileStudent.group_name) : '—' },
                { label: 'Estado actual', value: getCategoryStatusText(category) },
              ].map((row) => (
                <div key={row.label} className="flex items-center gap-3 px-5 py-3.5">
                  <span className="text-body-sm text-[var(--nx-text-muted)] w-28">{row.label}</span>
                  <span className="text-body text-[var(--nx-text)] flex-1">{row.value}</span>
                </div>
              ))}
            </Surface>

            {/* Información específica según categoría */}
            <div className="space-y-3">
              <div className="border-b border-[var(--nx-border)] pb-3">
                <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                  <p className="text-label text-[var(--nx-text)]">Información de la métrica</p>
                </div>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {renderProfileFields(category, profileStudent)}
              </div>
            </div>
          </div>
        </Drawer>
      )}

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
  const { user } = useAuth();
  const navigate = useNavigate();

  return (
    <div className="space-y-8">
      <div className="border-b border-[var(--nx-border)] pb-3">
        <div className="border-l-2 border-[var(--nx-accent)] pl-3">
          <p className="text-label text-[var(--nx-text)]">Tareas pendientes</p>
        </div>
      </div>
      <TasksEmptyState loading={parentLoading} />
      <NexusInsights />
    </div>
  );
};

export default Dashboard;
