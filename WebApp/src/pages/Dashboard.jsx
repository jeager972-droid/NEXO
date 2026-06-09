import { useState, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import {
  Users, Activity, AlertTriangle, UserMinus,
  ChevronRight, LogOut, Bell, FileText, Search,
  X, Loader2, CalendarDays
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { dashboardApi } from '../api/dashboard';
import { ROLES } from '../config/roles';

const EMPTY_STATS = {
  presentCount: 0, absentCount: 0, alertsCount: 0, permCount: 0,
  pendingTasks: [], studentsByGroup: {}, teacherGroups: [],
  groupStats: { present: 0, absent: 0, alerts: 0, permisos: 0, outside: 0 },
};

// ── Skeleton primitives ───────────────────────────────────────────────────────

const Pulse = ({ className = '' }) => (
  <div className={`animate-pulse bg-slate-200 dark:bg-slate-800 rounded-sm ${className}`} />
);

const KpiSkeleton = () => (
  <div className="p-6 space-y-3 bg-white dark:bg-slate-900">
    <Pulse className="h-2 w-20" />
    <Pulse className="h-10 w-16" />
    <Pulse className="h-2 w-28" />
  </div>
);

const StreamSkeleton = () => (
  <div className="space-y-0">
    {[1, 2, 3, 4, 5].map(i => (
      <div key={i} className="flex items-center gap-3 py-3" style={{ borderBottom: '1px solid #F1F5F9' }}>
        <Pulse className="h-1.5 w-1.5 rounded-full shrink-0" />
        <Pulse className="h-2 flex-1" />
        <Pulse className="h-2 w-12" />
      </div>
    ))}
  </div>
);

// ── Shared UI primitives ──────────────────────────────────────────────────────

const SectionLabel = ({ title, sub }) => (
  <div className="mb-3">
    <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none' }}>
      {title}
    </p>
    {sub && (
      <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', marginTop: '2px', letterSpacing: '-0.01em' }}
         className="dark:text-slate-200">
        {sub}
      </p>
    )}
  </div>
);

const KpiCard = ({ label, value, icon: Icon, sub, accent = '#003366', delay = 0 }) => (
  <motion.div
    className="bg-white dark:bg-slate-900 p-6 space-y-3"
    initial={{ opacity: 0, y: 10 }}
    animate={{ opacity: 1, y: 0 }}
    transition={{ duration: 0.28, delay, ease: [0.25, 0.46, 0.45, 0.94] }}
  >
    <div className="flex items-center justify-between">
      <span style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
        {label}
      </span>
      <Icon size={15} strokeWidth={2} style={{ color: accent }} />
    </div>
    <p className="dark:text-slate-100"
       style={{ fontSize: '44px', fontWeight: 900, color: '#0F172A', lineHeight: 1, letterSpacing: '-0.02em' }}>
      {value}
    </p>
    <p style={{ fontSize: '10px', color: '#94A3B8', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.1em' }}>
      {sub}
    </p>
  </motion.div>
);

const StreamRow = ({ label, time, type = 'default', index }) => {
  const dot = type === 'alert' ? '#EF4444' : type === 'bio' ? '#00A67E' : '#003366';
  return (
    <motion.div
      initial={{ opacity: 0, x: -4 }}
      animate={{ opacity: 1, x: 0 }}
      transition={{ delay: index * 0.045, duration: 0.2 }}
      className="flex items-center gap-3 py-2.5"
      style={{ borderBottom: '1px solid #F1F5F9' }}
    >
      <span className="shrink-0 block h-1.5 w-1.5 rounded-full" style={{ backgroundColor: dot }} />
      <span className="flex-1 text-xs font-medium text-slate-600 dark:text-slate-400 truncate">{label}</span>
      <span style={{ fontSize: '9px', color: '#CBD5E1', fontWeight: 600 }}>{time}</span>
    </motion.div>
  );
};

// ── Dashboard router ──────────────────────────────────────────────────────────

const Dashboard = () => {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [stats,   setStats]   = useState(EMPTY_STATS);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const load = async () => {
      const id = user?.school_id || user?.inst_id || user?.institucion_id;
      if (!id) { setLoading(false); return; }
      try { setStats({ ...EMPTY_STATS, ...(await dashboardApi.getStats(id) || {}) }); }
      catch (e) { console.error(e); setStats(EMPTY_STATS); }
      finally { setLoading(false); }
    };
    load();
  }, [user]);

  switch (user?.role) {
    case ROLES.SUPER_RECTOR:
    case ROLES.RECTOR:
    case ROLES.COORDINADOR:
      return <AdminDashboard stats={stats} loading={loading} navigate={navigate} />;
    case ROLES.SECRETARIA:
      return <SecretaryDashboard tasks={stats.pendingTasks || []} loading={loading} />;
    case ROLES.DOCENTE:
    case ROLES.PSICORIENTADOR:
      return <TeacherDashboard stats={stats} loading={loading} />;
    case ROLES.PORTERO:
    case ROLES.AUXILIAR:
      return <StaffDashboard navigate={navigate} logout={logout} />;
    default:
      return (
        <p className="text-center py-20 text-slate-400 dark:text-slate-600 text-xs uppercase tracking-widest">
          Rol no autorizado: {user?.role ?? 'ninguno'}
        </p>
      );
  }
};

// ── Command Center — Admin / Rector / Coordinador ─────────────────────────────

const AdminDashboard = ({ stats, loading, navigate }) => {
  const kpis = [
    { label: 'Presentes',    value: stats.presentCount, icon: Users,         sub: 'Ingresos hoy',            accent: '#003366', delay: 0    },
    { label: 'Inasistentes', value: stats.absentCount,  icon: UserMinus,     sub: 'Sin registro de entrada', accent: '#0D4080', delay: 0.06 },
    { label: 'Alertas',      value: stats.alertsCount,  icon: AlertTriangle, sub: 'Requieren atención',      accent: '#DC2626', delay: 0.12 },
  ];

  const stream = (stats.pendingTasks || []).slice(0, 8).map((t, i) => ({
    label: t.title || t.description || 'Evento registrado',
    time:  t.time  || '—',
    type:  t.type  === 'alert' ? 'alert' : 'default',
    index: i,
  }));

  const shortcuts = [
    { label: 'Operación', icon: Activity, path: '/operacion' },
    { label: 'Informes',  icon: FileText, path: '/informes'  },
    { label: 'Consulta',  icon: Search,   path: '/consulta'  },
    { label: 'Auditoría', icon: Users,    path: '/auditoria' },
  ];

  return (
    <div className="space-y-6">
      {/* ── Estadísticas del Día ── */}
      <section>
        <SectionLabel title="Estadísticas del Día" sub="Datos de la institución en tiempo real" />
        <div className="grid grid-cols-1 md:grid-cols-3" style={{ border: '1.5px solid #E2E8F0' }}>
          {loading
            ? [1, 2, 3].map(i => <KpiSkeleton key={i} />)
            : kpis.map((k, i) => (
                <div key={i} style={{ borderRight: i < 2 ? '1.5px solid #E2E8F0' : 'none' }}>
                  <KpiCard {...k} />
                </div>
              ))
          }
        </div>
      </section>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* ── Eventos Recientes ── */}
        <section className="lg:col-span-2">
          <SectionLabel title="Eventos Recientes" sub="Últimas novedades institucionales" />
          <div className="bg-white dark:bg-slate-900 px-5 py-2" style={{ border: '1.5px solid #E2E8F0', minHeight: '200px' }}>
            {loading
              ? <StreamSkeleton />
              : stream.length > 0
                ? stream.map((ev, i) => <StreamRow key={i} {...ev} index={i} />)
                : (
                  <div className="flex items-center justify-center h-40">
                    <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#CBD5E1', textTransform: 'uppercase' }}>
                      Sin eventos recientes
                    </p>
                  </div>
                )
            }
          </div>
        </section>

        {/* ── Accesos Rápidos ── */}
        <section>
          <SectionLabel title="Accesos Rápidos" sub="Módulos del sistema" />
          <div style={{ border: '1.5px solid #E2E8F0' }}>
            {shortcuts.map((s, i, arr) => (
              <button
                key={s.path}
                onClick={() => navigate(s.path)}
                className="group flex items-center gap-3 w-full px-4 py-3.5 bg-white dark:bg-slate-900 hover:bg-gov-900 dark:hover:bg-gov-900 transition-colors duration-150"
                style={{ borderBottom: i < arr.length - 1 ? '1.5px solid #F1F5F9' : 'none' }}
              >
                <s.icon size={15} strokeWidth={2} className="text-gov-900 group-hover:text-white transition-colors shrink-0" />
                <span className="flex-1 text-xs font-bold uppercase text-slate-700 dark:text-slate-300 group-hover:text-white transition-colors"
                      style={{ letterSpacing: '0.1em' }}>
                  {s.label}
                </span>
                <ChevronRight size={13} className="text-slate-300 group-hover:text-white/60 transition-colors shrink-0" />
              </button>
            ))}
          </div>
        </section>
      </div>
    </div>
  );
};

// ── Secretaria ────────────────────────────────────────────────────────────────

const SecretaryDashboard = ({ tasks, loading }) => (
  <div className="space-y-5">
    <SectionLabel title="Panel Secretaría" sub="Tareas administrativas pendientes" />
    <div style={{ border: '1.5px solid #E2E8F0' }} className="bg-white dark:bg-slate-900">
      {loading
        ? <div className="p-6"><StreamSkeleton /></div>
        : tasks.length > 0
          ? tasks.map((t, i) => (
              <div key={t.id || i}
                   className="flex items-center gap-4 px-5 py-3.5 hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
                   style={{ borderBottom: i < tasks.length - 1 ? '1.5px solid #F1F5F9' : 'none' }}>
                <span className="shrink-0 h-1.5 w-1.5 rounded-full block" style={{ backgroundColor: '#00A67E' }} />
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-bold text-slate-800 dark:text-slate-200 truncate">{t.title}</p>
                  <p style={{ fontSize: '9px', color: '#94A3B8', fontWeight: 700, letterSpacing: '0.1em', textTransform: 'uppercase' }}>{t.time}</p>
                </div>
                <ChevronRight size={14} strokeWidth={2} className="text-slate-300 shrink-0" />
              </div>
            ))
          : (
            <div className="flex items-center justify-center py-16">
              <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#CBD5E1', textTransform: 'uppercase' }}>
                No hay tareas pendientes
              </p>
            </div>
          )
      }
    </div>
  </div>
);

// ── Docente / Psicorientador ──────────────────────────────────────────────────

const CATEGORY_LABELS = {
  present:  { label: 'Presentes',    accent: '#003366', icon: Users },
  absent:   { label: 'Inasistentes', accent: '#0D4080', icon: UserMinus },
  alert:    { label: 'Alertas',      accent: '#DC2626', icon: AlertTriangle },
  permiso:  { label: 'Permisos',     accent: '#00A67E', icon: Activity },
};

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
    const id = user?.school_id || user?.inst_id || user?.institucion_id;
    if (!id) return;
    setGroupLoading(true);
    dashboardApi.getStats(id, selectedGroup)
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

  const cards = [
    { key: 'present',  label: 'Presentes',    value: groupStats?.present  ?? 0, accent: '#003366' },
    { key: 'absent',   label: 'Inasistentes', value: groupStats?.absent   ?? 0, accent: '#0D4080' },
    { key: 'alert',    label: 'Alertas',      value: groupStats?.alerts   ?? 0, accent: '#DC2626' },
    { key: 'permiso',  label: 'Permisos',     value: groupStats?.permisos ?? 0, accent: '#00A67E' },
  ];

  const hasActivity = groupStats && (groupStats.present + groupStats.absent + groupStats.alerts + groupStats.permisos) > 0;

  return (
    <div className="space-y-5">
      <SectionLabel title="Panel Docente" sub="Control de asistencia por grupo — Hoy" />

      {parentLoading ? (
        <div style={{ border: '1.5px solid #E2E8F0' }} className="p-6 space-y-3 bg-white dark:bg-slate-900">
          {[1, 2].map(i => <Pulse key={i} className="h-10 w-full" />)}
        </div>
      ) : (
        <>
          {/* Group selector — searchable dropdown */}
          <div style={{ border: '1.5px solid #E2E8F0', position: 'relative' }} className="bg-white dark:bg-slate-900">
            <button
              onClick={() => setGroupOpen(v => !v)}
              className="w-full flex items-center justify-between px-5 py-3.5 text-left"
              style={{ background: 'none', border: 'none', cursor: 'pointer' }}
            >
              <div>
                <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>Grupo seleccionado</p>
                <p className="text-sm font-black dark:text-white" style={{ color: selectedGroup ? '#003366' : '#CBD5E1', marginTop: '2px' }}>
                  {selectedGroup || '— Elegir grupo —'}
                </p>
              </div>
              <Search size={15} strokeWidth={2} className="text-slate-300 shrink-0" />
            </button>

            {/* Dropdown panel */}
            <AnimatePresence>
              {groupOpen && (
                <motion.div
                  initial={{ opacity: 0, y: -6 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -6 }}
                  transition={{ duration: 0.15 }}
                  className="absolute left-0 right-0 z-30 bg-white dark:bg-slate-900 shadow-xl"
                  style={{ top: '100%', border: '1.5px solid #E2E8F0', borderTop: 'none', maxHeight: '260px', overflowY: 'auto' }}
                >
                  {/* Search input */}
                  <div className="sticky top-0 bg-white dark:bg-slate-900 px-3 py-2" style={{ borderBottom: '1px solid #F1F5F9' }}>
                    <div className="relative">
                      <Search size={13} strokeWidth={2} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none" />
                      <input
                        autoFocus
                        type="text"
                        placeholder="Buscar grupo…"
                        value={groupQuery}
                        onChange={e => setGroupQuery(e.target.value)}
                        className="w-full pl-8 pr-3 py-2 text-xs outline-none dark:bg-slate-900 dark:text-white"
                        style={{ border: '1.5px solid #E2E8F0', backgroundColor: '#F8FAFC', fontWeight: 600, color: '#0F172A' }}
                      />
                    </div>
                  </div>

                  {filteredGroups.length === 0 ? (
                    <p className="px-4 py-3 text-xs text-slate-400 text-center">Sin resultados</p>
                  ) : (
                    filteredGroups.map(g => (
                      <button
                        key={g}
                        onClick={() => { setSelectedGroup(g); setGroupOpen(false); setGroupQuery(''); }}
                        className="w-full text-left px-4 py-2.5 text-sm font-bold hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                        style={{ color: g === selectedGroup ? '#003366' : '#334155', background: g === selectedGroup ? 'rgba(0,51,102,0.04)' : 'none', border: 'none', cursor: 'pointer', borderBottom: '1px solid #F8FAFC' }}
                      >
                        {g}
                      </button>
                    ))
                  )}
                </motion.div>
              )}
            </AnimatePresence>
          </div>

          {groupNames.length === 0 && (
            <p className="text-xs text-amber-600 font-semibold">No se encontraron grupos asignados. Verifique su asignación en horarios.</p>
          )}

          {/* Warning: group selected but no activity today */}
          {selectedGroup && !groupLoading && groupStats && !hasActivity && (
            <div className="flex items-center gap-3 p-5 bg-white dark:bg-slate-900" style={{ border: '1.5px solid #E2E8F0' }}>
              <AlertTriangle size={20} strokeWidth={1.5} className="text-amber-400 shrink-0" />
              <div>
                <p className="text-xs font-bold text-slate-600 dark:text-slate-400">
                  El grupo <span className="text-[#003366]">{selectedGroup}</span> no tiene registros de ingreso hoy
                </p>
                <p style={{ fontSize: '11px', color: '#94A3B8' }}>Verifique que el nodo de control esté operativo o que los estudiantes hayan ingresado</p>
              </div>
            </div>
          )}

          {/* 4 Stat cards — always clickable even with 0 values */}
          {selectedGroup && (
            <div className="grid grid-cols-2 md:grid-cols-4" style={{ border: '1.5px solid #E2E8F0' }}>
              {groupLoading ? (
                [1,2,3,4].map(i => (
                  <div key={i} className="bg-white dark:bg-slate-900 text-center py-5 px-4" style={{ borderRight: i < 4 ? '1.5px solid #E2E8F0' : 'none' }}>
                    <Pulse className="h-10 w-16 mx-auto" />
                  </div>
                ))
              ) : (
                cards.map((s, i) => (
                  <button
                    key={s.key}
                    onClick={() => openDetail(s.key)}
                    className="bg-white dark:bg-slate-900 text-center py-5 px-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors"
                    style={{ borderRight: i < 3 ? '1.5px solid #E2E8F0' : 'none' }}
                  >
                    <p style={{ fontSize: '40px', fontWeight: 900, color: s.accent, lineHeight: 1 }}>{s.value}</p>
                    <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.15em', color: '#94A3B8', textTransform: 'uppercase', marginTop: '6px' }}>
                      {s.label}
                    </p>
                  </button>
                ))
              )}
            </div>
          )}
        </>
      )}

      {/* ── Detail Drawer ── */}
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
};
function humanizeDetailVal(v) {
  if (v === null || v === undefined) return '—';
  const s = String(v).trim();
  return ENUM_ES_D[s] || ENUM_ES_D[s.toUpperCase()] || s;
}

const TeacherDetailDrawer = ({ category, groupName, data, loading, emptyWarning, onClose }) => {
  const config = CATEGORY_LABELS[category];
  const Icon = config?.icon || Users;
  const [searchQuery, setSearchQuery] = useState('');

  // Sort alphabetically by last_name then first_name
  const sorted = [...data].sort((a, b) => {
    const la = (a.last_name || '').toLowerCase();
    const lb = (b.last_name || '').toLowerCase();
    if (la !== lb) return la.localeCompare(lb, 'es');
    return (a.first_name || '').toLowerCase().localeCompare((b.first_name || '').toLowerCase(), 'es');
  });

  const filteredData = searchQuery.trim()
    ? sorted.filter(row =>
        (`${row.last_name} ${row.first_name}`).toLowerCase().includes(searchQuery.toLowerCase()) ||
        (row.document_number || '').toLowerCase().includes(searchQuery.toLowerCase())
      )
    : sorted;

  // Columnas dinámicas — "Estudiante" unificado (Apellido Nombre)
  const getColumns = () => {
    const base = [
      { key: '_student', label: 'Estudiante' },
      { key: 'document_number', label: 'Documento' },
      { key: 'group_name', label: 'Grupo' },
    ];
    switch (category) {
      case 'present':  return [...base, { key: 'last_entry',  label: 'Último ingreso' }];
      case 'absent':   return [...base, { key: 'absent_since', label: 'Desde' }];
      case 'alert':    return [...base, { key: 'alert_type', label: 'Tipo de alerta' }, { key: 'alert_at', label: 'Fecha' }];
      case 'permiso':  return [...base, { key: 'permiso_type', label: 'Tipo' }, { key: 'permiso_at', label: 'Fecha' }, { key: 'reason', label: 'Motivo' }];
      default:         return base;
    }
  };

  const columns = getColumns();

  const renderCell = (col, row) => {
    if (col.key === '_student') {
      return (
        <div className="flex items-center gap-2">
          <div className="w-7 h-7 shrink-0 flex items-center justify-center text-xs font-black text-white"
               style={{ backgroundColor: '#003366' }}>
            {(row.last_name || row.first_name || '?').charAt(0)}
          </div>
          <span className="font-bold">{row.last_name} {row.first_name}</span>
        </div>
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
      <motion.div key="t-ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }} className="fixed z-40"
        style={{ top: '52px', left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(2,6,23,0.45)' }}
        onClick={onClose} />
      <motion.div key="t-dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
        transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
        className="fixed right-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
        style={{ top: '56px', bottom: 0, maxWidth: '640px', borderLeft: '1.5px solid #E2E8F0' }}
      >
        {/* Header */}
        <div className="shrink-0 flex items-center justify-between px-6 py-4" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-9 h-9" style={{ backgroundColor: 'rgba(0,51,102,0.08)' }}>
              <Icon size={16} strokeWidth={2} style={{ color: config?.accent || '#003366' }} />
            </div>
            <div>
              <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: '#1E293B' }}>
                {config?.label} — {groupName}
              </p>
              <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                {filteredData.length} estudiante{filteredData.length !== 1 ? 's' : ''} · Orden alfabético
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <X size={18} strokeWidth={2} />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-6">
          {/* Search bar */}
          <div className="mb-4 relative">
            <Search size={14} strokeWidth={2} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none" />
            <input
              type="text"
              placeholder="Buscar por apellido, nombre o documento…"
              value={searchQuery}
              onChange={e => setSearchQuery(e.target.value)}
              className="w-full pl-9 pr-4 py-2.5 outline-none dark:bg-slate-900 dark:text-white"
              style={{ border: '1.5px solid #E2E8F0', backgroundColor: '#F8FAFC', fontSize: '13px', fontWeight: 500, color: '#0F172A' }}
            />
          </div>

          {loading ? (
            <div className="flex flex-col items-center justify-center h-full py-20 gap-4">
              <Loader2 size={32} className="animate-spin text-[#003366] dark:text-slate-400" />
              <p style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.15em', color: '#94A3B8', textTransform: 'uppercase' }}>
                Cargando datos...
              </p>
            </div>
          ) : filteredData.length > 0 ? (
            <div className="overflow-x-auto" style={{ border: '1.5px solid #E2E8F0' }}>
              <table className="w-full min-w-[440px]">
                <thead>
                  <tr style={{ backgroundColor: '#F8FAFC', borderBottom: '1.5px solid #E2E8F0' }}>
                    {columns.map(col => (
                      <th key={col.key} className="px-4 py-3 text-left"
                        style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                        {col.label}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-slate-900">
                  {filteredData.map((row, i) => (
                    <tr key={i} className="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
                      style={{ borderBottom: i < filteredData.length - 1 ? '1px solid #F1F5F9' : 'none' }}>
                      {columns.map(col => (
                        <td key={col.key} className="px-4 py-3 text-sm font-semibold text-slate-700 dark:text-slate-300 whitespace-nowrap">
                          {renderCell(col, row)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
              <div className="px-4 py-2 bg-slate-50 dark:bg-slate-800 border-t border-slate-200">
                <p className="text-[10px] text-slate-400 font-medium">{filteredData.length} estudiante{filteredData.length !== 1 ? 's' : ''}</p>
              </div>
            </div>
          ) : (
            <div className="flex flex-col items-center justify-center h-full py-20 gap-4">
              <CalendarDays size={32} strokeWidth={1} className="text-slate-200 dark:text-slate-700" />
              <div className="text-center space-y-1">
                <p style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.15em', color: '#CBD5E1', textTransform: 'uppercase' }}>
                  {searchQuery ? 'Sin coincidencias' : 'Sin registros hoy'}
                </p>
                <p style={{ fontSize: '11px', color: '#CBD5E1' }} className="max-w-xs">
                  {searchQuery
                    ? 'Ningún estudiante coincide con tu búsqueda.'
                    : emptyWarning
                      ? 'Este grupo no tiene registros de ingreso para el día de hoy. Verifique que el nodo de control esté operativo.'
                      : 'No se encontraron estudiantes en esta categoría para el período seleccionado.'}
                </p>
              </div>
            </div>
          )}
        </div>
      </motion.div>
    </>
  );
};


// ── Portero / Auxiliar ────────────────────────────────────────────────────────

const StaffDashboard = ({ navigate, logout }) => (
  <div className="space-y-5">
    <SectionLabel title="Panel de Servicio" sub="Accesos operacionales rápidos" />
    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
      {[
        { label: 'Panel de Operación', icon: Activity, path: '/operacion',    accent: '#003366' },
        { label: 'Notificaciones',     icon: Bell,     path: '/notificaciones', accent: '#00A67E' },
      ].map(s => (
        <button
          key={s.path}
          onClick={() => navigate(s.path)}
          className="group flex flex-col items-start gap-4 p-7 bg-white dark:bg-slate-900 hover:bg-gov-900 dark:hover:bg-gov-900 transition-colors duration-200 text-left"
          style={{ border: '1.5px solid #E2E8F0' }}
        >
          <s.icon size={22} strokeWidth={1.5} style={{ color: s.accent }} className="group-hover:text-white transition-colors" />
          <span className="text-sm font-black uppercase text-slate-800 dark:text-white group-hover:text-white transition-colors"
                style={{ letterSpacing: '0.1em' }}>
            {s.label}
          </span>
        </button>
      ))}
    </div>
    <button
      onClick={logout}
      className="flex items-center gap-2 text-slate-400 hover:text-red-500 transition-colors"
      style={{ fontSize: '10px', fontWeight: 700, letterSpacing: '0.2em', textTransform: 'uppercase' }}
    >
      <LogOut size={14} strokeWidth={2} />
      Finalizar Sesión
    </button>
  </div>
);

export default Dashboard;
