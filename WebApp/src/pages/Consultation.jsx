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
import { auditApi } from '../api/audit';
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

const AUDIT_MODULES = {
  'Auditoría Global': (params) => auditApi.getGlobalLogs(params),
  'Integridad de Auditoría': () => auditApi.getIntegrity(),
  'Alertas SOS': (params) => auditApi.getSosAlerts(params),
  'SOS Resueltas': (params) => auditApi.getSosResolved(params),
  'Tiempo de Resolución SOS': (params) => auditApi.getSosResolutionTime(params),
  'Historial SOS': (params) => auditApi.getSosHistory(params),
  'Seguridad Global': (params) => auditApi.getSecurityGlobal(params),
  'Accesos al Sistema': (params) => auditApi.getSecurityAccesses(params),
  'Sesiones Activas': (params) => auditApi.getSecuritySessions(params),
  'Comandos del Sistema': (params) => auditApi.getSecurityCommands(params),
  'Actividad Administrativa': (params) => auditApi.getSecurityAdminActivity(params),
  'Intentos Fallidos': (params) => auditApi.getSecurityFailedAttempts(params),
  'Asistencia Consolidada': (params) => auditApi.getConsolidatedAttendance(params),
  'Disciplina Consolidada': (params) => auditApi.getConsolidatedDiscipline(params),
  'Permisos Consolidados': (params) => auditApi.getConsolidatedPermissions(params),
  'Mensajería Consolidada': (params) => auditApi.getConsolidatedMessaging(params),
  'Docentes Consolidado': (params) => auditApi.getConsolidatedTeacher(params),
  'Seguridad Consolidada': (params) => auditApi.getConsolidatedSecurity(params),
  'Métricas Institucionales': (params) => auditApi.getConsolidatedInstitutional(params),
  'Histórico de Estudiantes': (params) => auditApi.getHistoricalStudent(params),
  'Histórico de Docentes': (params) => auditApi.getHistoricalTeacher(params),
  'Histórico de Asistencia': (params) => auditApi.getHistoricalAttendance(params),
  'Histórico de Disciplina': (params) => auditApi.getHistoricalDiscipline(params),
  'Histórico de Permisos': (params) => auditApi.getHistoricalPermissions(params),
  'Histórico de Mensajería': (params) => auditApi.getHistoricalMessaging(params),
  'Actividad Docente': (params) => auditApi.getTeacherActivity(params),
  'Clases de Docentes': (params) => auditApi.getTeacherClasses(params),
  'Permisos de Docentes': (params) => auditApi.getTeacherPermissions(params),
  'Incidentes de Docentes': (params) => auditApi.getTeacherIncidents(params),
  'Sistema Docente': (params) => auditApi.getTeacherSystemActivity(params),
  'Mensajes WhatsApp': (params) => auditApi.getMessagingWhatsAppSent(params),
  'Respuestas de Acudientes': (params) => auditApi.getMessagingGuardianReplies(params),
  'Mensajes Fallidos': (params) => auditApi.getMessagingFailed(params),
  'Citaciones Enviadas': (params) => auditApi.getMessagingCitations(params),
  'Mensajes Internos': (params) => auditApi.getMessagingInternal(params),
  'Permisos de Salida': (params) => auditApi.getPermissionsClassExits(params),
  'Salidas del Colegio': (params) => auditApi.getPermissionsSchoolExits(params),
  'Salidas Pedagógicas Audit': (params) => auditApi.getPermissionsPedagogical(params),
  'Retornos Pendientes': (params) => auditApi.getPermissionsPendingReturns(params),
  'Historial de Permisos': (params) => auditApi.getPermissionsHistory(params),
  'Incidentes de Disciplina': (params) => auditApi.getDisciplineIncidents(params),
  'Violaciones de Disciplina': (params) => auditApi.getDisciplineViolations(params),
  'Aula Equivocada': (params) => auditApi.getDisciplineWrongClassroom(params),
  'Reportes de Disciplina': (params) => auditApi.getDisciplineReports(params),
  'Asistencia General': (params) => auditApi.getAttendanceGeneral(params),
  'Inasistencias Audit': (params) => auditApi.getAttendanceAbsences(params),
  'Llegadas Tarde Audit': (params) => auditApi.getAttendanceLates(params),
  'Evasión Escolar': (params) => auditApi.getAttendanceEvasion(params),
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

    const auditFn = AUDIT_MODULES[activeItem];
    if (auditFn) {
      const params = { from: fromDate, to: toDate };
      if (selectedGroup) params.group_id = selectedGroup;
      if (selectedStudent) params.student_id = selectedStudent;
      try {
        const res = await auditFn(params);
        const rows = res.data || [];
        setDynamicData(rows);
        const cols = {};
        if (rows.length > 0) Object.keys(rows[0]).forEach(k => { if (!['log_id','entity_id','action_details','metadata_json'].includes(k)) cols[k] = k.replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase()); });
        setDynamicColumns(cols);
      } catch (err) {
        setQueryError(humanizeError(err, 'Error de red al consultar auditoría'));
        setDynamicData([]);
        setDynamicColumns({});
      } finally {
        setLoadingData(false);
      }
      return;
    }

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

    const auditFn = AUDIT_MODULES[activeItem];
    const moduleSlug = activeItem === 'Análisis de Riesgo' ? null : MODULE_SLUGS[activeItem];
    if (activeItem !== 'Análisis de Riesgo' && !moduleSlug && !auditFn) {
      if (!abortController.signal.aborted) {
        setQueryError('Módulo no soportado');
        setLoadingData(false);
      }
      return () => abortController.abort();
    }

    if (auditFn) {
      const params = { from: fromDate, to: toDate };
      if (selectedGroup) params.group_id = selectedGroup;
      auditFn(params)
        .then((res) => {
          if (!abortController.signal.aborted) {
            const rows = res.data || [];
            setDynamicData(rows);
            const cols = {};
            if (rows.length > 0) Object.keys(rows[0]).forEach(k => { if (!['log_id','entity_id','action_details','metadata_json'].includes(k)) cols[k] = k.replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase()); });
            setDynamicColumns(cols);
          }
        })
        .catch((err) => { if (!abortController.signal.aborted) setQueryError(humanizeError(err, 'Error de red al consultar auditoría')); })
        .finally(() => { if (!abortController.signal.aborted) setLoadingData(false); });
    } else if (activeItem === 'Análisis de Riesgo') {
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
          { label: 'Alertas SOS', icon: AlertTriangle },
          { label: 'SOS Resueltas', icon: ShieldCheck },
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
      },
      {
        title: 'Auditoría',
        icon: Database,
        tone: 'warning',
        items: [
          { label: 'Seguridad Global', icon: ShieldAlert },
          { label: 'Accesos al Sistema', icon: ShieldCheck },
          { label: 'Sesiones Activas', icon: Activity },
          { label: 'Comandos del Sistema', icon: ClipboardList },
          { label: 'Actividad Administrativa', icon: FileText },
          { label: 'Intentos Fallidos', icon: AlertTriangle },
        ]
      },
      {
        title: 'Disciplina',
        icon: ShieldAlert,
        tone: 'danger',
        items: [
          { label: 'Incidentes de Disciplina', icon: AlertTriangle },
          { label: 'Violaciones de Disciplina', icon: ShieldAlert },
          { label: 'Aula Equivocada', icon: DoorOpen },
          { label: 'Reportes de Disciplina', icon: FileBarChart },
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
          { label: 'Asistencia Consolidada', icon: Activity },
          { label: 'Disciplina Consolidada', icon: ShieldAlert },
          { label: 'Permisos Consolidados', icon: ShieldCheck },
          { label: 'Mensajería Consolidada', icon: MessageSquare },
          { label: 'Docentes Consolidado', icon: GraduationCap },
          { label: 'Seguridad Consolidada', icon: ShieldCheck },
          { label: 'Métricas Institucionales', icon: BarChart2 },
        ]
      },
      {
        title: 'Históricos',
        icon: History,
        tone: 'accent',
        items: [
          { label: 'Históricos Completos', icon: History },
          { label: 'Histórico de Estudiantes', icon: GraduationCap },
          { label: 'Histórico de Docentes', icon: UserCheck },
          { label: 'Histórico de Asistencia', icon: Activity },
          { label: 'Histórico de Disciplina', icon: ShieldAlert },
          { label: 'Histórico de Permisos', icon: ShieldCheck },
          { label: 'Histórico de Mensajería', icon: MessageSquare },
        ]
      },
      {
        title: 'Auditoría',
        icon: Database,
        tone: 'danger',
        items: [
          { label: 'Auditoría Global', icon: FileText },
          { label: 'Integridad de Auditoría', icon: ShieldCheck },
          { label: 'Seguridad Global', icon: ShieldAlert },
          { label: 'Accesos al Sistema', icon: ShieldCheck },
          { label: 'Sesiones Activas', icon: Activity },
          { label: 'Comandos del Sistema', icon: ClipboardList },
          { label: 'Actividad Administrativa', icon: FileText },
          { label: 'Intentos Fallidos', icon: AlertTriangle },
        ]
      },
      {
        title: 'Mensajería',
        icon: MessageSquare,
        tone: 'accent',
        items: [
          { label: 'Mensajes WhatsApp', icon: Send },
          { label: 'Respuestas de Acudientes', icon: ContactRound },
          { label: 'Mensajes Fallidos', icon: AlertTriangle },
          { label: 'Citaciones Enviadas', icon: Mail },
          { label: 'Mensajes Internos', icon: FileText },
        ]
      },
      {
        title: 'Exportaciones',
        icon: FileText,
        tone: 'warning',
        items: [
          { label: 'Exportaciones Institucionales', icon: FileText },
          { label: 'Reportes', icon: FileBarChart },
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
          executeQuery={executeQuery}
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
