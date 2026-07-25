/**
 * SCR-HOME-01 Dashboard / Inicio por rol
 * Nueva generación: KPIs, grupo activo, eventos, riesgo y acciones propias por rol.
 */
import { useState, useEffect, useRef } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import {
  Users, Activity, AlertTriangle, UserMinus, ChevronRight,
  Search, X, Loader2, CalendarDays, CheckCircle2, FileText
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { dashboardApi } from '../api/dashboard';
import { trackingApi } from '../api/tracking';
import { ROLES } from '../config/roles';
import { Card, CardHeader } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';
import { Skeleton, SkeletonText } from '../components/ui/Skeleton';
import { EmptyState } from '../components/ui/EmptyState';
import { Section, Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
import { Input } from '../components/ui/Input';

const EMPTY_STATS = {
  presentCount: 0, absentCount: 0, alertsCount: 0, permCount: 0,
  pendingTasks: [], studentsByGroup: {}, teacherGroups: [],
  groupStats: { present: 0, absent: 0, alerts: 0, permisos: 0, outside: 0 },
};

const fmtTime = (iso) => {
  if (!iso) return '';
  const d = new Date(iso);
  if (isNaN(d)) return String(iso);
  return d.toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit', hour12: true });
};

const StreamItem = ({ ev, onClick, showIssuer }) => (
  <button
    onClick={onClick}
    className="flex w-full items-center gap-3 rounded-control px-4 py-3 text-left transition-colors hover:bg-[var(--nx-surface-subtle)]"
  >
    <span
      className="h-2 w-2 shrink-0 rounded-full"
      style={{ backgroundColor: ev.type === 'alert' ? 'var(--nx-danger)' : 'var(--nx-success)' }}
    />
    <span className="flex-1 truncate text-body text-[var(--nx-text)]">{ev.label}</span>
    {showIssuer && ev.issuer && <span className="hidden sm:block text-caption text-[var(--nx-text-muted)]">{ev.issuer}</span>}
    <span className="shrink-0 text-caption text-[var(--nx-text-muted)]">{ev.time}</span>
    <ChevronRight size={14} className="shrink-0 text-[var(--nx-text-muted)]" />
  </button>
);

const StreamList = ({ events, loading, emptyTitle, showIssuer, onItemClick }) => {
  if (loading) {
    return (
      <Surface className="p-5 space-y-3">
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
        <Skeleton className="h-10 w-full" />
      </Surface>
    );
  }
  if (!events.length) {
    return (
      <Surface>
        <EmptyState title={emptyTitle} description="Aún no hay novedades para mostrar." />
      </Surface>
    );
  }
  return (
    <Surface className="divide-y divide-[var(--nx-border)]">
      {events.map((ev, i) => (
        <StreamItem key={i} ev={ev} showIssuer={showIssuer} onClick={() => onItemClick?.(ev)} />
      ))}
    </Surface>
  );
};

const Dashboard = () => {
  const { user } = useAuth();
  const [stats, setStats] = useState(EMPTY_STATS);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const load = async () => {
      try { setStats({ ...EMPTY_STATS, ...(await dashboardApi.getStats() || {}) }); }
      catch (e) { console.error(e); setStats(EMPTY_STATS); }
      finally { setLoading(false); }
    };
    load();
  }, [user]);

  switch (user?.role) {
    case ROLES.RECTOR:
    case ROLES.COORDINADOR:
      return <AdminDashboard stats={stats} loading={loading} />;
    case ROLES.SECRETARIA:
    case ROLES.PSICORIENTADOR:
      return <SecretaryDashboard tasks={stats.pendingTasks || []} loading={loading} />;
    case ROLES.DOCENTE:
      return <TeacherDashboard stats={stats} loading={loading} />;
    case ROLES.PORTERO:
    case ROLES.AUXILIAR:
      return <StaffDashboard />;
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

const AdminDashboard = ({ stats, loading }) => {
  const [events, setEvents] = useState([]);
  const [eventsLoading, setEventsLoading] = useState(true);
  const [activeCategory, setActiveCategory] = useState(null);
  const [detailData, setDetailData] = useState([]);
  const [detailLoading, setDetailLoading] = useState(false);

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
    { key: 'present',  label: 'Presentes',    value: stats.presentCount, icon: Users,         sub: 'Ingresos hoy',            accent: 'var(--nx-accent)' },
    { key: 'absent',   label: 'Inasistentes', value: stats.absentCount,  icon: UserMinus,     sub: 'Sin registro de entrada', accent: 'var(--nx-accent)' },
    { key: 'alert',    label: 'Alertas',      value: stats.alertsCount,  icon: AlertTriangle, sub: 'Requieren atención',      accent: 'var(--nx-danger)' },
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
      <Section title="Visión de la jornada" subtitle={todayLabel()} />
      {loading ? (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {[1, 2, 3].map((i) => <Skeleton key={i} className="h-32" />)}
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {kpis.map((k) => (
            <Card key={k.key} asAction onClick={() => openDetail(k.key)}>
              <div className="flex items-start justify-between">
                <span className="text-label text-[var(--nx-text-muted)] uppercase">{k.label}</span>
                <k.icon size={18} strokeWidth={1.75} style={{ color: k.accent }} />
              </div>
              <p className="mt-4 text-display text-[var(--nx-text)]" style={{ color: k.key === 'alert' ? 'var(--nx-danger)' : undefined }}>
                {k.value ?? 0}
              </p>
              <p className="text-caption text-[var(--nx-text-muted)] mt-1">{k.sub}</p>
            </Card>
          ))}
        </div>
      )}

      <Section title="Eventos recientes" subtitle="Últimas novedades institucionales" />
      <StreamList events={stream} loading={eventsLoading} emptyTitle="Sin eventos recientes" showIssuer />

      <AnimatePresence>
        {activeCategory && (
          <TeacherDetailDrawer
            category={activeCategory}
            groupName="Toda la Institución"
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

// ── Secretaria / Psicoorientador ──────────────────────────────────────────────

const SecretaryDashboard = ({ tasks = [], loading }) => {
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

  const stream = events.slice(0, 8).map((ev, i) => ({ ...ev, index: i }));

  return (
    <div className="space-y-8">
      <Section title="Tareas del día" subtitle="Seguimiento y novedades" />
      {loading ? (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          {[1, 2, 3].map((i) => <Skeleton key={i} className="h-24" />)}
        </div>
      ) : (
        tasks.length > 0 && (
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {tasks.slice(0, 6).map((t, i) => (
              <Card key={i}>
                <p className="text-body text-[var(--nx-text)] truncate">{t.label}</p>
                <p className="text-caption text-[var(--nx-text-muted)] mt-1">{t.time}</p>
              </Card>
            ))}
          </div>
        )
      )}

      <Section title="Eventos recientes" subtitle="Últimas novedades institucionales" />
      <StreamList events={stream} loading={eventsLoading} emptyTitle="Sin eventos recientes" showIssuer />
    </div>
  );
};

// ── Docente / Psicorientador ──────────────────────────────────────────────────

const CATEGORY_LABELS = {
  present:  { label: 'Presentes',    accent: 'var(--nx-accent)', icon: Users },
  absent:   { label: 'Inasistentes', accent: 'var(--nx-accent)', icon: UserMinus },
  alert:    { label: 'Alertas',      accent: 'var(--nx-danger)', icon: AlertTriangle },
  permiso:  { label: 'Permisos',     accent: 'var(--nx-success)', icon: Activity },
};

const DetailBadge = ({ scheme, children }) => (
  <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-caption font-medium border ${
    scheme === 'danger' ? 'bg-[color-mix(in_oklch,var(--nx-danger)_10%,transparent)] text-[var(--nx-danger)] border-[color-mix(in_oklch,var(--nx-danger)_25%,transparent)]' :
    scheme === 'success' ? 'bg-[color-mix(in_oklch,var(--nx-success)_12%,transparent)] text-[var(--nx-success)] border-[color-mix(in_oklch,var(--nx-success)_25%,transparent)]' :
    'bg-[color-mix(in_oklch,var(--nx-accent)_10%,transparent)] text-[var(--nx-accent)] border-[color-mix(in_oklch,var(--nx-accent)_25%,transparent)]'
  }`}>
    {children}
  </span>
);

// Helper: fecha local en formato YYYY-MM-DD (timezone-safe, no UTC shift)
const localDateStr = (date = new Date()) => {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

const TeacherDashboard = ({ stats, loading: parentLoading }) => {
  const { user } = useAuth();
  const [selectedGroup, setSelectedGroup] = useState('');
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
  const normalizeGroup = g => String(g || '').replace(/[\s\-]/g, '').toLowerCase();
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

  const filteredGroups = groupQuery.trim()
    ? groupNames.filter(g => g.toLowerCase().includes(groupQuery.toLowerCase()))
    : groupNames;

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

  const cards = [
    { key: 'present',  label: 'Presentes',    value: groupStats?.present  ?? 0, accent: 'var(--nx-accent)' },
    { key: 'absent',   label: 'Inasistentes', value: groupStats?.absent   ?? 0, accent: 'var(--nx-accent)' },
    { key: 'alert',    label: 'Alertas',      value: groupStats?.alerts   ?? 0, accent: 'var(--nx-danger)' },
    { key: 'permiso',  label: 'Permisos',     value: groupStats?.permisos ?? 0, accent: 'var(--nx-success)' },
  ];

  const hasActivity = groupStats && (groupStats.present + groupStats.absent + groupStats.alerts + groupStats.permisos) > 0;

  return (
    <div className="space-y-8">
      <Section title="Panel docente" subtitle="Control de asistencia por grupo — Hoy" />

      {parentLoading ? (
        <Surface className="p-6 space-y-3">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </Surface>
      ) : (
        <>
          <Surface className="relative">
            <button
              onClick={() => setGroupOpen((v) => !v)}
              className="flex w-full items-center justify-between px-5 py-4 text-left"
            >
              <div>
                <p className="text-caption uppercase text-[var(--nx-text-muted)]">Grupo seleccionado</p>
                <p className="text-h3 text-[var(--nx-accent)] mt-0.5">{selectedGroup || '— Elegir grupo —'}</p>
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
                        style={{ color: g === selectedGroup ? 'var(--nx-accent)' : undefined }}
                      >
                        {g}
                      </button>
                    ))
                  )}
                </motion.div>
              )}
            </AnimatePresence>
          </Surface>

          {groupNames.length === 0 && (
            <div className="rounded-control bg-[color-mix(in_oklch,var(--nx-warning)_10%,transparent)] px-4 py-3 text-body-sm text-[var(--nx-warning)]">
              No se encontraron grupos asignados. Verifique su asignación en horarios.
            </div>
          )}

          {selectedGroup && !groupLoading && groupStats && !hasActivity && (
            <Surface className="flex items-start gap-3 p-4">
              <AlertTriangle size={20} className="mt-0.5 shrink-0 text-[var(--nx-warning)]" />
              <div>
                <p className="text-body text-[var(--nx-text)]">
                  El grupo <span className="font-medium text-[var(--nx-accent)]">{selectedGroup}</span> no tiene registros de ingreso hoy.
                </p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">Verifique que el nodo de control esté operativo.</p>
              </div>
            </Surface>
          )}

          {selectedGroup && (
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              {groupLoading ? (
                [1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-28" />)
              ) : (
                cards.map((s) => (
                  <Card key={s.key} asAction onClick={() => openDetail(s.key)}>
                    <p className="text-display" style={{ color: s.key === 'alert' ? 'var(--nx-danger)' : s.key === 'permiso' ? 'var(--nx-success)' : 'var(--nx-text)' }}>
                      {s.value}
                    </p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-1 uppercase">{s.label}</p>
                  </Card>
                ))
              )}
            </div>
          )}

          <Section title="Eventos recientes" subtitle="Últimas novedades de tus grupos" />
          <StreamList events={events.slice(0, 8)} loading={eventsLoading} emptyTitle="Sin eventos recientes" showIssuer />

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

const TeacherDetailDrawer = ({ category, groupName, data, loading, emptyWarning, onClose }) => {
  const { user } = useAuth();
  const config = CATEGORY_LABELS[category];
  const Icon = config?.icon || Users;
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
      case 'alert':    return [...base, { key: 'alert_type', label: 'Evento' }, { key: 'alert_at', label: 'Fecha' }, user?.role !== ROLES.DOCENTE ? { key: '_action', label: 'Acción' } : null].filter(Boolean);
      case 'permiso':  return [...base, { key: 'permiso_type', label: 'Tipo' }, { key: 'permiso_at', label: 'Fecha' }, { key: 'reason', label: 'Motivo' }];
      default:         return base;
    }
  };

  const columns = getColumns();

  const [trackedStudents, setTrackedStudents] = useState(new Set());
  const [successModal, setSuccessModal] = useState(null); // { studentName }

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
        alert("Error al iniciar seguimiento: " + (res.message || "Error del servidor"));
        // Revert on error
        setTrackedStudents(prev => {
          const next = new Set(prev);
          next.delete(studentId);
          return next;
        });
      }
    } catch (e) {
      console.error(e);
      const backendError = e.response?.data?.detail || e.response?.data?.message || e.message;
      alert(`Error al iniciar el seguimiento: ${backendError}`);
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
    if (v === null || v === undefined) return '—';
    if (col.key.includes('_at') || col.key.includes('entry') || col.key.includes('since')) {
      return fmtDetailDate(v);
    }
    return humanizeDetailVal(v);
  };

  return (
    <>
      <motion.div
        key="t-ov"
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }}
        onClick={onClose}
        className="fixed inset-0 z-40 bg-[color-mix(in_oklch,var(--nx-text)_40%,transparent)]"
      />
      <motion.div
        key="t-dw"
        initial={{ x: '100%' }}
        animate={{ x: 0 }}
        exit={{ x: '100%' }}
        transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
        className="fixed right-0 top-0 z-50 flex h-full w-full max-w-[640px] flex-col overflow-hidden border-l border-[var(--nx-border)] bg-[var(--nx-surface)]"
      >
        <div className="flex shrink-0 items-center justify-between border-b border-[var(--nx-border)] px-6 py-4">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-control bg-[color-mix(in_oklch,var(--nx-accent)_10%,transparent)]">
              <Icon size={18} strokeWidth={1.75} style={{ color: config?.accent || 'var(--nx-accent)' }} />
            </div>
            <div>
              <p className="text-h3 text-[var(--nx-text)]">{config?.label} — {groupName}</p>
              <p className="text-caption text-[var(--nx-text-muted)] uppercase">
                {filteredData.length} estudiante{filteredData.length !== 1 ? 's' : ''}
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-2 text-[var(--nx-text-muted)] hover:text-[var(--nx-text)] rounded-control hover:bg-[var(--nx-surface-subtle)] transition-colors">
            <X size={20} />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto p-6">
          <div className="mb-4">
            <Input
              placeholder="Buscar estudiante..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />}
            />
          </div>

          {loading ? (
            <div className="flex flex-col items-center justify-center gap-4 py-20">
              <Loader2 size={32} className="animate-spin text-[var(--nx-accent)]" />
              <p className="text-body-sm text-[var(--nx-text-muted)] uppercase">Cargando datos...</p>
            </div>
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
              icon={<CalendarDays size={32} className="text-[var(--nx-border)]" />}
              title={searchQuery ? 'Sin coincidencias' : 'Sin registros hoy'}
              description={
                searchQuery
                  ? 'Ningún estudiante coincide con tu búsqueda.'
                  : emptyWarning
                    ? 'Este grupo no tiene registros de ingreso para el día de hoy. Verifique que el nodo de control esté operativo.'
                    : 'No se encontraron estudiantes en esta categoría para el período seleccionado.'
              }
            />
          )}
        </div>
      </motion.div>

      <AnimatePresence>
        {successModal && (
          <motion.div
            initial={{ opacity: 0, y: 50, scale: 0.9 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 20, scale: 0.9 }}
            className="fixed bottom-6 right-6 z-[60] w-full max-w-sm rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4 shadow-high"
          >
            <div className="flex items-start gap-3">
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[color-mix(in_oklch,var(--nx-success)_12%,transparent)] text-[var(--nx-success)]">
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
    </>
  );
};


// ── Portero / Auxiliar ────────────────────────────────────────────────────────

const StaffDashboard = () => {
  const [events, setEvents] = useState([]);
  const [eventsLoading, setEventsLoading] = useState(true);

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

  const stream = events.slice(0, 8).map((ev, i) => ({
    label: ev.label,
    time: ev.time,
    type: ev.type,
    index: i,
  }));

  return (
    <div className="space-y-8">
      <Section title="Panel de servicio" subtitle="Eventos recientes" />
      <StreamList events={stream} loading={eventsLoading} emptyTitle="Sin eventos recientes" showIssuer />
    </div>
  );
};

export default Dashboard;
