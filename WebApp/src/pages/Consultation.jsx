import { useState, useEffect } from 'react';
import { useAuth } from '../hooks/useAuth';
import {
  Search, Users, ShieldAlert, MessageSquare,
  Activity, ChevronRight, Database, BookOpen,
  History, UserCheck, X,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
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
  const filtered = modules.map(m => ({
    ...m,
    items: m.items.filter(it => it.toLowerCase().includes(searchTerm.toLowerCase())),
  })).filter(m => m.items.length > 0 || !searchTerm);

  return (
    <div className="space-y-5">
      {/* Header */}
      <div>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none' }}>Panel de Consulta</p>
        <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', marginTop: '2px' }} className="dark:text-slate-200">Acceso rápido a información por módulo</p>
      </div>

      {/* Search */}
      <div className="relative">
        <Search size={14} strokeWidth={2} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none" />
        <input type="text" placeholder="Filtrar módulos y submódulos…" value={searchTerm}
          onChange={e => setSearchTerm(e.target.value)}
          className="w-full pl-9 pr-4 py-2.5 outline-none dark:bg-slate-900 dark:text-white"
          style={{ border: '1.5px solid #E2E8F0', backgroundColor: '#F8FAFC', fontSize: '13px', fontWeight: 500, color: '#0F172A' }}
          onFocus={e => { e.target.style.borderColor = '#003366'; }}
          onBlur={e => { e.target.style.borderColor = '#E2E8F0'; }} />
      </div>

      {/* Module grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {filtered.map((mod, idx) => (
          <motion.div key={idx}
            initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.2, delay: idx * 0.04 }}
            className="bg-white dark:bg-slate-900"
            style={{ border: '1.5px solid #E2E8F0' }}
          >
            {/* Module header */}
            <div className="flex items-center gap-3 px-5 py-3.5" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
              <div className="flex items-center justify-center w-8 h-8 shrink-0"
                style={{ backgroundColor: 'rgba(0,51,102,0.07)', color: '#003366' }}>
                <mod.icon size={16} strokeWidth={2} />
              </div>
              <p className="text-xs font-black uppercase dark:text-white" style={{ letterSpacing: '0.1em', color: '#1E293B' }}>
                {mod.title}
              </p>
            </div>
            {/* Items */}
            <div>
              {mod.items.map((item, i) => (
                <button key={i} onClick={() => setActiveItem(item)}
                  className="group flex items-center justify-between w-full px-5 py-3 text-left bg-white dark:bg-slate-900 hover:bg-gov-900 dark:hover:bg-gov-900 transition-colors duration-150"
                  style={{ borderBottom: i < mod.items.length - 1 ? '1px solid #F8FAFC' : 'none' }}>
                  <span className="text-xs font-semibold text-slate-600 dark:text-slate-400 group-hover:text-white transition-colors"
                        style={{ letterSpacing: '0.05em' }}>{item}</span>
                  <ChevronRight size={12} strokeWidth={2} className="text-slate-300 group-hover:text-white/60 transition-colors shrink-0" />
                </button>
              ))}
            </div>
          </motion.div>
        ))}
      </div>

      {/* Consultation Drawer */}
      <AnimatePresence>
        {activeItem && (
          <ConsultationDrawer
            item={activeItem}
            riskStudents={riskStudents}
            onClose={() => setActiveItem(null)}
          />
        )}
      </AnimatePresence>
    </div>
  );
};

// ── Consultation Drawer ───────────────────────────────────────────────────────

const RiskBadge = ({ level }) => {
  const [color, border, bg] = level === 'CRITICAL'
    ? ['#DC2626', '#DC2626', 'rgba(220,38,38,0.07)']
    : ['#D97706', '#D97706', 'rgba(217,119,6,0.07)'];
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', padding: '2px 8px',
      borderRadius: '999px', border: `1px solid ${border}`, backgroundColor: bg, color,
      fontSize: '9px', fontWeight: 700, letterSpacing: '0.15em', textTransform: 'uppercase' }}>
      {level}
    </span>
  );
};

const ConsultationDrawer = ({ item, riskStudents, onClose }) => (
  <>
    <motion.div key="ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
      transition={{ duration: 0.2 }} className="fixed inset-0 z-40"
      style={{ backgroundColor: 'rgba(2,6,23,0.5)', backdropFilter: 'blur(2px)' }}
      onClick={onClose} />
    <motion.div key="dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
      transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
      className="fixed right-0 inset-y-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
      style={{ maxWidth: '520px', borderLeft: '1.5px solid #E2E8F0' }}
    >
      {/* Header */}
      <div className="shrink-0 flex items-center justify-between px-6 py-4" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
        <div className="flex items-center gap-3">
          <div className="flex items-center justify-center w-9 h-9" style={{ backgroundColor: 'rgba(0,51,102,0.08)' }}>
            <Search size={16} strokeWidth={2} style={{ color: '#003366' }} />
          </div>
          <div>
            <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: '#1E293B' }}>{item}</p>
            <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>Consulta de Datos Institucionales</p>
          </div>
        </div>
        <button onClick={onClose} className="p-1.5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
          <X size={18} strokeWidth={2} />
        </button>
      </div>

      {/* Body */}
      <div className="flex-1 overflow-y-auto p-6">
        {item === 'Análisis de Riesgo' && riskStudents.length > 0 ? (
          <div className="overflow-x-auto" style={{ border: '1.5px solid #E2E8F0' }}>
            <table className="w-full min-w-[440px]">
              <thead>
                <tr style={{ backgroundColor: '#F8FAFC', borderBottom: '1.5px solid #E2E8F0' }}>
                  {['Estudiante', 'Grupo', 'Score', 'Nivel'].map(h => (
                    <th key={h} className="px-4 py-3 text-left"
                      style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="bg-white dark:bg-slate-900">
                {riskStudents.map((s, i) => (
                  <tr key={s.student_id} className="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
                    style={{ borderBottom: i < riskStudents.length - 1 ? '1px solid #F1F5F9' : 'none' }}>
                    <td className="px-4 py-4">
                      <div className="flex items-center gap-2">
                        <div className="w-7 h-7 shrink-0 flex items-center justify-center text-xs font-black text-white"
                             style={{ backgroundColor: '#003366' }}>
                          {s.first_name.charAt(0)}
                        </div>
                        <span className="text-sm font-bold text-slate-800 dark:text-slate-200">{s.first_name} {s.last_name}</span>
                      </div>
                    </td>
                    <td className="px-4 py-4"><span className="text-sm font-semibold text-slate-500 dark:text-slate-400">{s.group_name}</span></td>
                    <td className="px-4 py-4">
                      <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '13px', fontWeight: 700, color: s.risk_score >= 85 ? '#DC2626' : '#D97706' }}>
                        {s.risk_score}
                      </span>
                    </td>
                    <td className="px-4 py-4"><RiskBadge level={s.risk_level} /></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="flex flex-col items-center justify-center h-full py-20 gap-4">
            <Database size={32} strokeWidth={1} className="text-slate-200 dark:text-slate-700" />
            <div className="text-center space-y-1">
              <p style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.15em', color: '#CBD5E1', textTransform: 'uppercase' }}>Sin datos disponibles</p>
              <p style={{ fontSize: '11px', color: '#CBD5E1' }} className="max-w-xs">Este submódulo estará disponible cuando exista integración con el endpoint.</p>
            </div>
          </div>
        )}
      </div>
    </motion.div>
  </>
);

export default Consultation;
