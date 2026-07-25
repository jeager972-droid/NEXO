/**
 * SCR-AUD-01 Audit
 * Panel de auditoría institucional: módulos de reporte, filtros y exportación.
 */
import { useState, useEffect, useRef } from 'react';
import { useSearchParams, Navigate } from 'react-router-dom';
import {
  FileText, Users, ShieldAlert, MessageSquare, Activity, Database,
  Search, X, Download, Loader2, CalendarDays, Filter, ChevronRight
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '../hooks/useAuth';
import { auditApi } from '../api/audit';
import { ROLES } from '../config/roles';
import { Section, Surface } from '../components/ui/Surface';
import { Card } from '../components/ui/Card';
import { Input } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Select } from '../components/ui/Select';
import { Badge } from '../components/ui/Badge';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton } from '../components/ui/Skeleton';

const ADMIN_ROLES = [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.SECRETARIA];

const MODULES = [
  { title: 'Asistencia', icon: Users, subdivisions: ['Inasistencias', 'Llegadas tarde', 'Evasión interna'] },
  { title: 'Disciplina', icon: ShieldAlert, subdivisions: ['Intentos salón incorrecto', 'Spam biométrico', 'Reporte disciplinario'] },
  { title: 'Permisos', icon: FileText, subdivisions: ['Salidas clase', 'Salidas colegio', 'Salidas pedagógicas', 'Retornos pendientes', 'Historial permisos'] },
  { title: 'SOS y seguridad', icon: MessageSquare, subdivisions: ['Alertas SOS emitidas', 'Evasiones internas'] },
  { title: 'Operaciones', icon: Activity, subdivisions: ['Permisos emitidos'] },
];

const DRAWER_CONFIG = {
  'Inasistencias':            { api: auditApi.getAttendanceAbsences,        needsDates: true, needsGroup: true, needsStudent: true },
  'Llegadas tarde':           { api: auditApi.getAttendanceLates,           needsDates: true, needsGroup: true, needsStudent: true },
  'Evasión interna':          { api: auditApi.getAttendanceEvasion,         needsDates: true, needsGroup: true, needsStudent: true },
  'Intentos salón incorrecto':{ api: auditApi.getDisciplineWrongClassroom,  needsDates: true, needsGroup: true, needsStudent: true },
  'Spam biométrico':          { api: auditApi.getDisciplineBiometricSpam,   needsDates: true, needsGroup: true, needsStudent: true },
  'Reporte disciplinario':    { api: auditApi.getDisciplineReports,         needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas clase':            { api: auditApi.getPermissionsClassExits,     needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas colegio':          { api: auditApi.getPermissionsSchoolExits,    needsDates: true, needsGroup: true, needsStudent: true },
  'Salidas pedagógicas':      { api: auditApi.getPermissionsPedagogical,    needsDates: true, needsGroup: true, needsStudent: true },
  'Retornos pendientes':      { api: auditApi.getPermissionsPendingReturns, needsDates: true, needsGroup: true, needsStudent: true },
  'Historial permisos':       { api: auditApi.getPermissionsHistory,        needsDates: true },
  'Permisos emitidos':        { api: auditApi.getTeacherPermissions,        needsDates: true, needsStaff: true },
  'Alertas SOS emitidas':     { api: auditApi.getSosAlerts,                 needsDates: true, needsGroup: true, needsStudent: true },
  'Evasiones internas':       { api: auditApi.getAttendanceEvasion,         needsDates: true, needsGroup: true, needsStudent: true },
};

const EXCLUDE_COLS = ['student_id', 'id', 'metadata', 'raw'];
const humanize = (k) => String(k).replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());
const fmtValue = (v) => v === null || v === undefined ? '—' : String(v);

const SearchableSelect = ({ label, options, value, onChange, placeholder, loading }) => {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const ref = useRef(null);

  useEffect(() => {
    const handleClickOutside = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const filtered = q.trim() === '' ? options : options.filter((o) => String(o.name || '').toLowerCase().includes(q.toLowerCase()));
  const selected = options.find((o) => o.id === value);

  return (
    <div className="relative" ref={ref}>
      {label && <label className="block text-label text-[var(--nx-text)] mb-1.5">{label}</label>}
      <button type="button" onClick={() => setOpen(!open)} className="flex w-full items-center justify-between rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-3 py-2.5 text-left text-body text-[var(--nx-text)] hover:border-[var(--nx-text-muted)]">
        <span className="truncate">{selected ? selected.name : loading ? 'Cargando…' : placeholder}</span>
        <Search size={14} className="text-[var(--nx-text-muted)]" />
      </button>
      {open && (
        <div className="absolute z-20 mt-1 w-full rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-high max-h-60 overflow-auto">
          <div className="sticky top-0 border-b border-[var(--nx-border)] bg-[var(--nx-surface)] p-2">
            <Input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar…" leftIcon={<Search size={14} className="text-[var(--nx-text-muted)]" />} />
          </div>
          {filtered.length === 0 && <div className="px-3 py-2 text-body-sm text-[var(--nx-text-muted)]">Sin coincidencias</div>}
          {filtered.map((o) => (
            <button key={o.id} onClick={() => { onChange(o.id); setOpen(false); setQ(''); }} className={`w-full px-3 py-2 text-left text-body-sm truncate transition-colors ${o.id === value ? 'bg-[color-mix(in_oklch,var(--nx-accent)_10%,transparent)] text-[var(--nx-accent)]' : 'text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)]'}`}>{o.name}</button>
          ))}
        </div>
      )}
    </div>
  );
};

const AuditDrawer = ({ activeSub, onClose }) => {
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
    setMetaLoading(true);
    Promise.all([
      config?.needsGroup || config?.needsStudent ? auditApi.getGroups().then((r) => setGroups(r.data || [])).catch(() => {}) : Promise.resolve(),
      config?.needsStaff ? auditApi.getStaff().then((r) => setStaff(r.data || [])).catch(() => {}) : Promise.resolve(),
    ]).finally(() => setMetaLoading(false));
  }, [activeSub, config]);

  useEffect(() => {
    if (!filters.groupId) { setStudents([]); return; }
    auditApi.getGroupStudents(filters.groupId).then((r) => setStudents(r.data || [])).catch(() => setStudents([]));
  }, [filters.groupId]);

  const handleSearch = async () => {
    if (!config) { setError('Submódulo no configurado'); return; }
    setLoading(true);
    setError(null);
    try {
      const res = await config.api(filters);
      setData(res.data || res.rows || []);
    } catch (e) {
      setError(e?.response?.data?.message || e.message || 'Error al consultar');
      setData([]);
    } finally {
      setLoading(false);
    }
  };

  const rows = Array.isArray(data) ? data : [];
  const visibleKeys = rows.length > 0 ? Object.keys(rows[0]).filter((k) => !EXCLUDE_COLS.includes(k)) : [];
  const filteredRows = filters.q.trim() ? rows.filter((r) => Object.values(r).some((v) => String(v).toLowerCase().includes(filters.q.toLowerCase()))) : rows;

  return (
    <>
      <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={onClose} className="fixed inset-0 z-40 bg-[color-mix(in_oklch,var(--nx-text)_45%,transparent)]" />
      <motion.div initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }} className="fixed right-0 top-0 z-50 flex h-full w-full max-w-[640px] flex-col overflow-hidden border-l border-[var(--nx-border)] bg-[var(--nx-surface)]">
        <div className="flex shrink-0 items-center justify-between border-b border-[var(--nx-border)] px-6 py-4">
          <p className="text-h3 text-[var(--nx-text)]">{activeSub}</p>
          <button onClick={onClose} className="p-2 rounded-control hover:bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]"><X size={20} /></button>
        </div>

        <Surface className="shrink-0 border-b border-[var(--nx-border)] p-5 space-y-4 rounded-none">
          <div className="flex items-center gap-2 text-label text-[var(--nx-text-muted)] uppercase"><Filter size={14} /> Filtros</div>
          {config?.needsDates && (
            <div className="grid grid-cols-2 gap-3">
              <Input type="date" label="Desde" value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} />
              <Input type="date" label="Hasta" value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} />
            </div>
          )}
          {(config?.needsGroup || config?.needsStudent) && (
            <SearchableSelect label="Grupo" placeholder="Seleccionar grupo…" options={groups.map((g) => ({ id: g.id || g.group_id || g.name, name: g.name || g.group_name }))} value={filters.groupId} onChange={(v) => setFilters((f) => ({ ...f, groupId: v, studentId: '' }))} loading={metaLoading} />
          )}
          {config?.needsStudent && (
            <SearchableSelect label="Estudiante" placeholder={filters.groupId ? 'Seleccionar estudiante…' : 'Primero seleccione un grupo'} options={students.map((s) => ({ id: s.student_id, name: `${s.last_name} ${s.first_name}` }))} value={filters.studentId} onChange={(v) => setFilters((f) => ({ ...f, studentId: v }))} loading={metaLoading} />
          )}
          {config?.needsStaff && (
            <SearchableSelect label="Personal" placeholder="Seleccionar personal…" options={staff.map((u) => ({ id: u.user_id, name: `${u.last_name} ${u.first_name} — ${u.role_name}` }))} value={filters.staffId} onChange={(v) => setFilters((f) => ({ ...f, staffId: v }))} loading={metaLoading} />
          )}
          <Input placeholder="Filtrar resultados…" value={filters.q} onChange={(e) => setFilters((f) => ({ ...f, q: e.target.value }))} leftIcon={<Search size={14} className="text-[var(--nx-text-muted)]" />} />
          <Button onClick={handleSearch} loading={loading} leftIcon={<CalendarDays size={16} />}>Consultar</Button>
        </Surface>

        <div className="flex-1 overflow-auto p-5">
          {error ? <EmptyState icon={<X size={32} className="text-[var(--nx-danger)]" />} title="Error" description={error} /> :
           loading ? <div className="flex flex-col items-center gap-3 py-20"><Loader2 size={28} className="animate-spin text-[var(--nx-accent)]" /><p className="text-body-sm text-[var(--nx-text-muted)]">Consultando…</p></div> :
           filteredRows.length === 0 ? <EmptyState icon={<FileText size={32} className="text-[var(--nx-border)]" />} title="Sin registros" description="Ajusta los filtros y consulta." /> : (
            <Surface className="overflow-x-auto">
              <table className="w-full min-w-[600px]">
                <thead>
                  <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                    {visibleKeys.map((k) => <th key={k} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)] whitespace-nowrap">{humanize(k)}</th>)}
                  </tr>
                </thead>
                <tbody className="divide-y divide-[var(--nx-border)]">
                  {filteredRows.map((row, i) => (
                    <tr key={i} className="hover:bg-[var(--nx-surface-subtle)]">
                      {visibleKeys.map((k) => <td key={k} className="px-4 py-3 text-body-sm text-[var(--nx-text)] whitespace-nowrap max-w-[200px] truncate">{fmtValue(row[k])}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </Surface>
          )}
        </div>
      </motion.div>
    </>
  );
};

const ExportModalContent = ({ onClose }) => {
  const [sub, setSub] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [loading, setLoading] = useState(false);
  const [toast, setToast] = useState(null);

  const handleExport = async (format) => {
    setLoading(true);
    setToast(null);
    try {
      const res = format === 'csv' ? await auditApi.exportConsolidated(sub, from, to) : await auditApi.exportConsolidated(sub, from, to);
      const rows = Array.isArray(res) ? res : [];
      if (rows.length === 0) { setToast({ type: 'error', message: 'Sin datos para exportar' }); setLoading(false); return; }
      const headers = Object.keys(rows[0]);
      const csv = [headers.join(','), ...rows.map((r) => headers.map((h) => `"${String(r[h] ?? '').replace(/"/g, '""')}"`).join(','))].join('\n');
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url; a.download = `nexo-auditoria-${sub || 'todos'}.csv`; a.click(); URL.revokeObjectURL(url);
      setToast({ type: 'success', message: 'Exportación iniciada' });
    } catch (e) {
      setToast({ type: 'error', message: e.message || 'Error al exportar' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="space-y-4">
      <p className="text-h3 text-[var(--nx-text)]">Exportar consolidado</p>
      <Input label="Submódulo (opcional)" value={sub} onChange={(e) => setSub(e.target.value)} />
      <div className="grid grid-cols-2 gap-3">
        <Input type="date" label="Desde" value={from} onChange={(e) => setFrom(e.target.value)} />
        <Input type="date" label="Hasta" value={to} onChange={(e) => setTo(e.target.value)} />
      </div>
      {toast && <p className={`text-body-sm ${toast.type === 'success' ? 'text-[var(--nx-success)]' : 'text-[var(--nx-danger)]'}`}>{toast.message}</p>}
      <div className="flex gap-3">
        <Button variant="secondary" onClick={onClose}>Cancelar</Button>
        <Button onClick={() => handleExport('csv')} loading={loading} leftIcon={<Download size={16} />}>Descargar CSV</Button>
      </div>
    </div>
  );
};

const Audit = () => {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const [activeSub, setActiveSub] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [exportModal, setExportModal] = useState(false);

  useEffect(() => {
    const sub = searchParams.get('sub');
    if (sub) { setActiveSub(sub); setSearchParams({}, { replace: true }); }
  }, [searchParams, setSearchParams]);

  if (!ADMIN_ROLES.includes(user?.role_name || user?.role)) {
    return <Navigate to="/" replace />;
  }

  const filtered = MODULES.map((m) => ({ ...m, subdivisions: m.subdivisions.filter((s) => s.toLowerCase().includes(searchTerm.toLowerCase())) })).filter((m) => m.subdivisions.length > 0 || !searchTerm);

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <Section title="Auditoría" subtitle="Módulos de reporte institucional" />
        <Button variant="secondary" onClick={() => setExportModal(true)} leftIcon={<Download size={16} />}>Exportar</Button>
      </div>
      <Input placeholder="Buscar submódulo…" value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />} />
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {filtered.map((mod, idx) => (
          <Card key={idx} className="p-5">
            <div className="flex items-center gap-3 mb-4">
              <div className="flex h-10 w-10 items-center justify-center rounded-control bg-[color-mix(in_oklch,var(--nx-accent)_10%,transparent)] text-[var(--nx-accent)]">
                <mod.icon size={20} />
              </div>
              <p className="text-h3 text-[var(--nx-text)]">{mod.title}</p>
            </div>
            <div className="space-y-2">
              {mod.subdivisions.map((sub) => (
                <button key={sub} onClick={() => setActiveSub(sub)} className="group flex w-full items-center justify-between rounded-control px-3 py-2 text-left text-body text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)]">
                  <span>{sub}</span>
                  <ChevronRight size={14} className="text-[var(--nx-text-muted)] group-hover:text-[var(--nx-accent)]" />
                </button>
              ))}
            </div>
          </Card>
        ))}
      </div>

      <AnimatePresence>
        {activeSub && <AuditDrawer activeSub={activeSub} onClose={() => setActiveSub(null)} />}
      </AnimatePresence>

      <AnimatePresence>
        {exportModal && (
          <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-[color-mix(in_oklch,var(--nx-text)_45%,transparent)]">
            <motion.div initial={{ opacity: 0, scale: 0.95 }} animate={{ opacity: 1, scale: 1 }} exit={{ opacity: 0, scale: 0.95 }} className="w-full max-w-md rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] p-6 shadow-high">
              <ExportModalContent onClose={() => setExportModal(false)} />
            </motion.div>
          </div>
        )}
      </AnimatePresence>
    </div>
  );
};

export default Audit;
