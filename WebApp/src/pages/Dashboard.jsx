import { useState, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import {
  Users, Activity, AlertTriangle, UserMinus,
  ChevronRight, LogOut, Bell, FileText, Search,
} from 'lucide-react';
import { motion } from 'framer-motion';
import { dashboardApi } from '../api/dashboard';
import { ROLES } from '../config/roles';

const EMPTY_STATS = {
  presentCount: 0, absentCount: 0, alertsCount: 0,
  pendingTasks: [], studentsByGroup: {},
  groupStats: { present: 0, absent: 0, alerts: 0, outside: 0 },
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
      {/* ── Real-Time Insights ── */}
      <section>
        <SectionLabel title="Real-Time Insights" sub="Estado biométrico en tiempo real" />
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
        {/* ── Live Stream ── */}
        <section className="lg:col-span-2">
          <SectionLabel title="Live Stream" sub="Eventos institucionales recientes" />
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

        {/* ── Action Shortcuts ── */}
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

const TeacherDashboard = ({ stats, loading }) => {
  const [selectedGroup, setSelectedGroup] = useState('');
  const groupStats = [
    { label: 'Presentes',    value: stats?.groupStats?.present || 0, accent: '#003366' },
    { label: 'Inasistentes', value: stats?.groupStats?.absent  || 0, accent: '#0D4080' },
    { label: 'Alertas',      value: stats?.groupStats?.alerts  || 0, accent: '#DC2626' },
    { label: 'Fuera',        value: stats?.groupStats?.outside || 0, accent: '#00A67E' },
  ];

  return (
    <div className="space-y-5">
      <SectionLabel title="Panel Docente" sub="Control de asistencia por grupo" />

      {loading ? (
        <div style={{ border: '1.5px solid #E2E8F0' }} className="p-6 space-y-3 bg-white dark:bg-slate-900">
          {[1, 2].map(i => <Pulse key={i} className="h-10 w-full" />)}
        </div>
      ) : (
        <>
          <div style={{ border: '1.5px solid #E2E8F0' }} className="p-5 bg-white dark:bg-slate-900">
            <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase', marginBottom: '8px' }}>
              Seleccionar Grupo
            </p>
            <select
              value={selectedGroup}
              onChange={e => setSelectedGroup(e.target.value)}
              className="w-full p-3 text-sm font-bold outline-none dark:bg-slate-800 dark:text-white"
              style={{ border: '1.5px solid #E2E8F0', backgroundColor: '#F8FAFC', color: '#0F172A' }}
            >
              <option value="">— Elegir grupo —</option>
              {Object.keys(stats?.studentsByGroup || {}).map(g => (
                <option key={g} value={g}>{g}</option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-4" style={{ border: '1.5px solid #E2E8F0' }}>
            {groupStats.map((s, i) => (
              <div key={i}
                   className="bg-white dark:bg-slate-900 text-center py-5 px-4"
                   style={{ borderRight: i < 3 ? '1.5px solid #E2E8F0' : 'none' }}>
                <p style={{ fontSize: '40px', fontWeight: 900, color: s.accent, lineHeight: 1 }}>{s.value}</p>
                <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.15em', color: '#94A3B8', textTransform: 'uppercase', marginTop: '6px' }}>
                  {s.label}
                </p>
              </div>
            ))}
          </div>
        </>
      )}
    </div>
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
