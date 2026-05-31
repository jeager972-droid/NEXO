import { useState, useEffect, useContext } from 'react';
import {
  FileText, Users, ShieldAlert, MessageSquare,
  Clock, Activity, History, ChevronRight,
  FileSpreadsheet, File as FilePdf, AlertTriangle, X,
  Lock, Unlock, ShieldCheck, Search, CalendarDays, Filter, Eye, Loader2,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { auditApi } from '../api/audit';
import { AuthContext } from '../context/AuthContext';

const ADMIN_ROLES = ['RECTOR', 'COORDINADOR', 'SUPER_RECTOR'];

const Audit = () => {
  const { user } = useContext(AuthContext);
  const isAdmin = ADMIN_ROLES.includes(user?.role_name || user?.role);

  const [activeSub, setActiveSub] = useState(null);
  const [logs, setLogs] = useState([]);
  const [integrity, setIntegrity] = useState(null);
  const [auditLoading, setAuditLoading] = useState(true);
  const [auditError, setAuditError] = useState(null);

  const modules = [
    {
      id: 'asistencia',
      title: 'Asistencia',
      icon: Users,
      subdivisions: ['Reporte general', 'Inasistencias', 'Llegadas tarde', 'Evasión interna'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'disciplina',
      title: 'Disciplina',
      icon: ShieldAlert,
      subdivisions: ['Incidentes', 'Vulneraciones', 'Intentos salón incorrecto', 'Spam biométrico', 'Reporte disciplinario'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'permisos',
      title: 'Permisos y Salidas',
      icon: Activity,
      subdivisions: ['Salidas clase', 'Salidas colegio', 'Salidas pedagógicas', 'Retornos pendientes', 'Historial permisos'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'mensajeria',
      title: 'Mensajería',
      icon: MessageSquare,
      subdivisions: ['WhatsApp enviados', 'Respuestas acudientes', 'Mensajes fallidos', 'Citaciones', 'Mensajería interna', 'Historial conversaciones'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'docente',
      title: 'Actividad Docente',
      icon: Clock,
      subdivisions: ['Actividad profesores', 'Clases registradas', 'Permisos emitidos', 'Incidencias asociadas', 'Actividad sistema docente'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'seguridad',
      title: 'Seguridad',
      icon: ShieldAlert,
      subdivisions: ['Auditoría global', 'Accesos', 'Sesiones', 'Comandos ejecutados', 'Actividad administrativa', 'Intentos fallidos'],
      exports: ['Reportes', 'Excel']
    },
    {
      id: 'sos',
      title: 'Alertas SOS',
      icon: AlertTriangle,
      subdivisions: ['Alertas emitidas', 'Alertas resueltas', 'Tiempo resolución', 'Historial SOS'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'historicos',
      title: 'Históricos',
      icon: History,
      subdivisions: ['Histórico docente', 'Histórico asistencia', 'Histórico disciplina', 'Histórico permisos', 'Histórico mensajes', 'Buscar histórico', 'Descargar individual', 'Descargar consolidado'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'consolidados',
      title: 'Reportes Consolidados',
      icon: FileText,
      subdivisions: ['Consolidado asistencia', 'Consolidado disciplina', 'Consolidado permisos', 'Consolidado mensajería', 'Consolidado docente', 'Consolidado seguridad', 'Consolidado institucional'],
      exports: ['Excel', 'PDF']
    }
  ];

  useEffect(() => {
    const load = async () => {
      try {
        setAuditLoading(true);
        const [logData, integrityData] = await Promise.all([
          auditApi.getGlobalLogs(),
          auditApi.getIntegrity(),
        ]);
        setLogs(Array.isArray(logData) ? logData : []);
        setIntegrity(integrityData);
      } catch (e) {
        setAuditError(e.message);
      } finally {
        setAuditLoading(false);
      }
    };
    load();
  }, []);

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none' }}>Auditoría</p>
        <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', marginTop: '2px' }} className="dark:text-slate-200">Control institucional de alto nivel</p>
      </div>

      {/* ── Audit Chain Terminal ── */}
      <section>
        <div className="flex items-center justify-between mb-3">
          <div>
            <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase' }}>Audit Chain</p>
            <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366' }} className="dark:text-slate-200">
              {isAdmin ? 'Registro de actividad institucional' : 'Log de seguridad criptográfico'}
            </p>
          </div>
          {!isAdmin && (
            <div className="flex items-center gap-1.5">
              <ShieldCheck size={14} strokeWidth={2} style={{ color: '#00A67E' }} />
              <span style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#00A67E', textTransform: 'uppercase' }}>SHA-256</span>
            </div>
          )}
        </div>

        <div className="overflow-x-auto" style={{ backgroundColor: '#070D1B', border: '1.5px solid #1E293B' }}>
          {/* Terminal header */}
          <div className="flex items-center gap-1.5 px-4 py-2.5" style={{ borderBottom: '1px solid #1E293B' }}>
            {['#DC2626','#D97706','#00A67E'].map(c => (
              <span key={c} className="block w-2.5 h-2.5 rounded-full" style={{ backgroundColor: c, opacity: 0.7 }} />
            ))}
            <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '10px', color: '#475569', marginLeft: '8px', letterSpacing: '0.1em' }}>
              nexo-audit-chain — bash
            </span>
          </div>

          {/* Log entries */}
          <div className="min-w-[700px]">
            {auditLoading && (
              <div className="px-4 py-3 text-xs" style={{ fontFamily: 'ui-monospace, monospace', color: '#475569' }}>
                Cargando logs…
              </div>
            )}
            {auditError && (
              <div className="px-4 py-3 text-xs" style={{ fontFamily: 'ui-monospace, monospace', color: '#FCA5A5' }}>
                Error: {auditError}
              </div>
            )}
            {logs.map((entry, i) => (
              <motion.div
                key={entry.audit_id ?? i}
                initial={{ opacity: 0, x: -6 }}
                animate={{ opacity: 1, x: 0 }}
                transition={{ delay: i * 0.06, duration: 0.2 }}
                className="flex items-center gap-0 px-4 py-2.5 hover:bg-white/[0.03] transition-colors"
                style={{ borderBottom: i < logs.length - 1 ? '1px solid rgba(30,41,59,0.5)' : 'none' }}
              >
                {/* Seq */}
                <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '10px', color: '#334155', width: '28px', flexShrink: 0 }}>
                  {String(i + 1).padStart(3, '0')}
                </span>
                {/* Timestamp */}
                <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '11px', color: '#00A67E', width: '100px', flexShrink: 0 }}>
                  [{new Date(entry.created_at).toLocaleTimeString('es-CO', { hour12: false })}]
                </span>
                {/* Event */}
                <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '11px', color: '#F1F5F9', width: '200px', flexShrink: 0, letterSpacing: '0.05em' }}>
                  {entry.event_type}
                </span>
                {/* User */}
                <span className="truncate" style={{ fontFamily: 'ui-monospace, monospace', fontSize: '10px', color: '#64748B', width: '180px', flexShrink: 0 }}>
                  {entry.actor_name || '—'}
                </span>
                {/* Hash */}
                <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '10px', color: '#334155', width: '110px', flexShrink: 0 }}>
                  {(entry.audit_id || '').slice(0, 12)}…
                </span>
                {/* Valid indicator */}
                <div className="flex items-center gap-1.5 shrink-0">
                  <Lock size={12} strokeWidth={2.5} style={{ color: '#00A67E' }} />
                  <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '9px', fontWeight: 700, color: '#00A67E', letterSpacing: '0.1em' }}>
                    OK
                  </span>
                </div>
              </motion.div>
            ))}
          </div>
        </div>
      </section>

      {/* ── Module grid ── */}
      <section>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', marginBottom: '12px', userSelect: 'none' }}>
          Módulos de Reporte
        </p>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {modules.map((mod, idx) => (
            <motion.div key={mod.id}
              initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.2, delay: idx * 0.03 }}
              className="bg-white dark:bg-slate-900"
              style={{ border: '1.5px solid #E2E8F0' }}
            >
              {/* Module header */}
              <div className="flex items-center justify-between px-4 py-3" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
                <div className="flex items-center gap-2.5">
                  <div className="flex items-center justify-center w-7 h-7" style={{ backgroundColor: 'rgba(0,51,102,0.07)', color: '#003366' }}>
                    <mod.icon size={14} strokeWidth={2} />
                  </div>
                  <p className="text-xs font-black uppercase dark:text-white" style={{ letterSpacing: '0.08em', color: '#1E293B' }}>{mod.title}</p>
                </div>
                {/* Export buttons */}
                <div className="flex items-center gap-1">
                  {mod.exports.map((exp, i) => (
                    <button key={i} onClick={() => setActiveSub(`${mod.title} — ${exp}`)}
                      className="flex items-center gap-1 px-2 py-1 text-slate-400 hover:text-gov-900 hover:bg-gov-50 dark:hover:bg-gov-900/20 transition-colors"
                      title={exp}>
                      {exp === 'Excel' ? <FileSpreadsheet size={12} strokeWidth={2} />
                        : exp === 'PDF' ? <FilePdf size={12} strokeWidth={2} />
                        : <FileText size={12} strokeWidth={2} />}
                    </button>
                  ))}
                </div>
              </div>
              {/* Subdivisions */}
              <div>
                {mod.subdivisions.map((sub, i) => (
                  <button key={i} onClick={() => setActiveSub(sub)}
                    className="group flex items-center justify-between w-full px-4 py-2.5 text-left bg-white dark:bg-slate-900 hover:bg-gov-900 dark:hover:bg-gov-900 transition-colors duration-150"
                    style={{ borderBottom: i < mod.subdivisions.length - 1 ? '1px solid #F8FAFC' : 'none' }}>
                    <span className="text-xs font-medium text-slate-600 dark:text-slate-400 group-hover:text-white transition-colors truncate"
                          style={{ letterSpacing: '0.04em' }}>{sub}</span>
                    <ChevronRight size={11} strokeWidth={2} className="text-slate-300 group-hover:text-white/60 transition-colors shrink-0 ml-2" />
                  </button>
                ))}
              </div>
            </motion.div>
          ))}
        </div>
      </section>

      {/* ── Sub-module Drawer ── */}
      <AnimatePresence>
        {activeSub && (
          <>
            <motion.div key="ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
              transition={{ duration: 0.2 }} className="fixed inset-0 z-40"
              style={{ backgroundColor: 'rgba(2,6,23,0.5)', backdropFilter: 'blur(2px)' }}
              onClick={() => setActiveSub(null)} />
            <motion.div key="dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
              transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
              className="fixed right-0 inset-y-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
              style={{ maxWidth: '480px', borderLeft: '1.5px solid #E2E8F0' }}
            >
              <div className="shrink-0 flex items-center justify-between px-6 py-4" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
                <div className="flex items-center gap-3">
                  <div className="flex items-center justify-center w-9 h-9" style={{ backgroundColor: 'rgba(0,51,102,0.08)' }}>
                    <Activity size={16} strokeWidth={2} style={{ color: '#003366' }} />
                  </div>
                  <div>
                    <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: '#1E293B' }}>{activeSub}</p>
                    <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>Módulo de Auditoría</p>
                  </div>
                </div>
                <button onClick={() => setActiveSub(null)} className="p-1.5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                  <X size={18} strokeWidth={2} />
                </button>
              </div>
              <AuditDrawer activeSub={activeSub} onClose={() => setActiveSub(null)} />
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
};

/* ── Drawer Configuration ── */
const DRAWER_CONFIG = {
  'Reporte general': { api: auditApi.getAttendanceGeneral, needsDates: true },
  'Inasistencias': { api: auditApi.getAttendanceAbsences, needsDates: true, needsGroup: true, needsStudent: true },
  'Llegadas tarde': { api: auditApi.getAttendanceLates, needsDates: true, needsGroup: true, needsStudent: true },
  'Evasión interna': { api: auditApi.getAttendanceEvasion, needsDates: true, needsGroup: true, needsStudent: true },
  'Incidentes': { api: auditApi.getDisciplineIncidents, needsDates: true, needsGroup: true, needsStudent: true },
  'Vulneraciones': { api: auditApi.getDisciplineViolations, needsDates: true, needsGroup: true, needsStudent: true },
  'Intentos salón incorrecto': { api: auditApi.getDisciplineWrongClassroom, needsDates: true, needsGroup: true, needsStudent: true },
  'Spam biométrico': { api: auditApi.getDisciplineBiometricSpam, needsDates: true },
  'Reporte disciplinario': { api: auditApi.getDisciplineReports, needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas clase': { api: auditApi.getPermissionsClassExits, needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas colegio': { api: auditApi.getPermissionsSchoolExits, needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas pedagógicas': { api: auditApi.getPermissionsPedagogical, needsDates: true, needsGroup: true, needsStudent: true },
  'Retornos pendientes': { api: auditApi.getPermissionsPendingReturns, needsDates: true, needsGroup: true, needsStudent: true },
  'Historial permisos': { api: auditApi.getPermissionsHistory, needsDates: true },
  'WhatsApp enviados': { api: auditApi.getMessagingWhatsAppSent, needsDates: true },
  'Respuestas acudientes': { api: auditApi.getMessagingGuardianReplies, needsDates: true },
  'Mensajes fallidos': { api: auditApi.getMessagingFailed, needsDates: true },
  'Citaciones': { api: auditApi.getMessagingCitations, needsDates: true },
  'Mensajería interna': { api: auditApi.getMessagingInternal, needsDates: true },
  'Historial conversaciones': { api: auditApi.getMessagingConversations, needsDates: true },
  'Actividad profesores': { api: auditApi.getTeacherActivity, needsDates: true, needsStaff: true },
  'Clases registradas': { api: auditApi.getTeacherClasses, needsDates: true, needsStaff: true },
  'Permisos emitidos': { api: auditApi.getTeacherPermissions, needsDates: true, needsStaff: true },
  'Incidencias asociadas': { api: auditApi.getTeacherIncidents, needsDates: true, needsStaff: true },
  'Actividad sistema docente': { api: auditApi.getTeacherSystemActivity, needsDates: true, needsStaff: true },
  'Auditoría global': { api: auditApi.getSecurityGlobal, needsDates: true },
  'Accesos': { api: auditApi.getSecurityAccesses, needsDates: true },
  'Sesiones': { api: auditApi.getSecuritySessions, needsDates: true },
  'Comandos ejecutados': { api: auditApi.getSecurityCommands, needsDates: true },
  'Actividad administrativa': { api: auditApi.getSecurityAdminActivity, needsDates: true },
  'Intentos fallidos': { api: auditApi.getSecurityFailedAttempts, needsDates: true },
  'Alertas emitidas': { api: auditApi.getSosAlerts, needsDates: true },
  'Alertas resueltas': { api: auditApi.getSosResolved, needsDates: true },
  'Tiempo resolución': { api: auditApi.getSosResolutionTime, needsDates: true },
  'Historial SOS': { api: auditApi.getSosHistory, needsDates: true },
  'Histórico docente': { api: auditApi.getHistoricalTeacher, needsDates: true, needsStaff: true },
  'Histórico asistencia': { api: auditApi.getHistoricalAttendance, needsDates: true },
  'Histórico disciplina': { api: auditApi.getHistoricalDiscipline, needsDates: true },
  'Histórico permisos': { api: auditApi.getHistoricalPermissions, needsDates: true },
  'Histórico mensajes': { api: auditApi.getHistoricalMessaging, needsDates: true },
  'Buscar histórico': { api: auditApi.getHistoricalSearch, needsDates: false },
  'Descargar individual': { api: auditApi.getHistoricalDownload, needsDates: false },
  'Descargar consolidado': { api: auditApi.getHistoricalDownloadConsolidated, needsDates: true },
  'Consolidado asistencia': { api: auditApi.getConsolidatedAttendance, needsDates: true },
  'Consolidado disciplina': { api: auditApi.getConsolidatedDiscipline, needsDates: true },
  'Consolidado permisos': { api: auditApi.getConsolidatedPermissions, needsDates: true },
  'Consolidado mensajería': { api: auditApi.getConsolidatedMessaging, needsDates: true },
  'Consolidado docente': { api: auditApi.getConsolidatedTeacher, needsDates: true },
  'Consolidado seguridad': { api: auditApi.getConsolidatedSecurity, needsDates: true },
  'Consolidado institucional': { api: auditApi.getConsolidatedInstitutional, needsDates: true },
};

const EXCLUDE_COLS = ['school_id','sync_hash','event_signature','metadata_json','command_payload','previous_data','new_data','biometric_hash'];

function AuditDrawer({ activeSub, onClose }) {
  const [filters, setFilters] = useState({ from: '', to: '', groupId: '', studentId: '', staffId: '', q: '' });
  const [groups, setGroups] = useState([]);
  const [students, setStudents] = useState([]);
  const [staff, setStaff] = useState([]);
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [metaLoading, setMetaLoading] = useState(false);

  const config = DRAWER_CONFIG[activeSub];

  useEffect(() => {
    if (!activeSub) return;
    setData(null);
    setError(null);
    const today = new Date().toISOString().slice(0, 10);
    const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);
    setFilters({ from: monthAgo, to: today, groupId: '', studentId: '', staffId: '', q: '' });
    setGroups([]);
    setStudents([]);
    setStaff([]);

    if (config?.needsGroup || config?.needsStudent) {
      setMetaLoading(true);
      auditApi.getGroups()
        .then(r => setGroups(r.data || []))
        .catch(() => {})
        .finally(() => setMetaLoading(false));
    }
    if (config?.needsStaff) {
      setMetaLoading(true);
      auditApi.getStaff()
        .then(r => setStaff(r.data || []))
        .catch(() => {})
        .finally(() => setMetaLoading(false));
    }
  }, [activeSub]);

  useEffect(() => {
    if (!filters.groupId) { setStudents([]); return; }
    auditApi.getGroupStudents(filters.groupId)
      .then(r => setStudents(r.data || []))
      .catch(() => setStudents([]));
  }, [filters.groupId]);

  const handleSearch = async () => {
    if (!config) { setError('Submódulo no configurado'); return; }
    try {
      setLoading(true);
      setError(null);
      const params = {};
      if (config.needsDates) {
        if (filters.from) params.from = filters.from;
        if (filters.to) params.to = filters.to;
      }
      if ((config.needsGroup || config.needsStudent) && filters.groupId) params.group_id = filters.groupId;
      if (config.needsStudent && filters.studentId) params.student_id = filters.studentId;
      if (config.needsStaff && filters.staffId) params.user_id = filters.staffId;
      if (activeSub === 'Buscar histórico' && filters.q) params.q = filters.q;
      if (activeSub === 'Descargar individual') {
        params.type = 'student';
        params.id = filters.studentId || filters.staffId || '';
      }
      const res = await config.api(params);
      setData(res);
    } catch (e) {
      setError(e.message || 'Error consultando datos');
    } finally {
      setLoading(false);
    }
  };

  const rows = data?.data ?? data?.summary ?? (Array.isArray(data) ? data : []);
  const stats = data?.stats ?? null;

  const visibleKeys = rows.length > 0
    ? Object.keys(rows[0]).filter(k => !EXCLUDE_COLS.includes(k))
    : [];

  return (
    <div className="flex-1 flex flex-col p-0 overflow-hidden bg-white dark:bg-slate-900">
      {/* Filter Panel */}
      <div className="shrink-0 p-5 border-b border-slate-100 dark:border-slate-800 space-y-4">
        <div className="flex items-center gap-2 mb-1">
          <Filter size={14} strokeWidth={2} className="text-slate-400" />
          <span className="text-[10px] font-bold uppercase tracking-widest text-slate-400">Filtros de consulta</span>
        </div>

        {config?.needsDates && (
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Desde</label>
              <div className="relative">
                <CalendarDays size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                <input type="date" value={filters.from}
                  onChange={e => setFilters(p => ({ ...p, from: e.target.value }))}
                  className="w-full pl-8 pr-2 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30 focus:border-[#003366]"
                />
              </div>
            </div>
            <div>
              <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Hasta</label>
              <div className="relative">
                <CalendarDays size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
                <input type="date" value={filters.to}
                  onChange={e => setFilters(p => ({ ...p, to: e.target.value }))}
                  className="w-full pl-8 pr-2 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30 focus:border-[#003366]"
                />
              </div>
            </div>
          </div>
        )}

        {(config?.needsGroup || config?.needsStudent) && (
          <div>
            <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Grupo académico</label>
            <select
              value={filters.groupId}
              onChange={e => setFilters(p => ({ ...p, groupId: e.target.value, studentId: '' }))}
              className="w-full px-3 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30 focus:border-[#003366]"
            >
              <option value="">{metaLoading ? 'Cargando grupos…' : 'Todos los grupos'}</option>
              {groups.map(g => (
                <option key={g.group_id} value={g.group_id}>{g.group_name} {g.grade_level ? `(${g.grade_level})` : ''}</option>
              ))}
            </select>
          </div>
        )}

        {config?.needsStudent && filters.groupId && (
          <div>
            <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Estudiante</label>
            <select
              value={filters.studentId}
              onChange={e => setFilters(p => ({ ...p, studentId: e.target.value }))}
              className="w-full px-3 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30 focus:border-[#003366]"
            >
              <option value="">Todos los estudiantes del grupo</option>
              {students.map(s => (
                <option key={s.student_id} value={s.student_id}>{s.last_name}, {s.first_name} — {s.document_number}</option>
              ))}
            </select>
          </div>
        )}

        {config?.needsStaff && (
          <div>
            <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Personal</label>
            <select
              value={filters.staffId}
              onChange={e => setFilters(p => ({ ...p, staffId: e.target.value }))}
              className="w-full px-3 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30 focus:border-[#003366]"
            >
              <option value="">{metaLoading ? 'Cargando personal…' : 'Todo el personal'}</option>
              {staff.map(u => (
                <option key={u.user_id} value={u.user_id}>{u.last_name}, {u.first_name} — {u.role_name}</option>
              ))}
            </select>
          </div>
        )}

        <button
          onClick={handleSearch}
          disabled={loading}
          className="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-[#003366] hover:bg-[#002855] text-white text-xs font-bold uppercase tracking-wider rounded transition-colors disabled:opacity-60"
        >
          {loading ? <Loader2 size={14} className="animate-spin" /> : <Eye size={14} />}
          Consultar
        </button>
      </div>

      {/* Results */}
      <div className="flex-1 overflow-auto">
        {loading && (
          <div className="flex flex-col items-center justify-center h-64 gap-3">
            <Loader2 size={28} strokeWidth={1.5} className="text-[#003366] animate-spin" />
            <p className="text-xs text-slate-400 font-medium">Consultando registros…</p>
          </div>
        )}

        {error && !loading && (
          <div className="flex flex-col items-center justify-center h-64 gap-3 px-6">
            <div className="w-12 h-12 rounded-full bg-red-50 flex items-center justify-center">
              <AlertTriangle size={20} className="text-red-500" />
            </div>
            <p className="text-xs text-red-500 font-medium text-center">{error}</p>
          </div>
        )}

        {!loading && !error && rows.length === 0 && !stats && (
          <div className="flex flex-col items-center justify-center h-64 gap-4 px-6">
            <div className="w-14 h-14 rounded-full bg-slate-50 flex items-center justify-center border border-slate-100">
              <Activity size={24} strokeWidth={1.5} className="text-slate-300" />
            </div>
            <div className="text-center space-y-1">
              <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Sin registros</p>
              <p className="text-xs text-slate-400 max-w-[260px] leading-relaxed">
                Ajusta los filtros y presiona <strong>Consultar</strong> para obtener resultados.
              </p>
            </div>
          </div>
        )}

        {!loading && !error && (rows.length > 0 || stats) && (
          <div className="p-5 space-y-5">
            {stats && (
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                {Object.entries(stats).map(([k, v]) => (
                  <div key={k} className="p-3 rounded-lg border border-slate-100 bg-slate-50/50 dark:bg-slate-800/50 dark:border-slate-700">
                    <p className="text-[9px] font-bold uppercase tracking-wider text-slate-400 mb-1">{k.replace(/_/g, ' ')}</p>
                    <p className="text-lg font-black text-[#003366] dark:text-slate-100">{v ?? 0}</p>
                  </div>
                ))}
              </div>
            )}

            {rows.length > 0 && (
              <div className="border border-slate-200 dark:border-slate-700 rounded-lg overflow-hidden">
                <div className="overflow-x-auto">
                  <table className="w-full text-left border-collapse">
                    <thead>
                      <tr className="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                        {visibleKeys.map(k => (
                          <th key={k} className="px-4 py-2.5 text-[10px] font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">
                            {k.replace(/_/g, ' ')}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {rows.map((row, i) => (
                        <tr key={i} className="border-b border-slate-100 dark:border-slate-800 hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition-colors">
                          {visibleKeys.map(k => (
                            <td key={k} className="px-4 py-2.5 text-[11px] text-slate-700 dark:text-slate-300 whitespace-nowrap max-w-[200px] truncate">
                              {formatCell(k, row[k])}
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="px-4 py-2 bg-slate-50 dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700">
                  <p className="text-[10px] text-slate-400 font-medium">{rows.length} registro{rows.length !== 1 ? 's' : ''}</p>
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

function formatCell(key, value) {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'Sí' : 'No';
  const sk = String(key).toLowerCase();
  if (sk.includes('timestamp') || sk.includes('_at') || sk.includes('time') || sk.includes('created') || sk.includes('detected') || sk.includes('emitted') || sk.includes('resolved') || sk.includes('sent') || sk.includes('executed')) {
    const d = new Date(value);
    if (!isNaN(d)) return d.toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' });
  }
  if (sk.includes('date') || sk.includes('birth')) {
    const d = new Date(value);
    if (!isNaN(d)) return d.toLocaleDateString('es-CO');
  }
  const s = String(value);
  if (s.length > 100) return s.slice(0, 100) + '…';
  return s;
}

export default Audit;
