import { useState, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import { useNavigate } from 'react-router-dom';
import {
  Users, 
  Activity, 
  AlertTriangle, 
  UserMinus, 
  ChevronRight,
  ClipboardList,
  Users2,
  LogOut,
  Bell
} from 'lucide-react';
import { dashboardApi } from '../api/dashboard';
import { cn } from '../utils/cn';
import LogoNexo from '../components/LogoNexo';

import { ROLES } from '../config/roles';

const EMPTY_STATS = {
  presentCount: 0,
  absentCount: 0,
  alertsCount: 0,
  pendingTasks: [],
  studentsByGroup: {},
  groupStats: {
    present: 0,
    absent: 0,
    alerts: 0,
    outside: 0
  }
};

const Dashboard = () => {
  const { user, logout } = useAuth();
  const navigate = useNavigate();
  const [stats, setStats] = useState(EMPTY_STATS);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchStats = async () => {
      const schoolId = user?.school_id || user?.inst_id || user?.institucion_id;
      if (!schoolId) {
        setStats(EMPTY_STATS);
        setLoading(false);
        return;
      }
      try {
        const data = await dashboardApi.getStats(schoolId);
        setStats({ ...EMPTY_STATS, ...(data || {}) });
      } catch (err) {
        console.error(err);
        setStats(EMPTY_STATS);
      } finally {
        setLoading(false);
      }
    };
    fetchStats();
  }, [user]);

  if (loading) return <div className="min-h-[60vh] flex items-center justify-center font-black text-institutional-900 uppercase tracking-widest animate-pulse">Sincronizando datos institucionales...</div>;

  // Renderiza el dashboard según el rol unificado
  return (
    <div className="animate-in fade-in duration-500">
      {(() => {
        switch (user?.role) {
          case ROLES.RECTOR:
          case ROLES.COORDINADOR:
            return <AdminDashboard user={user} stats={stats} />;
          case ROLES.SECRETARIA:
            return <SecretaryDashboard user={user} tasks={stats.pendingTasks || []} />;
          case ROLES.DOCENTE:
          case ROLES.PSICORIENTADOR:
            return <TeacherDashboard user={user} stats={stats || EMPTY_STATS} />;
          case ROLES.PORTERO:
          case ROLES.AUXILIAR:
            return <StaffDashboard user={user} stats={stats} navigate={navigate} logout={logout} />;
          default:
            return <div className="dark:text-white text-center py-20 font-black uppercase tracking-widest">Acceso no autorizado - Rol: {user?.role || 'NINGUNO'}</div>;
        }
      })()}
    </div>
  );
};

/**
 * DASHBOARD: RECTOR Y COORDINADOR
 */
const AdminDashboard = ({ user, stats }) => {
  const statCards = [
    { label: 'Estudiantes Presentes', value: stats?.presentCount || '0', icon: Users, color: 'text-institutional-600', darkColor: 'dark:text-institutional-500', bg: 'bg-institutional-50', darkBg: 'dark:bg-institutional-900/20', sub: 'Ingresos hoy' },
    { label: 'Inasistencias', value: stats?.absentCount || '0', icon: UserMinus, color: 'text-institutional-700', darkColor: 'dark:text-institutional-600', bg: 'bg-institutional-50', darkBg: 'dark:bg-institutional-900/20', sub: 'Sin reporte aún' },
    { label: 'Alertas', value: stats?.alertsCount || '0', icon: AlertTriangle, color: 'text-red-600', darkColor: 'dark:text-red-400', bg: 'bg-red-50', darkBg: 'dark:bg-red-900/20', sub: 'Requieren atención' },
  ];

  return (
    <div className="max-w-6xl mx-auto space-y-16 py-12 animate-in fade-in duration-700">
      <div className="text-center space-y-4">
        <h2 className="text-5xl font-black text-gray-900 dark:text-white uppercase tracking-tight italic">Panel de Control</h2>
        <div className="flex items-center justify-center gap-2">
          <div className="w-12 h-1 bg-institutional-400 rounded-full"></div>
          <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.3em]">Estado institucional hoy</p>
          <div className="w-12 h-1 bg-institutional-400 rounded-full"></div>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-10">
        {statCards.map((stat, index) => (
          <div key={index} className="bg-white dark:bg-slate-900 p-12 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50 flex flex-col items-center text-center group hover:border-institutional-400 transition-all duration-500">
            <div className={cn("p-8 rounded-3xl mb-8 transition-all duration-500 group-hover:scale-110 group-hover:rotate-3 shadow-inner", stat.bg, stat.darkBg, stat.color, stat.darkColor)}>
              <stat.icon size={56} strokeWidth={1.5} />
            </div>
            <span className="text-xs font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] mb-2">{stat.label}</span>
            <span className="text-6xl font-black text-gray-900 dark:text-white mb-3 tracking-tighter">{stat.value}</span>
            <span className="text-sm text-gray-400 dark:text-slate-500 font-bold uppercase tracking-widest">{stat.sub}</span>
          </div>
        ))}
      </div>
    </div>
  );
};

/**
 * DASHBOARD: SECRETARIA
 * Enfoque en Tareas Pendientes.
 */
const SecretaryDashboard = ({ user, tasks }) => {
  const [showTasks, setShowTasks] = useState(false);

  return (
    <div className="max-w-4xl mx-auto min-h-[70vh] flex flex-col items-center justify-center space-y-12">
      <div className="text-center space-y-4">
        <h2 className="text-5xl font-black text-gray-900 dark:text-white uppercase tracking-tight">Tareas Pendientes</h2>
        <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.2em]">Gestión administrativa institucional</p>
      </div>

      {!showTasks ? (
        <button 
          onClick={() => setShowTasks(true)}
          className="group bg-white dark:bg-slate-900 p-20 rounded-[4rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50 transition-all hover:scale-105 flex flex-col items-center gap-6 hover:border-institutional-400"
        >
          <div className="p-8 bg-institutional-50 dark:bg-institutional-900/20 text-institutional-700 dark:text-institutional-400 rounded-3xl group-hover:rotate-6 transition-transform">
            <ClipboardList size={80} strokeWidth={1} />
          </div>
          <span className="text-2xl font-black text-gray-900 dark:text-white uppercase tracking-widest">Ver lista de tareas ({tasks.length})</span>
        </button>
      ) : (
        <div className="w-full space-y-6 animate-in fade-in slide-in-from-bottom-8 duration-700">
          {tasks.length > 0 ? (
            tasks.map(task => (
              <div key={task.id} className="bg-white dark:bg-slate-900 p-10 rounded-[2.5rem] border border-gray-50 dark:border-slate-800 shadow-soft dark:shadow-soft-dark flex items-center justify-between group hover:border-institutional-400 transition-all duration-300">
                <div className="flex items-center gap-8">
                  <div className="w-4 h-4 rounded-full bg-institutional-400 animate-pulse"></div>
                  <div>
                    <span className="text-xl font-black text-gray-900 dark:text-white block">{task.title}</span>
                    <span className="text-xs font-bold text-gray-400 dark:text-slate-500 uppercase tracking-widest mt-1">{task.time}</span>
                  </div>
                </div>
                <div className="p-4 bg-gray-50 dark:bg-slate-800 rounded-2xl group-hover:bg-institutional-900 group-hover:text-white transition-all">
                  <ChevronRight size={24} />
                </div>
              </div>
            ))
          ) : (
            <div className="text-center py-20 bg-white dark:bg-slate-900 rounded-[3rem] border-2 border-dashed border-gray-100 dark:border-slate-800">
              <p className="text-gray-400 dark:text-slate-500 font-black uppercase tracking-widest text-sm">No hay tareas pendientes</p>
            </div>
          )}
          <button 
            onClick={() => setShowTasks(false)}
            className="w-full py-8 text-gray-400 dark:text-slate-600 font-black text-xs uppercase tracking-[0.3em] hover:text-institutional-600 transition-colors"
          >
            [ Volver al panel central ]
          </button>
        </div>
      )}
    </div>
  );
};

/**
 * DASHBOARD: DOCENTE
 */
const TeacherDashboard = ({ user, stats }) => {
  const [selectedGroup, setSelectedGroup] = useState('');
  const [showStudents, setShowGroupList] = useState(false);
  
  const groupStats = [
    { label: 'Permiso fuera', value: stats?.groupStats?.outside || '0', color: 'text-institutional-400', darkColor: 'dark:text-institutional-400', bg: 'bg-institutional-50', darkBg: 'dark:bg-institutional-900/20' },
    { label: 'Alertas', value: stats?.groupStats?.alerts || '0', color: 'text-red-600', darkColor: 'dark:text-red-400', bg: 'bg-red-50', darkBg: 'dark:bg-red-900/20' },
    { label: 'Presentes', value: stats?.groupStats?.present || '0', color: 'text-institutional-600', darkColor: 'dark:text-institutional-500', bg: 'bg-institutional-50', darkBg: 'dark:bg-institutional-900/20' },
    { label: 'Inasistentes', value: stats?.groupStats?.absent || '0', color: 'text-institutional-800', darkColor: 'dark:text-institutional-300', bg: 'bg-institutional-50', darkBg: 'dark:bg-institutional-900/20' },
  ];

  return (
    <div className="max-w-5xl mx-auto space-y-16 py-12 animate-in fade-in duration-700">
      <div className="text-center space-y-4">
        <h2 className="text-5xl font-black text-gray-900 dark:text-white uppercase tracking-tight">Panel Docente</h2>
        <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.2em]">Control de asistencia por grupo</p>
      </div>

      <div className="bg-white dark:bg-slate-900 p-16 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50 flex flex-col items-center gap-10 group hover:border-institutional-400 transition-all duration-500">
        <div className="p-8 bg-institutional-50 dark:bg-institutional-900/20 text-institutional-800 dark:text-institutional-400 rounded-3xl group-hover:scale-110 transition-transform">
          <Users2 size={64} strokeWidth={1} />
        </div>
        <div className="w-full max-w-md space-y-4">
          <label className="text-xs font-black text-gray-400 dark:text-slate-500 uppercase tracking-[0.2em] text-center block">Seleccionar Grupo de Monitoreo</label>
          <div className="relative">
            <select 
              value={selectedGroup}
              onChange={(e) => {
                setSelectedGroup(e.target.value);
                setShowGroupList(false);
              }}
              className="w-full p-6 text-2xl font-black border-2 border-gray-100 dark:border-slate-800 rounded-3xl focus:border-institutional-500 outline-none transition-all appearance-none text-center bg-gray-50 dark:bg-slate-800 dark:text-white"
            >
              <option value="">-- Elegir Grupo --</option>
              {Object.keys(stats?.studentsByGroup || {}).map((group) => (
                <option key={group} value={group}>{group}</option>
              ))}
            </select>
          </div>
        </div>
        <button 
          onClick={() => selectedGroup && setShowGroupList(true)}
          disabled={!selectedGroup}
          className="bg-institutional-900 hover:bg-institutional-800 dark:bg-institutional-700 dark:hover:bg-institutional-600 text-white px-12 py-6 rounded-2xl font-black uppercase tracking-widest transition-all shadow-xl shadow-institutional-900/20 dark:shadow-none hover:scale-105 disabled:opacity-50 disabled:hover:scale-100"
        >
          Asignar Grupo
        </button>
      </div>

      {showStudents && (
        <div className="bg-white dark:bg-slate-900 p-12 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50 animate-in slide-in-from-bottom-8 duration-500">
          <h3 className="text-2xl font-black text-gray-900 dark:text-white uppercase tracking-tight mb-8">Estudiantes del Grupo {selectedGroup}</h3>
          {(stats?.studentsByGroup?.[selectedGroup] || []).length > 0 ? (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {(stats?.studentsByGroup?.[selectedGroup] || []).map((student, i) => (
                <div key={i} className="p-6 bg-gray-50 dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-slate-700 flex items-center gap-4">
                  <div className="w-10 h-10 rounded-full bg-institutional-900 text-white flex items-center justify-center font-black text-xs">
                    {student.name.charAt(0)}
                  </div>
                  <span className="font-bold text-gray-800 dark:text-gray-200">{student.name}</span>
                </div>
              ))}
            </div>
          ) : (
            <div className="text-center py-10 text-sm text-gray-400">Sin registros</div>
          )}
        </div>
      )}

      <div className="grid grid-cols-2 md:grid-cols-4 gap-6">
        {groupStats.map((stat, index) => (
          <div key={index} className={cn("p-10 rounded-3xl border border-gray-50 dark:border-slate-800/50 text-center flex flex-col items-center transition-all shadow-soft dark:shadow-soft-dark hover:scale-105 bg-white dark:bg-slate-900", stat.darkBg)}>
            <span className="text-4xl font-black text-gray-900 dark:text-white mb-2">{stat.value}</span>
            <span className={cn("text-[10px] font-black uppercase tracking-[0.15em]", stat.color, stat.darkColor)}>{stat.label}</span>
          </div>
        ))}
      </div>
    </div>
  );
};

/**
 * PANTALLA: PORTERO / AUXILIAR
 * Bienvenida institucional y navegación directa.
 */
const StaffDashboard = ({ user, navigate, logout }) => {
  return (
    <div className="max-w-4xl mx-auto min-h-[75vh] flex flex-col items-center justify-center text-center space-y-16">
      <div className="space-y-6">
        <div className="inline-flex p-10 bg-institutional-50 dark:bg-institutional-900/20 text-institutional-900 dark:text-institutional-400 rounded-full mb-6 shadow-inner">
          <LogoNexo className="h-24" showText={false} />
        </div>
        <h2 className="text-6xl font-black text-gray-900 dark:text-white tracking-tighter uppercase">¡Bienvenido!</h2>
        <div className="flex items-center justify-center gap-2">
          <div className="w-8 h-1 bg-institutional-400 rounded-full"></div>
          <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.3em]">Gestión Institucional NEXO</p>
          <div className="w-8 h-1 bg-institutional-400 rounded-full"></div>
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-10 w-full">
        <button 
          onClick={() => navigate('/operacion')}
          className="group bg-white dark:bg-slate-900 p-16 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800 hover:border-institutional-400 transition-all duration-500 flex flex-col items-center gap-6"
        >
          <div className="p-6 bg-institutional-50 dark:bg-institutional-900/20 text-institutional-700 dark:text-institutional-400 rounded-3xl group-hover:scale-110 group-hover:rotate-6 transition-all">
            <Activity size={48} strokeWidth={1.5} />
          </div>
          <span className="text-xl font-black text-gray-900 dark:text-white uppercase tracking-widest">Panel de Operación</span>
        </button>

        <button 
          onClick={() => navigate('/notificaciones')}
          className="group bg-white dark:bg-slate-900 p-16 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800 hover:border-institutional-400 transition-all duration-500 flex flex-col items-center gap-6"
        >
          <div className="p-6 bg-institutional-50 dark:bg-institutional-900/20 text-institutional-700 dark:text-institutional-400 rounded-3xl group-hover:scale-110 group-hover:-rotate-6 transition-all">
            <Bell size={48} strokeWidth={1.5} />
          </div>
          <span className="text-xl font-black text-gray-900 dark:text-white uppercase tracking-widest">Notificaciones</span>
        </button>
      </div>

      <button 
        onClick={logout}
        className="text-red-500 dark:text-red-400 font-black text-xs uppercase tracking-[0.3em] flex items-center gap-3 hover:opacity-70 transition-all"
      >
        <LogOut size={20} />
        [ Finalizar Sesión ]
      </button>
    </div>
  );
};

export default Dashboard;
