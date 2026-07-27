/**
 * SCR-CON-01 Consultation
 * Catálogo de módulos de consulta filtrado por rol. Muestra análisis de riesgo,
 * datos dinámicos por módulo y abre ConsultationDrawer para detalles.
 */
import { useState, useEffect } from 'react';
import { useSearchParams, Navigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { behaviorApi } from '../api/behavior';
import { consultationsApi } from '../api/consultations';
import { studentsApi } from '../api/students';
import { Search, ChevronRight, BookOpen, Activity, Database, Users, UserCheck, MessageSquare, ShieldAlert, FileText } from 'lucide-react';
import { ROLES } from '../config/roles';
import { ConsultationDrawer } from './ConsultationDrawer';
import { PageHeader } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Card } from '../components/ui/Card';
import { humanizeError } from '../utils/messages';

const TEACHER_MODULES = ['Llegadas Tarde', 'Inasistencias', 'Estudiantes Ausentes', 'Estudiantes fuera del salón', 'Estudiantes con Permiso', 'Citaciones'];

const MODULE_SLUGS = {
  'Llegadas Tarde': 'late_arrivals',
  'Inasistencias': 'absences',
  'Estudiantes Ausentes': 'absences',
  'Estudiantes fuera del salón': 'incidents',
  'Estudiantes con Permiso': 'active_permissions',
  'Citaciones': 'sent_messages',
  'Análisis de Riesgo': null,
  'Seguimientos completados': 'student_tracking_completed',
  'Spam Biométrico': 'biometric_spam',
  'Vulneraciones': 'incidents',
  'Alertas': 'incidents',
  'Seguimiento Estudiantil': 'student_tracking_active',
  'Permisos Emitidos': 'issued_permissions',
  'Salidas del colegio permitidas': 'school_exits',
  'Salidas Pedagógicas': 'pedagogical_trips',
  'Métricas Globales': 'institutional_metrics',
  'Asistencia Institucional': 'attendance_history',
  'Estadísticas Históricas': 'attendance_history',
  'Indicadores Críticos': 'incidents',
  'TODOS los Consolidados': 'reports',
  'Históricos Completos': 'attendance_history',
  'Exportaciones Institucionales': 'reports',
  'Estudiantes': 'all_students',
  'Grupos': 'all_groups',
  'Acudientes': 'all_guardians',
  'Matrículas': 'all_students',
  'Cambios Registro': 'audit_logs',
  'Profesores': 'all_teachers',
  'Auxiliares': 'staff_auxiliary',
  'Portería': 'staff_security',
  'Personal Institucional': 'staff',
  'Mensajes Enviados': 'sent_messages',
  'Reportes': 'reports',
  'Auditoría Local': 'audit_logs',
  'Permisos Activos Hoy': 'active_permissions',
};

const localDateStr = (date = new Date()) => {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

const Consultation = () => {
  const { user } = useAuth();
  const [searchParams] = useSearchParams();
  const [searchTerm, setSearchTerm] = useState('');
  const [activeItem, setActiveItem] = useState(null);
  const [riskStudents, setRiskStudents] = useState([]);
  const [dynamicData, setDynamicData] = useState([]);
  const [dynamicColumns, setDynamicColumns] = useState({});
  const [loadingData, setLoadingData] = useState(false);

  const [groups, setGroups] = useState([]);
  const [selectedGroup, setSelectedGroup] = useState('');
  const [fromDate, setFromDate] = useState(() => { const d = new Date(); d.setDate(d.getDate() - 7); return localDateStr(d); });
  const [toDate, setToDate] = useState(() => localDateStr());
  const [hasQueried, setHasQueried] = useState(false);
  const [queryError, setQueryError] = useState(null);
  const [selectedStudent, setSelectedStudent] = useState('');

  useEffect(() => {
    const isTeacherRole = user?.role === ROLES.DOCENTE || user?.role === ROLES.PSICORIENTADOR;
    studentsApi.getGroups(isTeacherRole)
      .then((data) => setGroups(Array.isArray(data) ? data : []))
      .catch((err) => console.error('Error loading groups', err));
  }, [user?.role]);

  useEffect(() => {
    const mod = searchParams.get('mod');
    if (mod) setActiveItem(mod);
  }, [searchParams]);

  const isTeacherModule = activeItem && TEACHER_MODULES.includes(activeItem);

  const executeQuery = async () => {
    if (!activeItem) return;
    setLoadingData(true);
    setHasQueried(true);
    setQueryError(null);
    const moduleSlug = MODULE_SLUGS[activeItem];
    if (!moduleSlug) {
      setQueryError('Módulo no soportado');
      setDynamicData([]);
      setDynamicColumns({});
      setLoadingData(false);
      return;
    }
    try {
      const res = await consultationsApi.queryModule(moduleSlug, selectedGroup, fromDate, toDate, selectedStudent);
      setDynamicData(res.data || []);
      setDynamicColumns(res.columns || {});
    } catch (err) {
      setQueryError(humanizeError(err, 'Error de red al consultar'));
      setDynamicData([]);
      setDynamicColumns({});
    } finally {
      setLoadingData(false);
    }
  };

  useEffect(() => {
    if (!activeItem) return;
    if (isTeacherModule) {
      setHasQueried(false);
      setQueryError(null);
      setDynamicData([]);
      setDynamicColumns({});
      setSelectedStudent('');
      setLoadingData(false);
      return;
    }
    const abortController = new AbortController();
    setLoadingData(true);

    const moduleSlug = activeItem === 'Análisis de Riesgo' ? null : MODULE_SLUGS[activeItem];
    if (activeItem !== 'Análisis de Riesgo' && !moduleSlug) {
      if (!abortController.signal.aborted) {
        setQueryError('Módulo no soportado');
        setLoadingData(false);
      }
      return () => abortController.abort();
    }

    if (activeItem === 'Análisis de Riesgo') {
      behaviorApi.getRiskAnalysis('', '', abortController.signal)
        .then((res) => {
          if (!abortController.signal.aborted) {
            setRiskStudents(res.students || res.data || []);
            setDynamicData(res.students || res.data || []);
            setDynamicColumns(res.columns || {});
          }
        })
        .catch((err) => { if (!abortController.signal.aborted) setQueryError(humanizeError(err, 'Error de red')); })
        .finally(() => { if (!abortController.signal.aborted) setLoadingData(false); });
    } else {
      consultationsApi.queryModule(moduleSlug, '', '', '', '', abortController.signal)
        .then((res) => {
          if (!abortController.signal.aborted) { setDynamicData(res.data || []); setDynamicColumns(res.columns || {}); }
        })
        .catch((err) => {
          if (!abortController.signal.aborted) {
            setQueryError(humanizeError(err, 'Error de red al consultar'));
            setDynamicData([]);
            setDynamicColumns({});
          }
        })
        .finally(() => { if (!abortController.signal.aborted) setLoadingData(false); });
    }
    return () => abortController.abort();
  }, [activeItem, isTeacherModule]);

  const allowedForConsulta = [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA, ROLES.DOCENTE, ROLES.PSICORIENTADOR];
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

  const openModule = (item) => {
    setActiveItem(item);
    setDynamicData([]);
    setDynamicColumns({});
    setHasQueried(false);
    setQueryError(null);
  };

  return (
    <div className="space-y-6">
      {!activeItem ? (
        <>
          <PageHeader
            eyebrow="Consulta institucional"
            title="Panel de consulta"
            subtitle="Acceso rápido a información por módulo"
          />
          <Input
            placeholder="Filtrar módulos y submódulos…"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />}
          />
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {filtered.map((mod, idx) => (
              <Card key={idx} className="p-0 overflow-hidden">
                <div className="flex items-center gap-3 border-b border-[var(--nx-border)] px-5 py-3.5">
                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-control bg-[color-mix(in_oklch,var(--nx-accent)_10%,transparent)] text-[var(--nx-accent)]">
                    <mod.icon size={16} strokeWidth={2} />
                  </div>
                  <p className="text-label uppercase text-[var(--nx-text)]">{mod.title}</p>
                </div>
                <div>
                  {mod.items.map((item, i) => (
                    <button
                      key={i}
                      onClick={() => openModule(item)}
                      className="group flex w-full items-center justify-between border-b border-[var(--nx-border)] px-5 py-3 text-left text-body text-[var(--nx-text)] transition-colors last:border-0 hover:bg-[var(--nx-surface-subtle)]"
                    >
                      <span>{item}</span>
                      <ChevronRight size={14} className="text-[var(--nx-text-muted)] group-hover:text-[var(--nx-accent)]" />
                    </button>
                  ))}
                </div>
              </Card>
            ))}
          </div>
        </>
      ) : (
        <>
          <div className="flex items-center gap-3">
            <button onClick={() => setActiveItem(null)} className="text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]">← Volver</button>
            <PageHeader
              eyebrow="Consulta"
              title={activeItem}
              subtitle={isTeacherModule ? 'Configura filtros y consulta' : 'Resultados del módulo'}
            />
          </div>
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
        </>
      )}
    </div>
  );
};

export default Consultation;
