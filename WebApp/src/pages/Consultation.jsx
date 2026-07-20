import { useState, useEffect } from 'react';
import { useSearchParams, Navigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { behaviorApi } from '../api/behavior';
import { consultationsApi } from '../api/consultations';
import { studentsApi } from '../api/students';
import {
  Search, Users, ShieldAlert, MessageSquare,
  Activity, ChevronRight, BookOpen, Database,
  UserCheck, FileText
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { ROLES } from '../config/roles';
import { ConsultationDrawer } from './ConsultationDrawer';

const TEACHER_MODULES = ['Llegadas Tarde', 'Inasistencias', 'Estudiantes Ausentes', 'Estudiantes fuera del salón', 'Estudiantes con Permiso', 'Citaciones'];

const Consultation = () => {
  const { user } = useAuth();
  const [searchTerm, setSearchTerm] = useState('');
  const [activeItem, setActiveItem] = useState(null);
  const [riskStudents, setRiskStudents] = useState([]);
  const [dynamicData, setDynamicData] = useState([]);
  const [dynamicColumns, setDynamicColumns] = useState({});
  const [loadingData, setLoadingData] = useState(false);
  const [searchParams, setSearchParams] = useSearchParams();

  // Query params for teacher modules
  const [groups, setGroups] = useState([]);
  const [selectedGroup, setSelectedGroup] = useState('');
  // Helper: fecha local YYYY-MM-DD (evita desfase UTC de toISOString)
  const localDateStr = (date = new Date()) => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  };

  const [fromDate, setFromDate] = useState(() => {
    const d = new Date(); d.setDate(d.getDate() - 7);
    return localDateStr(d);
  });
  const [toDate, setToDate] = useState(() => localDateStr());
  const [hasQueried, setHasQueried] = useState(false);
  const [queryError, setQueryError] = useState(null);
  const [selectedStudent, setSelectedStudent] = useState('');

  // BUG-08 FIX: separar en dos effects — grupos solo se recarga cuando cambia el rol,
  // no en cada cambio de searchParams
  useEffect(() => {
    const isTeacherRole = user?.role === 'DOCENTE' || user?.role === 'PSICORIENTADOR';
    studentsApi.getGroups(isTeacherRole)
      .then(data => setGroups(Array.isArray(data) ? data : []))
      .catch(err => console.error('Error loading groups', err));
  }, [user?.role]);

  const [initialModHandled, setInitialModHandled] = useState(false);

  useEffect(() => {
    if (!initialModHandled) {
      const mod = searchParams.get('mod');
      if (mod) {
        setActiveItem(mod);
      }
    }
  }, [initialModHandled, searchParams]);

  const isTeacherModule = activeItem && TEACHER_MODULES.includes(activeItem);

  const executeQuery = async () => {
    if (!activeItem) return;
    setLoadingData(true);
    setHasQueried(true);
    setQueryError(null);
    try {
      const res = await consultationsApi.queryModule(activeItem, selectedGroup, fromDate, toDate, selectedStudent);
      setDynamicData(res.data || []);
      setDynamicColumns(res.columns || {});
    } catch (err) {
      console.error('Error fetching module data', err);
      setQueryError(err?.response?.data?.message || err.message || 'Error de red al consultar');
      setDynamicData([]);
      setDynamicColumns({});
    } finally {
      setLoadingData(false);
    }
  };

  useEffect(() => {
    if (!activeItem) return;

    // Teacher modules: require manual query via form
    if (isTeacherModule) {
      setHasQueried(false);
      setQueryError(null);
      setDynamicData([]);
      setDynamicColumns({});
      setSelectedStudent('');
      setLoadingData(false);
      return;
    }

    // Non-teacher modules: auto-fetch
    setLoadingData(true);
    const abortController = new AbortController();

    if (activeItem === 'Análisis de Riesgo') {
      behaviorApi.getRiskAnalysis()
        .then(res => {
          if (!abortController.signal.aborted) {
            if (res.status === 'ok') setRiskStudents(res.data || []);
            else setQueryError(res.message || 'Error al cargar análisis de riesgo');
          }
        })
        .catch(err => {
          if (!abortController.signal.aborted) {
            console.error('Error fetching risk analysis', err);
            setQueryError(err?.response?.data?.message || err.message || 'Error de red');
          }
        })
        .finally(() => {
          if (!abortController.signal.aborted) setLoadingData(false);
        });
    } else {
      consultationsApi.queryModule(activeItem, '', '', '', '', abortController.signal)
        .then(res => {
          if (!abortController.signal.aborted) {
            setDynamicData(res.data || []);
            setDynamicColumns(res.columns || {});
          }
        })
        .catch(err => {
          if (!abortController.signal.aborted) {
            console.error('Error fetching module data', err);
            setQueryError(err?.response?.data?.message || err.message || 'Error de red al consultar');
            setDynamicData([]);
            setDynamicColumns({});
          }
        })
        .finally(() => {
          if (!abortController.signal.aborted) setLoadingData(false);
        });
    }
    return () => abortController.abort();
  }, [activeItem]);

  const allowedForConsulta = [ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR];
  if (!allowedForConsulta.includes(user?.role)) {
    return <Navigate to="/" replace />;
  }

  // Definición de módulos por rol
  const rbacModules = {
    [ROLES.DOCENTE]: [
      {
        title: 'Mis Clases',
        icon: BookOpen,
        items: ['Llegadas Tarde', 'Inasistencias', 'Estudiantes Ausentes', 'Estudiantes fuera del salón', 'Estudiantes con Permiso', 'Citaciones']
      }
    ],
    [ROLES.PSICORIENTADOR]: [
      {
        title: 'Análisis de Riesgo',
        icon: ShieldAlert,
        items: ['Análisis de Riesgo']
      },
      {
        title: 'Seguimientos',
        icon: FileText,
        items: ['Seguimientos completados']
      }
    ],
    [ROLES.COORDINADOR]: [
      { 
        title: 'Incidentes', 
        icon: ShieldAlert, 
        items: ['Spam Biométrico', 'Vulneraciones', 'Alertas', 'Seguimiento Estudiantil'] 
      },
      { 
        title: 'Permisos', 
        icon: Activity, 
        items: ['Permisos Emitidos', 'Salidas del colegio permitidas', 'Salidas Pedagógicas'] 
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
      },
      {
        title: 'Control de Acceso',
        icon: Activity,
        items: ['Permisos Activos Hoy']
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
                <button key={i} onClick={() => {
                  setActiveItem(item);
                  setDynamicData([]);
                  setDynamicColumns({});
                  setHasQueried(false);
                  setQueryError(null);
                }}
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
            dynamicData={dynamicData}
            dynamicColumns={dynamicColumns}
            loadingData={loadingData}
            isTeacherModule={isTeacherModule}
            hasQueried={hasQueried}
            groups={groups}
            selectedGroup={selectedGroup}
            setSelectedGroup={setSelectedGroup}
            selectedStudent={selectedStudent}
            setSelectedStudent={setSelectedStudent}
            fromDate={fromDate}
            setFromDate={setFromDate}
            toDate={toDate}
            setToDate={setToDate}
            onQuery={executeQuery}
            onClose={() => setActiveItem(null)}
            error={queryError}
          />
        )}
      </AnimatePresence>
    </div>
  );
};

export default Consultation;
