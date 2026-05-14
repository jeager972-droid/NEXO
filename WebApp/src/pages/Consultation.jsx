import { useState, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import {
  Search, 
  Users, 
  ShieldAlert, 
  MessageSquare, 
  Activity, 
  ChevronRight,
  Database,
  BookOpen,
  History,
  UserCheck,
  X
} from 'lucide-react';
import { ROLES } from '../config/roles';

const Consultation = () => {
  const { user } = useAuth();
  const [searchTerm, setSearchTerm] = useState('');
  const [activeItem, setActiveItem] = useState(null);
  const [riskStudents, setRiskStudents] = useState([]);

  useEffect(() => {
    // Mock data para Análisis de Riesgo (simula respuesta del endpoint /behavior/risk)
    setRiskStudents([
      { student_id: 1, first_name: 'Juan', last_name: 'Pérez', group_name: '5A', risk_score: 85, risk_level: 'CRITICAL', late_count: 3, absence_count: 2 },
      { student_id: 2, first_name: 'María', last_name: 'García', group_name: '4B', risk_score: 72, risk_level: 'HIGH', late_count: 5, absence_count: 1 },
      { student_id: 3, first_name: 'Carlos', last_name: 'López', group_name: '6A', risk_score: 91, risk_level: 'CRITICAL', late_count: 2, absence_count: 4 },
      { student_id: 4, first_name: 'Ana', last_name: 'Martínez', group_name: '3B', risk_score: 65, risk_level: 'HIGH', late_count: 6, absence_count: 0 },
    ]);
  }, []);

  // Definición de módulos por rol
  const rbacModules = {
    [ROLES.DOCENTE]: [
      { 
        title: 'Mis Clases', 
        icon: BookOpen, 
        items: ['Estudiantes del Grupo', 'Llegadas Tarde', 'Inasistencias', 'Estudiantes Ausentes', 'Estudiantes fuera del salón', 'Estudiantes con Permiso'] 
      },
      { 
        title: 'Historial Estudiantil', 
        icon: History, 
        items: ['Historial Asistencia', 'Historial Tardanzas', 'Mis Permisos', 'Incidentes Disciplinarios'] 
      },
      { 
        title: 'Mensajería', 
        icon: MessageSquare, 
        items: ['Mensajes Enviados', 'Respuestas Acudientes', 'Citaciones', 'Mensajes Internos'] 
      }
    ],
    [ROLES.PSICORIENTADOR]: [
      {
        title: 'Análisis de Riesgo',
        icon: ShieldAlert,
        items: ['Análisis de Riesgo']
      },
      {
        title: 'Mis Clases',
        icon: BookOpen,
        items: ['Estudiantes del Grupo', 'Llegadas Tarde', 'Inasistencias', 'Estudiantes Ausentes', 'Estudiantes fuera del salón', 'Estudiantes con Permiso']
      },
      {
        title: 'Historial Estudiantil',
        icon: History,
        items: ['Historial Asistencia', 'Historial Tardanzas', 'Mis Permisos', 'Incidentes Disciplinarios']
      },
      {
        title: 'Mensajería',
        icon: MessageSquare,
        items: ['Mensajes Enviados', 'Respuestas Acudientes', 'Citaciones', 'Mensajes Internos']
      }
    ],
    [ROLES.COORDINADOR]: [
      { 
        title: 'Supervisión Académica', 
        icon: Users, 
        items: ['TODOS los grupos', 'TODOS los profesores', 'Asistencia General'] 
      },
      { 
        title: 'Incidentes', 
        icon: ShieldAlert, 
        items: ['Vulneraciones', 'Alertas'] 
      },
      { 
        title: 'Permisos', 
        icon: Activity, 
        items: ['Permisos Activos', 'Salidas Pedagógicas', 'Autorizaciones Emitidas'] 
      },
      { 
        title: 'Estadísticas', 
        icon: Activity, 
        items: ['Métricas Institucionales', 'Grupos Críticos', 'Estudiantes Críticos', 'Reportes Históricos'] 
      }
    ],
    [ROLES.RECTOR]: [
      { 
        title: 'Ejecutivo Institucional', 
        icon: Activity, 
        items: ['Métricas Globales', 'Asistencia Institucional', 'Estadísticas Históricas', 'Indicadores Críticos'] 
      },
      { 
        title: 'Reportes Consolidados', 
        icon: Database, 
        items: ['TODOS los Consolidados', 'Históricos Completos', 'Exportaciones Institucionales'] 
      }
    ],
    [ROLES.SECRETARIA]: [
      { 
        title: 'Gestión Estudiantil', 
        icon: Users, 
        items: ['Estudiantes', 'Grupos', 'Acudientes', 'Matrículas', 'Cambios Registro'] 
      },
      { 
        title: 'Personal', 
        icon: UserCheck, 
        items: ['Profesores', 'Auxiliares', 'Portería', 'Personal Institucional'] 
      },
      { 
        title: 'Mensajería', 
        icon: MessageSquare, 
        items: ['Mensajes Enviados'] 
      },
      { 
        title: 'Históricos', 
        icon: Database, 
        items: ['Reportes', 'Auditoría Local'] 
      }
    ]
  };

  const modules = rbacModules[user?.role] || [];

  return (
    <div className="space-y-12 py-8 animate-in fade-in duration-500">
      <div className="text-center space-y-4">
        <h2 className="text-5xl font-black text-gray-900 dark:text-white uppercase tracking-tight italic">Panel de Consulta</h2>
        <p className="text-gray-400 dark:text-slate-500 text-sm font-black uppercase tracking-[0.3em]">Acceso rápido a información por módulo</p>
      </div>

      <div className="bg-white dark:bg-slate-900 p-10 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50">
        <div className="relative max-w-2xl mx-auto">
          <Search className="absolute left-6 top-1/2 -translate-y-1/2 text-gray-400 dark:text-slate-600" size={28} />
          <input 
            type="text" 
            placeholder="Búsqueda rápida en módulos..."
            className="w-full pl-16 pr-8 py-6 bg-gray-50 dark:bg-slate-800 border border-gray-100 dark:border-slate-700 rounded-3xl focus:ring-4 focus:ring-institutional-500/10 focus:border-institutional-500 outline-none transition-all text-xl font-bold dark:text-white"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-10 max-w-7xl mx-auto">
        {modules.map((mod, index) => (
          <div key={index} className="bg-white dark:bg-slate-900 rounded-[3rem] shadow-soft dark:shadow-soft-dark border border-gray-50 dark:border-slate-800/50 p-10 hover:border-institutional-400 transition-all duration-500">
            <div className="flex items-center gap-6 mb-8">
              <div className="p-5 rounded-2xl bg-institutional-50 dark:bg-institutional-900/20 text-institutional-900 dark:text-institutional-400">
                <mod.icon size={32} />
              </div>
              <h3 className="text-2xl font-black text-gray-900 dark:text-white uppercase tracking-tighter">{mod.title}</h3>
            </div>
            
            <div className="grid grid-cols-1 gap-3">
              {mod.items.map((item, i) => (
                <button 
                  key={i}
                  onClick={() => setActiveItem(item)}
                  className="flex items-center justify-between w-full p-5 bg-gray-50 dark:bg-slate-800/50 rounded-2xl text-left hover:bg-institutional-900 hover:text-white transition-all group/btn"
                >
                  <span className="text-sm font-black uppercase tracking-widest dark:text-institutional-400 group-hover/btn:text-white transition-colors">{item}</span>
                  <ChevronRight size={20} className="text-gray-300 group-hover/btn:text-white" />
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>

      {/* MODAL DE CONSULTA */}
      {activeItem && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-950/80 backdrop-blur-md animate-in fade-in duration-300">
          <div className="bg-white dark:bg-slate-900 w-full max-w-2xl rounded-[3.5rem] shadow-2xl border border-gray-100 dark:border-slate-800 overflow-hidden">
            <div className="bg-institutional-900 px-10 py-8 text-white flex items-center justify-between">
              <div className="flex items-center gap-4">
                <div className="p-3 bg-white/10 rounded-2xl">
                  <Search size={24} />
                </div>
                <div>
                  <h3 className="font-black text-2xl uppercase tracking-tight">{activeItem}</h3>
                  <p className="text-[10px] font-black uppercase tracking-widest opacity-60">Consulta de Datos Institucionales</p>
                </div>
              </div>
              <button onClick={() => setActiveItem(null)} className="hover:bg-white/10 p-3 rounded-2xl transition-colors">
                <X size={28} />
              </button>
            </div>
            <div className="p-8 max-h-[70vh] overflow-y-auto">
              {activeItem === 'Análisis de Riesgo' ? (
                <div className="space-y-6">
                  {riskStudents.length > 0 ? (
                    <div className="overflow-x-auto">
                      <table className="w-full text-left">
                        <thead>
                          <tr className="border-b border-gray-100 dark:border-slate-700">
                            <th className="py-3 px-4 text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-slate-500">Estudiante</th>
                            <th className="py-3 px-4 text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-slate-500">Grupo</th>
                            <th className="py-3 px-4 text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-slate-500">Score</th>
                            <th className="py-3 px-4 text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-slate-500">Nivel</th>
                            <th className="py-3 px-4 text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-slate-500 text-right">Acción</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-50 dark:divide-slate-800">
                          {riskStudents.map((s) => (
                            <tr key={s.student_id} className="hover:bg-gray-50 dark:hover:bg-slate-800/50 transition-colors">
                              <td className="py-4 px-4">
                                <span className="font-bold text-gray-900 dark:text-white">{s.first_name} {s.last_name}</span>
                              </td>
                              <td className="py-4 px-4">
                                <span className="text-sm font-bold text-gray-500 dark:text-slate-400">{s.group_name}</span>
                              </td>
                              <td className="py-4 px-4">
                                <span className="text-sm font-black text-institutional-700 dark:text-institutional-400">{s.risk_score}</span>
                              </td>
                              <td className="py-4 px-4">
                                <span className={`inline-block px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider ${s.risk_level === 'CRITICAL' ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'}`}>
                                  {s.risk_level}
                                </span>
                              </td>
                              <td className="py-4 px-4 text-right">
                                <button className="text-[10px] font-black uppercase tracking-widest text-institutional-700 dark:text-institutional-400 hover:text-institutional-900 dark:hover:text-institutional-300 transition-colors">
                                  Ver detalles
                                </button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  ) : (
                    <div className="text-center py-10">
                      <p className="text-gray-400 dark:text-slate-500 font-black uppercase tracking-widest text-sm">No hay estudiantes en riesgo HIGH/CRITICAL</p>
                    </div>
                  )}
                  <button
                    onClick={() => setActiveItem(null)}
                    className="w-full bg-institutional-900 text-white py-6 rounded-[2rem] font-black uppercase tracking-widest shadow-xl shadow-institutional-900/20 transition-all hover:scale-105 active:scale-95"
                  >
                    Regresar al Panel
                  </button>
                </div>
              ) : (
                <div className="text-center space-y-8">
                  <div className="inline-flex p-10 bg-institutional-50 dark:bg-institutional-900/20 text-institutional-900 dark:text-institutional-400 rounded-full shadow-inner">
                    <Database size={80} strokeWidth={1} className="animate-pulse" />
                  </div>
                  <div className="space-y-3">
                    <p className="text-gray-900 dark:text-white text-xl font-black uppercase tracking-widest">Sin datos disponibles</p>
                    <p className="text-gray-400 dark:text-slate-500 text-sm font-bold leading-relaxed max-w-md mx-auto">
                      Este submódulo aún no tiene endpoint operativo. Se mostrará información real cuando esté conectado.
                    </p>
                  </div>
                  <button
                    onClick={() => setActiveItem(null)}
                    className="w-full bg-institutional-900 text-white py-6 rounded-[2rem] font-black uppercase tracking-widest shadow-xl shadow-institutional-900/20 transition-all hover:scale-105 active:scale-95"
                  >
                    Regresar al Panel
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default Consultation;
