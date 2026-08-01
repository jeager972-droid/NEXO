/**
 * SCR-CON-01 Consultation
 * Catálogo de módulos de consulta filtrado por rol. Navegación de 3 niveles:
 * 1. Grid de módulos (ej: Mis Clases)  2. Grid de submódulos  3. ConsultationDrawer
 */
import { useState, useEffect } from 'react';
import { useSearchParams, Navigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { behaviorApi } from '../api/behavior';
import { consultationsApi } from '../api/consultations';
import { studentsApi } from '../api/students';
import { Search, ChevronRight, ChevronLeft, BookOpen, Activity, Database, Users, UserCheck, MessageSquare, ShieldAlert, FileText, Clock, UserX, UserMinus, CalendarDays, Send, ShieldCheck, AlertTriangle, BarChart2, FileBarChart, GraduationCap, ContactRound, ClipboardList, Mail, History, DoorOpen } from 'lucide-react';
import { ROLES } from '../config/roles';
import { ConsultationDrawer } from './ConsultationDrawer';
import { Input } from '../components/ui/Input';
import { Surface } from '../components/ui/Surface';
import { Button } from '../components/ui/Button';
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

const TONE_STYLES = {
  accent:  { bg: 'bg-[var(--nx-surface-accent)]', icon: 'bg-[var(--nx-icon-bg-accent)] text-[color-mix(in_oklch,var(--nx-accent)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-border-accent)]' },
  success: { bg: 'bg-[var(--nx-surface-success)]', icon: 'bg-[var(--nx-icon-bg-success)] text-[color-mix(in_oklch,var(--nx-success)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-border-success)]' },
  warning: { bg: 'bg-[var(--nx-surface-warning)]', icon: 'bg-[var(--nx-icon-bg-warning)] text-[color-mix(in_oklch,var(--nx-warning)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-border-warning)]' },
  danger:  { bg: 'bg-[var(--nx-surface-danger)]', icon: 'bg-[var(--nx-icon-bg-danger)] text-[color-mix(in_oklch,var(--nx-danger)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-border-danger)]' },
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
  const [activeModule, setActiveModule] = useState(null);
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
    setHasQueried(true);

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

  // ── Definición de módulos por rol con tonos de color e iconos de submódulos ──
  const rbacModules = {
    [ROLES.DOCENTE]: [
      {
        title: 'Mis Clases',
        icon: BookOpen,
        tone: 'accent',
        items: [
          { label: 'Llegadas Tarde', icon: Clock },
          { label: 'Inasistencias', icon: UserX },
          { label: 'Estudiantes Ausentes', icon: UserMinus },
          { label: 'Estudiantes fuera del salón', icon: DoorOpen },
          { label: 'Estudiantes con Permiso', icon: ShieldCheck },
          { label: 'Citaciones', icon: Send },
        ]
      }
    ],
    [ROLES.PSICORIENTADOR]: [
      {
        title: 'Análisis de Riesgo',
        icon: ShieldAlert,
        tone: 'danger',
        items: [
          { label: 'Análisis de Riesgo', icon: AlertTriangle },
        ]
      },
      {
        title: 'Seguimientos',
        icon: FileText,
        tone: 'accent',
        items: [
          { label: 'Seguimientos completados', icon: ClipboardList },
        ]
      }
    ],
    [ROLES.COORDINADOR]: [
      {
        title: 'Incidentes',
        icon: ShieldAlert,
        tone: 'danger',
        items: [
          { label: 'Spam Biométrico', icon: AlertTriangle },
          { label: 'Vulneraciones', icon: ShieldAlert },
          { label: 'Alertas', icon: AlertTriangle },
          { label: 'Seguimiento Estudiantil', icon: ClipboardList },
        ]
      },
      {
        title: 'Permisos',
        icon: Activity,
        tone: 'success',
        items: [
          { label: 'Permisos Emitidos', icon: ShieldCheck },
          { label: 'Salidas del colegio permitidas', icon: DoorOpen },
          { label: 'Salidas Pedagógicas', icon: CalendarDays },
        ]
      }
    ],
    [ROLES.RECTOR]: [
      {
        title: 'Ejecutivo Institucional',
        icon: Activity,
        tone: 'accent',
        items: [
          { label: 'Métricas Globales', icon: BarChart2 },
          { label: 'Asistencia Institucional', icon: Activity },
          { label: 'Estadísticas Históricas', icon: FileBarChart },
          { label: 'Indicadores Críticos', icon: AlertTriangle },
        ]
      },
      {
        title: 'Reportes Consolidados',
        icon: Database,
        tone: 'warning',
        items: [
          { label: 'TODOS los Consolidados', icon: FileBarChart },
          { label: 'Históricos Completos', icon: History },
          { label: 'Exportaciones Institucionales', icon: FileText },
        ]
      }
    ],
    [ROLES.SECRETARIA]: [
      {
        title: 'Gestión Estudiantil',
        icon: Users,
        tone: 'accent',
        items: [
          { label: 'Estudiantes', icon: GraduationCap },
          { label: 'Grupos', icon: Users },
          { label: 'Acudientes', icon: ContactRound },
          { label: 'Matrículas', icon: ClipboardList },
          { label: 'Cambios Registro', icon: FileText },
        ]
      },
      {
        title: 'Personal',
        icon: UserCheck,
        tone: 'success',
        items: [
          { label: 'Profesores', icon: GraduationCap },
          { label: 'Auxiliares', icon: UserCheck },
          { label: 'Portería', icon: DoorOpen },
          { label: 'Personal Institucional', icon: Users },
        ]
      },
      {
        title: 'Mensajería',
        icon: MessageSquare,
        tone: 'accent',
        items: [
          { label: 'Mensajes Enviados', icon: Mail },
        ]
      },
      {
        title: 'Históricos',
        icon: Database,
        tone: 'warning',
        items: [
          { label: 'Reportes', icon: FileBarChart },
          { label: 'Auditoría Local', icon: History },
        ]
      },
      {
        title: 'Control de Acceso',
        icon: Activity,
        tone: 'warning',
        items: [
          { label: 'Permisos Activos Hoy', icon: ShieldCheck },
        ]
      }
    ]
  };

  const modules = rbacModules[user?.role] || [];
  const currentModule = modules.find(m => m.title === activeModule);

  const openModule = (modTitle) => {
    setActiveModule(modTitle);
    setSearchTerm('');
  };

  const openSubmodule = (item) => {
    setActiveItem(item);
    setDynamicData([]);
    setDynamicColumns({});
    setHasQueried(false);
    setQueryError(null);
  };

  const goBackToModules = () => {
    setActiveModule(null);
    setActiveItem(null);
  };

  const goBackToSubmodules = () => {
    setActiveItem(null);
  };

  // ── Nivel 3: ConsultationDrawer (submódulo seleccionado) ──
  if (activeItem) {
    return (
      <div className="space-y-6">
        <div className="space-y-3">
          <Button variant="secondary" size="sm" onClick={goBackToSubmodules} leftIcon={<ChevronLeft size={16} />}>
            Volver
          </Button>
          <div className="flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
            <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
            <h2 className="text-h2 text-[var(--nx-text)]">{activeItem}</h2>
          </div>
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
          onClose={goBackToSubmodules}
          error={queryError}
        />
      </div>
    );
  }

  // ── Nivel 2: Grid de submódulos ──
  if (activeModule && currentModule) {
    const ts = TONE_STYLES[currentModule.tone] || TONE_STYLES.accent;
    const filteredItems = currentModule.items.filter(it =>
      it.label.toLowerCase().includes(searchTerm.toLowerCase())
    );
    return (
      <div className="space-y-6">
        <div className="space-y-3">
          <Button variant="secondary" size="sm" onClick={goBackToModules} leftIcon={<ChevronLeft size={16} />}>
            Volver
          </Button>
          <div className="flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
            <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
            <h2 className="text-h2 text-[var(--nx-text)]">{currentModule.title}</h2>
          </div>
        </div>
        <Input
          placeholder="Filtrar submódulos…"
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />}
        />
        {filteredItems.length === 0 ? (
          <Surface className="p-6">
            <p className="text-body text-[var(--nx-text-muted)] text-center">Sin coincidencias.</p>
          </Surface>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {filteredItems.map((sub) => {
              const SubIcon = sub.icon;
              return (
                <button
                  key={sub.label}
                  onClick={() => openSubmodule(sub.label)}
                  className={`flex flex-col rounded-panel border p-5 text-left transition-all duration-fast ${ts.bg} ${ts.border} hover:shadow-medium`}
                >
                  <div className="flex items-start justify-between">
                    <div className={`flex h-10 w-10 items-center justify-center rounded-control ${ts.icon}`}>
                      <SubIcon size={20} />
                    </div>
                    <ChevronRight size={18} className="text-[var(--nx-text-muted)]" />
                  </div>
                  <p className="mt-4 text-h3 text-[var(--nx-text)]">{sub.label}</p>
                </button>
              );
            })}
          </div>
        )}
      </div>
    );
  }

  // ── Nivel 1: Grid de módulos ──
  return (
    <div className="space-y-6">
      <Input
        placeholder="Filtrar módulos…"
        value={searchTerm}
        onChange={(e) => setSearchTerm(e.target.value)}
        leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />}
      />
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {modules
          .filter(m => m.title.toLowerCase().includes(searchTerm.toLowerCase()))
          .map((mod) => {
            const ts = TONE_STYLES[mod.tone] || TONE_STYLES.accent;
            const ModIcon = mod.icon;
            return (
              <button
                key={mod.title}
                onClick={() => openModule(mod.title)}
                className={`flex flex-col rounded-panel border p-5 text-left transition-all duration-fast ${ts.bg} ${ts.border} hover:shadow-medium`}
              >
                <div className="flex items-start justify-between">
                  <div className={`flex h-10 w-10 items-center justify-center rounded-control ${ts.icon}`}>
                    <ModIcon size={20} />
                  </div>
                  <ChevronRight size={18} className="text-[var(--nx-text-muted)]" />
                </div>
                <p className="mt-4 text-h3 text-[var(--nx-text)]">{mod.title}</p>
                <p className="mt-1 text-caption text-[var(--nx-text-muted)]">
                  {mod.items.length} {mod.items.length === 1 ? 'submódulo' : 'submódulos'}
                </p>
              </button>
            );
          })}
      </div>
    </div>
  );
};

export default Consultation;
