import { useState, useEffect, useContext, useRef } from 'react';
import { useSearchParams, Navigate } from 'react-router-dom';
import {
  FileText, Users, ShieldAlert, MessageSquare,
  Clock, Activity, History, ChevronRight,
  FileSpreadsheet, File as FilePdf, AlertTriangle, X,
  Lock, Unlock, ShieldCheck, Search, CalendarDays, Filter, Eye, Loader2,
  Download, CheckCircle2,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { auditApi } from '../api/audit';
import { AuthContext } from '../context/AuthContext';
import { formatCellValue as formatCell, humanizeColumn, EXCLUDE_COLS } from '../utils/formatters';

const ADMIN_ROLES = ['RECTOR', 'COORDINADOR', 'SUPER_RECTOR'];

const ALL_SUBS = [
  'Inasistencias', 'Llegadas tarde', 'Evasón interna',
  'Intentos salón incorrecto', 'Spam biométrico', 'Reporte disciplinario',
  'Salidas clase', 'Salidas colegio', 'Salidas pedagógicas',
  'Retornos pendientes', 'Historial permisos', 'Permisos emitidos',
  'Alertas SOS emitidas', 'Evasiones internas'
  // BUG-03 FIX: 'Seguimiento Estudiantil' removido — tiene su propia página /seguimiento y no existe en DRAWER_CONFIG
];

const Audit = () => {
  const { user } = useContext(AuthContext);
  const isAdmin = ADMIN_ROLES.includes(user?.role_name || user?.role);
  
  // Fix 3.4: Route guard para Auditoría
  if (!['SUPER_RECTOR', 'RECTOR'].includes(user?.role)) {
    return <Navigate to="/" replace />;
  }
  const [searchParams, setSearchParams] = useSearchParams();

  const [activeSub, setActiveSub] = useState(null);
  const [exportModal, setExportModal] = useState(null);

  useEffect(() => {
    const sub = searchParams.get('sub');
    if (sub && ALL_SUBS.includes(sub)) {
      setActiveSub(sub);
      setSearchParams({}, { replace: true });
    }
  }, [searchParams, setSearchParams]);

  const modules = [
    {
      id: 'asistencia',
      title: 'Asistencia',
      icon: Users,
      subdivisions: ['Inasistencias', 'Llegadas tarde', 'Evasión interna'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'disciplina',
      title: 'Disciplina',
      icon: ShieldAlert,
      // BUG-03 FIX: 'Seguimiento Estudiantil' removido — tiene su propia página /seguimiento
      subdivisions: ['Intentos salón incorrecto', 'Spam biométrico', 'Reporte disciplinario'],
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
      id: 'docente',
      title: 'Actividad Docente',
      icon: Clock,
      subdivisions: ['Permisos emitidos'],
      exports: ['Excel', 'PDF']
    },
    {
      id: 'alertas',
      title: 'Alertas',
      icon: AlertTriangle,
      subdivisions: ['Alertas SOS emitidas', 'Evasiones internas'],
      exports: ['Excel', 'PDF']
    }
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div>
        <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', letterSpacing: '-0.01em' }} className="dark:text-slate-200">Consulta</p>
        <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none', marginTop: '4px' }}>
          Panel de control de registros de la institución educativa {user?.school_name || ''}
        </p>
      </div>

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
                    <button key={i} onClick={() => setExportModal({ module: mod, format: exp })}
                      className="flex items-center gap-1 px-2 py-1 text-slate-400 hover:text-[#003366] hover:bg-[#003366]/5 dark:hover:bg-[#003366]/10 transition-colors"
                      title={`Descargar ${exp}`}>
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
              transition={{ duration: 0.2 }} className="fixed z-40"
              style={{ top: '52px', left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(2,6,23,0.45)' }}
              onClick={() => setActiveSub(null)} />
            <motion.div key="dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
              transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
              className="fixed right-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
              style={{ top: '56px', bottom: 0, borderLeft: '1.5px solid #E2E8F0' }}
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

      {/* ── Export Modal ── */}
      <AnimatePresence>
        {exportModal && (
          <>
            <motion.div key="ex-ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
              transition={{ duration: 0.2 }} className="fixed z-50"
              style={{ top: '52px', left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(2,6,23,0.45)' }}
              onClick={() => setExportModal(null)} />
            <motion.div key="ex-md" initial={{ opacity: 0, scale: 0.96, y: 20 }} animate={{ opacity: 1, scale: 1, y: 0 }} exit={{ opacity: 0, scale: 0.96, y: 20 }}
              transition={{ duration: 0.2 }}
              className="fixed inset-0 z-[60] flex items-center justify-center p-4 pointer-events-none">
              <div className="bg-white dark:bg-slate-900 w-full max-w-md rounded-xl shadow-2xl border border-slate-200 dark:border-slate-700 p-6 pointer-events-auto"
                onClick={e => e.stopPropagation()}>
                <ExportModalContent module={exportModal.module} format={exportModal.format} onClose={() => setExportModal(null)} />
              </div>
            </motion.div>
          </>
        )}
      </AnimatePresence>
    </div>
  );
};

/* ── Drawer Configuration ── */
const DRAWER_CONFIG = {
  'Inasistencias': { api: auditApi.getAttendanceAbsences, needsDates: true, needsGroup: true, needsStudent: true },
  'Llegadas tarde': { api: auditApi.getAttendanceLates, needsDates: true, needsGroup: true, needsStudent: true },
  'Evasión interna': { api: auditApi.getAttendanceEvasion, needsDates: true, needsGroup: true, needsStudent: true },
  'Intentos salón incorrecto': { api: auditApi.getDisciplineWrongClassroom, needsDates: true, needsGroup: true, needsStudent: true },
  'Spam biométrico': { api: auditApi.getDisciplineBiometricSpam, needsDates: true, needsGroup: true, needsStudent: true },
  'Reporte disciplinario': { api: auditApi.getDisciplineReports, needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas clase': { api: auditApi.getPermissionsClassExits, needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas colegio': { api: auditApi.getPermissionsSchoolExits, needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas pedagógicas': { api: auditApi.getPermissionsPedagogical, needsDates: true, needsGroup: true, needsStudent: true },
  'Retornos pendientes': { api: auditApi.getPermissionsPendingReturns, needsDates: true, needsGroup: true, needsStudent: true },
  'Historial permisos': { api: auditApi.getPermissionsHistory, needsDates: true },
  'Permisos emitidos': { api: auditApi.getTeacherPermissions, needsDates: true, needsStaff: true },
  'Alertas SOS emitidas': { api: auditApi.getSosAlerts, needsDates: true, needsGroup: true, needsStudent: true },
  'Evasiones internas': { api: auditApi.getAttendanceEvasion, needsDates: true, needsGroup: true, needsStudent: true },
};

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

  // BUG-12 FIX: contador ref — evita que el loading termine antes cuando hay 2 peticiones paralelas
  const metaLoadingCountRef = useRef(0);

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
    metaLoadingCountRef.current = 0;

    if (config?.needsGroup || config?.needsStudent) {
      metaLoadingCountRef.current++;
      setMetaLoading(true);
      auditApi.getGroups()
        .then(r => setGroups(r.data || []))
        .catch(() => {})
        .finally(() => {
          metaLoadingCountRef.current--;
          if (metaLoadingCountRef.current <= 0) setMetaLoading(false);
        });
    }
    if (config?.needsStaff) {
      metaLoadingCountRef.current++;
      setMetaLoading(true);
      auditApi.getStaff()
        .then(r => setStaff(r.data || []))
        .catch(() => {})
        .finally(() => {
          metaLoadingCountRef.current--;
          if (metaLoadingCountRef.current <= 0) setMetaLoading(false);
        });
    }
  }, [activeSub]);

  useEffect(() => {
    if (!filters.groupId) { setStudents([]); return; }
    auditApi.getGroupStudents(filters.groupId)
      .then(r => setStudents(r.data || []))
      // BUG-12 FIX: loguear error en vez de tragarlo en silencio
      .catch((e) => { console.error('Error cargando estudiantes del grupo:', e); setStudents([]); });
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
          <SearchableSelect
            label="Grupo académico"
            placeholder={metaLoading ? 'Cargando grupos…' : 'Todos los grupos'}
            options={groups.map(g => ({ id: g.group_id, name: `${g.group_name}${g.grade_level ? ` (${g.grade_level})` : ''}` }))}
            value={filters.groupId}
            onChange={v => setFilters(p => ({ ...p, groupId: v, studentId: '' }))}
          />
        )}

        {config?.needsStudent && filters.groupId && (
          <SearchableSelect
            label="Estudiante"
            placeholder="Todos los estudiantes del grupo"
            options={[...students]
              .sort((a, b) => {
                const la = (a.last_name || '').toLowerCase();
                const lb = (b.last_name || '').toLowerCase();
                if (la !== lb) return la.localeCompare(lb, 'es');
                return (a.first_name || '').toLowerCase().localeCompare((b.first_name || '').toLowerCase(), 'es');
              })
              .map(s => ({ id: s.student_id, name: `${s.last_name} ${s.first_name}` }))}
            value={filters.studentId}
            onChange={v => setFilters(p => ({ ...p, studentId: v }))}
          />
        )}

        {config?.needsStaff && (
          <SearchableSelect
            label="Personal"
            placeholder={metaLoading ? 'Cargando personal…' : 'Todo el personal'}
            options={[...staff]
              .sort((a, b) => {
                const la = (a.last_name || '').toLowerCase();
                const lb = (b.last_name || '').toLowerCase();
                if (la !== lb) return la.localeCompare(lb, 'es');
                return (a.first_name || '').toLowerCase().localeCompare((b.first_name || '').toLowerCase(), 'es');
              })
              .map(u => ({ id: u.user_id, name: `${u.last_name} ${u.first_name} — ${u.role_name}` }))}
            value={filters.staffId}
            onChange={v => setFilters(p => ({ ...p, staffId: v }))}
          />
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
                    <p className="text-[9px] font-bold uppercase tracking-wider text-slate-400 mb-1">{humanizeColumn(k)}</p>
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
                            {humanizeColumn(k)}
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

function SearchableSelect({ label, options, value, onChange, placeholder, loading }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const ref = useRef(null);

  useEffect(() => {
    function handleClickOutside(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const filtered = q.trim() === '' ? options : options.filter(o => {
    const text = String(o.name || '').toLowerCase();
    return text.includes(q.toLowerCase());
  });

  const selected = options.find(o => o.id === value);

  return (
    <div className="relative" ref={ref}>
      {label && <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">{label}</label>}
      <div
        onClick={() => setOpen(!open)}
        className="w-full px-3 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 cursor-pointer flex items-center justify-between"
      >
        <span className="truncate">{selected ? selected.name : (loading ? 'Cargando…' : placeholder)}</span>
        <Search size={12} className="text-slate-400 shrink-0 ml-2" />
      </div>
      {open && (
        <div className="absolute z-20 mt-1 w-full bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded shadow-lg max-h-60 overflow-auto">
          <div className="p-2 border-b border-slate-100 dark:border-slate-700 sticky top-0 bg-white dark:bg-slate-800">
            <div className="relative">
              <Search size={12} className="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                autoFocus
                type="text"
                value={q}
                onChange={e => setQ(e.target.value)}
                placeholder="Buscar…"
                className="w-full pl-7 pr-2 py-1.5 text-xs bg-slate-50 dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded focus:outline-none focus:ring-1 focus:ring-[#003366]"
                onClick={e => e.stopPropagation()}
              />
            </div>
          </div>
          {filtered.length === 0 && (
            <div className="px-3 py-2 text-xs text-slate-400">Sin coincidencias</div>
          )}
          {filtered.map(o => (
            <div
              key={o.id}
              onClick={() => { onChange(o.id); setOpen(false); setQ(''); }}
              className={`px-3 py-2 text-xs cursor-pointer truncate hover:bg-slate-50 dark:hover:bg-slate-700 ${o.id === value ? 'bg-[#003366]/5 text-[#003366] font-semibold' : 'text-slate-700 dark:text-slate-200'}`}
            >
              {o.name}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

function ExportModalContent({ module, format, onClose }) {
  const [sub, setSub] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [loading, setLoading] = useState(false);
  const [toast, setToast] = useState(null);

  useEffect(() => {
    const today = new Date().toISOString().slice(0, 10);
    const monthAgo = new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10);
    setFrom(monthAgo);
    setTo(today);
    setSub(module.subdivisions[0] || '');
  }, [module]);

  const buildFilename = () => {
    const slug = sub.toLowerCase().replace(/[^a-z0-9]+/g, '_');
    const dateSlug = from && to ? `_${from}_${to}` : '';
    return `nexo_${module.id}_${slug}${dateSlug}`;
  };

  const fetchExportData = async () => {
    const config = DRAWER_CONFIG[sub];
    if (!config) throw new Error('Submódulo no configurado');
    const params = {};
    if (config.needsDates) {
      if (from) params.from = from;
      if (to) params.to = to;
    }
    const res = await config.api(params);
    const rows = res?.data ?? res?.summary ?? (Array.isArray(res) ? res : []);
    return rows;
  };

  const downloadExcel = (rows) => {
    if (!rows.length) {
      setToast({ type: 'error', message: 'No hay datos para exportar en este período' });
      return;
    }
    const keys = Object.keys(rows[0]).filter(k => !EXCLUDE_COLS.includes(k));
    const headers = keys.map(humanizeColumn);
    let csv = '\uFEFF' + headers.join(';') + '\n';
    rows.forEach(row => {
      const line = keys.map(k => {
        const v = formatCell(k, row[k]);
        const cell = String(v).replace(/"/g, '""');
        return `"${cell}"`;
      });
      csv += line.join(';') + '\n';
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = buildFilename() + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  };

  const downloadPDF = (rows) => {
    if (!rows.length) {
      setToast({ type: 'error', message: 'No hay datos para exportar en este período' });
      return;
    }
    const keys = Object.keys(rows[0]).filter(k => !EXCLUDE_COLS.includes(k));
    const headers = keys.map(humanizeColumn);
    let tbody = '';
    rows.forEach(row => {
      tbody += '<tr>' + keys.map(k => `<td>${String(formatCell(k, row[k])).replace(/</g, '&lt;')}</td>`).join('') + '</tr>';
    });
    const html = `
      <html><head><meta charset="utf-8">
      <style>
        body{font-family:sans-serif;margin:40px;color:#1E293B}
        h1{font-size:16px;font-weight:800;color:#003366;text-transform:uppercase;letter-spacing:0.06em}
        h2{font-size:11px;color:#94A3B8;text-transform:uppercase;letter-spacing:0.2em;margin-top:4px}
        table{width:100%;border-collapse:collapse;margin-top:24px;font-size:10px}
        th{background:#F1F5F9;border-bottom:2px solid #003366;padding:8px;text-align:left;text-transform:uppercase;letter-spacing:0.1em;font-size:9px;color:#64748B}
        td{border-bottom:1px solid #E2E8F0;padding:8px}
        .meta{color:#94A3B8;font-size:9px;margin-top:16px}
      </style></head><body>
      <h1>${module.title} — ${sub}</h1>
      <h2>Periodo: ${from} a ${to}</h2>
      <table><thead><tr>${headers.map(h => `<th>${h}</th>`).join('')}</tr></thead>
      <tbody>${tbody}</tbody></table>
      <p class="meta">Generado por NEXO · ${new Date().toLocaleString('es-CO')}</p>
      </body></html>`;
    const blob = new Blob([html], { type: 'text/html;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const printWin = window.open(url, '_blank');
    if (printWin) {
      printWin.onload = () => { printWin.print(); };
      setTimeout(() => URL.revokeObjectURL(url), 30000);
    }
  };

  const handleDownload = async () => {
    if (!sub) return;
    setLoading(true);
    try {
      const rows = await fetchExportData();
      // BUG-10 FIX: verificar filas antes de llamar download — evita toast success falso
      if (rows.length === 0) {
        setToast({ type: 'error', message: 'No hay datos para exportar en este período' });
        return;
      }
      if (format === 'Excel') downloadExcel(rows);
      else if (format === 'PDF') downloadPDF(rows);
      setToast({ type: 'success', message: `${format} de "${sub}" generado correctamente` });
    } catch (e) {
      setToast({ type: 'error', message: e.message || 'Error generando el archivo. Vuelve a intentarlo.' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="space-y-5">
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-sm font-black uppercase tracking-wider text-slate-800 dark:text-white">Descargar {format}</h3>
          <p className="text-[10px] text-slate-400 mt-0.5">{module.title}</p>
        </div>
        <button onClick={onClose} className="p-1 text-slate-400 hover:text-slate-700 dark:hover:text-white"><X size={16} /></button>
      </div>

      <div>
        <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Subsección</label>
        <div className="space-y-1 max-h-40 overflow-auto pr-1">
          {module.subdivisions.map(s => (
            <button key={s} onClick={() => setSub(s)}
              className={`w-full text-left px-3 py-2 text-xs rounded transition-colors ${sub === s ? 'bg-[#003366] text-white font-semibold' : 'bg-slate-50 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700'}`}>
              {s}
            </button>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Desde</label>
          <input type="date" value={from} onChange={e => setFrom(e.target.value)}
            className="w-full px-3 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30" />
        </div>
        <div>
          <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Hasta</label>
          <input type="date" value={to} onChange={e => setTo(e.target.value)}
            className="w-full px-3 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30" />
        </div>
      </div>

      <button onClick={handleDownload} disabled={!sub || loading}
        className="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-[#003366] hover:bg-[#002855] text-white text-xs font-bold uppercase tracking-wider rounded transition-colors disabled:opacity-60">
        {loading ? <Loader2 size={14} className="animate-spin" /> : <Download size={14} />}
        Descargar {format}
      </button>

      {/* Toast */}
      <AnimatePresence>
        {toast && (
          <motion.div
            initial={{ opacity: 0, y: 10 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: 10 }}
            transition={{ duration: 0.2 }}
            className={`mt-3 px-4 py-2.5 rounded-lg text-xs font-semibold flex items-center gap-2 ${
              toast.type === 'success'
                ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
                : 'bg-red-50 text-red-700 border border-red-200'
            }`}
          >
            {toast.type === 'success' ? <CheckCircle2 size={14} /> : <AlertTriangle size={14} />}
            {toast.message}
          </motion.div>
        )}
      </AnimatePresence>
    </div>
  );
}

export default Audit;
