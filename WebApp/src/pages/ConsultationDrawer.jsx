import { useState, useEffect, useRef } from 'react';
import {
  Search, Activity, X, Loader2, CalendarDays, Filter, Eye, AlertTriangle
} from 'lucide-react';
import { motion } from 'framer-motion';
import { studentsApi } from '../api/students';
import { TrackingModal } from './TrackingModal';

const MONTHS_ES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

function fmt12h(iso) {
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const day = d.getDate();
  const month = MONTHS_ES[d.getMonth()];
  const year = d.getFullYear();
  let h = d.getHours();
  const m = String(d.getMinutes()).padStart(2, '0');
  const ampm = h >= 12 ? 'pm' : 'am';
  h = h % 12 || 12;
  return `${day} ${month} ${year}, ${h}:${m} ${ampm}`;
}

function fmtShortDate(iso) {
  return fmt12h(iso);
}

function fmtShortDateOnly(iso) {
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const day = d.getDate();
  const month = MONTHS_ES[d.getMonth()];
  const year = d.getFullYear();
  return `${day} ${month} ${year}`;
}

function formatCellValue(key, value) {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'Sí' : 'No';
  const sk = String(key).toLowerCase();
  if (sk.includes('timestamp') || sk.includes('_at') || sk.includes('time') || sk.includes('detected') || sk.includes('emitted') || sk.includes('created')) {
    const d = new Date(value);
    if (!isNaN(d)) return fmtShortDate(value);
  }
  if (sk.includes('date')) {
    const d = new Date(value);
    if (!isNaN(d)) return fmtShortDateOnly(value);
  }
  // Humanizar enums conocidos
  const sv = String(value).trim();
  if (sv === 'en proceso') {
    return (
      <span className="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-amber-50 text-amber-600 border border-amber-200">
        En Proceso
      </span>
    );
  }
  if (sv === 'resuelto') {
    return (
      <span className="inline-flex items-center px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-widest bg-emerald-50 text-emerald-600 border border-emerald-200">
        Resuelto
      </span>
    );
  }
  if (ENUM_LABELS[sv]) return ENUM_LABELS[sv];
  if (ENUM_LABELS[sv.toUpperCase()]) return ENUM_LABELS[sv.toUpperCase()];
  if (sv.length > 100) return sv.slice(0, 100) + '…';
  return sv;
}

const COLUMN_LABELS = {
  first_name: 'Nombre', last_name: 'Apellido', document_number: 'Documento',
  group_name: 'Grupo', event_timestamp: 'Fecha/Hora', event_type: 'Tipo',
  event_result: 'Resultado', detected_at: 'Detectado', incident_type: 'Incidente',
  permiso_type: 'Tipo', permiso_at: 'Fecha', reason: 'Motivo',
  alert_type: 'Alerta', alert_at: 'Fecha', absent_since: 'Desde',
  last_entry: 'Último ingreso', guardian_name: 'Acudiente',
  guardian_phone: 'Teléfono', status: 'Estado', message: 'Mensaje',
};

function humanizeColumn(key) {
  return COLUMN_LABELS[key] || key.replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase());
}

const EXCLUDE_COLS = [
  'school_id','student_id','guardian_id','incident_id','alert_id','metadata_json','command_payload',
  'sync_hash','event_signature','biometric_hash','device_id','event_id','log_id','audit_id',
  'assignment_id','schedule_id','classroom_id','report_export_id','command_id','twilio_message_id',
  'relationship_id','staff_record_id','previous_data','new_data',
];

// Enums conocidos a español — listado exhaustivo de valores reales del backend
const ENUM_LABELS = {
  // Tipos de eventos biométricos
  CHECK_IN: 'Entrada', CHECK_OUT: 'Salida', LATE_ARRIVAL: 'Llegada tarde',
  EARLY_EXIT: 'Salida anticipada', EARLY_DEPARTURE: 'Salida anticipada',
  INGRESO_NORMAL: 'Ingreso normal', INGRESO_TARDE: 'Ingreso tarde',
  WRONG_CLASSROOM: 'Salón incorrecto', EVASION_INTERNA: 'Evasión interna',
  // Resultados de reconocimiento
  MATCH: 'Coincidencia', NO_MATCH: 'Sin coincidencia',
  PARTIAL_MATCH: 'Coincidencia parcial', SPOOF_DETECTED: 'Intento de fraude',
  LIVENESS_FAIL: 'Prueba de vida fallida', TIMEOUT: 'Tiempo agotado',
  // Estados generales
  SUCCESS: 'Exitoso', FAILED: 'Fallido', PENDING: 'Pendiente',
  APPROVED: 'Aprobado', REJECTED: 'Rechazado', SENT: 'Enviado',
  DELIVERED: 'Entregado', UNDELIVERED: 'No entregado', READ: 'Leído',
  // Alertas / SOS
  SOS_WEBAPP: 'Alerta SOS', SOS_DEVICE: 'Alerta SOS (dispositivo)',
  SOS_ALERT: 'Alerta SOS', PANIC: 'Pánico', ALARM: 'Alarma',
  // Incidentes
  INASISTENCIA: 'Inasistencia', UNAUTHORIZED_ABSENCE: 'Inasistencia',
  CITACION: 'Citación a acudiente', CITACION_CONFIRMADA: 'Citación confirmada',
  CITACION_REAGENDADA: 'Citación reagendada',
  AUTORIZAR_SALIDA: 'Salida autorizada', AUTORIZAR: 'Salida autorizada',
  PERMISO: 'Permiso de salida', PEDAGOGICA: 'Salida pedagógica',
  SOLICITUD: 'Solicitud interna', DAÑO: 'Daño físico',
  INCIDENTE: 'Incidente', HORARIO: 'Cambio de horario',
  NOTIFY_ROLE: 'Notificación interna',
  // Tipos de salida / permiso
  class: 'Salida de clase', school: 'Salida del colegio', trip: 'Salida pedagógica',
  // Dirección mensajes
  INBOUND: 'Entrante', OUTBOUND: 'Saliente',
  // Roles
  RECTOR: 'Rector', SUPER_RECTOR: 'Super Rector', COORDINADOR: 'Coordinador',
  DOCENTE: 'Docente', SECRETARIA: 'Secretaria', PORTERO: 'Portero',
  AUXILIAR: 'Auxiliar', PSICORIENTADOR: 'Psicorientador',
  // Niveles de riesgo
  CRITICAL: 'Crítico', HIGH: 'Alto', MEDIUM: 'Medio', LOW: 'Bajo',
  // Boolean-like strings
  TRUE: 'Sí', FALSE: 'No',
  // Tipos de cita / motivo
  COMPORTAMIENTO: 'Comportamiento', ACADEMICO: 'Académico', SALUD: 'Salud',
  DISCIPLINA: 'Disciplina', OTRO: 'Otro',
};

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

/* ── SearchableSelect (patrón AuditDrawer del rector) ─────────────────── */

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

// ── Teacher Query Panel (patrón AuditDrawer del rector) ──────────────────────

const TeacherQueryPanel = ({
  item, groups, selectedGroup, setSelectedGroup,
  selectedStudent, setSelectedStudent,
  fromDate, setFromDate, toDate, setToDate, onQuery, loadingData, hasQueried,
  dynamicData, error
}) => {
  const [students, setStudents] = useState([]);
  const [studentsLoading, setStudentsLoading] = useState(false);
  const rows = dynamicData;
  const visibleKeys = rows.length > 0
    ? Object.keys(rows[0]).filter(k => !EXCLUDE_COLS.includes(k))
    : [];

  // Cargar estudiantes del grupo seleccionado
  useEffect(() => {
    if (!selectedGroup) { setStudents([]); setSelectedStudent(''); return; }
    setStudentsLoading(true);
    studentsApi.getAll({ limit: 100, group_name: selectedGroup })
      .then(res => {
        setStudents(res.students || []);
      })
      .catch(() => setStudents([]))
      .finally(() => setStudentsLoading(false));
  }, [selectedGroup]);

  const groupOptions = groups.map(g => ({
    id: g.name || g.group_name,
    name: `${g.name || g.group_name}${g.grade_level ? ` (${g.grade_level})` : ''}`
  }));

  const studentOptions = [...students]
    .sort((a, b) => {
      const la = (a.last_name || '').toLowerCase();
      const lb = (b.last_name || '').toLowerCase();
      if (la !== lb) return la.localeCompare(lb, 'es');
      return (a.first_name || '').toLowerCase().localeCompare((b.first_name || '').toLowerCase(), 'es');
    })
    .map(s => ({
      id: String(s.id || s.student_id),
      name: `${s.last_name || ''} ${s.first_name || ''}`.trim()
    }));

  return (
    <div className="flex-1 flex flex-col p-0 overflow-hidden bg-white dark:bg-slate-900">
      {/* Filter Panel — SIEMPRE visible */}
      <div className="shrink-0 p-5 border-b border-slate-100 dark:border-slate-800 space-y-4">
        <div className="flex items-center gap-2 mb-1">
          <Filter size={14} strokeWidth={2} className="text-slate-400" />
          <span className="text-[10px] font-bold uppercase tracking-widest text-slate-400">Filtros de consulta</span>
        </div>

        <SearchableSelect
          label="Grupo académico"
          placeholder={groups.length === 0 ? 'No hay grupos asignados' : 'Seleccionar grupo…'}
          options={groupOptions}
          value={selectedGroup}
          onChange={v => { setSelectedGroup(v); setSelectedStudent(''); }}
        />

        <SearchableSelect
          label="Estudiante"
          placeholder={!selectedGroup ? 'Primero seleccione un grupo' : (studentsLoading ? 'Cargando estudiantes…' : 'Todos los estudiantes del grupo')}
          options={studentOptions}
          value={selectedStudent}
          onChange={v => setSelectedStudent(v)}
          loading={studentsLoading}
        />

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Desde</label>
            <div className="relative">
              <CalendarDays size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
              <input type="date" value={fromDate} onChange={e => setFromDate(e.target.value)}
                className="w-full pl-8 pr-2 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30 focus:border-[#003366]" />
            </div>
          </div>
          <div>
            <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Hasta</label>
            <div className="relative">
              <CalendarDays size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
              <input type="date" value={toDate} onChange={e => setToDate(e.target.value)}
                className="w-full pl-8 pr-2 py-2 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30 focus:border-[#003366]" />
            </div>
          </div>
        </div>

        <button
          onClick={onQuery}
          disabled={!selectedGroup || loadingData}
          className="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-[#003366] hover:bg-[#002855] text-white text-xs font-bold uppercase tracking-wider rounded transition-colors disabled:opacity-60"
        >
          {loadingData ? <Loader2 size={14} className="animate-spin" /> : <Eye size={14} />}
          Consultar
        </button>
      </div>

      {/* Results */}
      <div className="flex-1 overflow-auto">
        {error && !loadingData && (
          <div className="flex flex-col items-center justify-center h-64 gap-4 px-6">
            <div className="w-14 h-14 rounded-full bg-red-50 flex items-center justify-center border border-red-100">
              <AlertTriangle size={24} strokeWidth={1.5} className="text-red-400" />
            </div>
            <div className="text-center space-y-1">
              <p className="text-xs font-bold uppercase tracking-widest text-red-400">Error de consulta</p>
              <p className="text-xs text-red-400 max-w-[260px] leading-relaxed">{error}</p>
            </div>
          </div>
        )}

        {!error && !hasQueried && !loadingData && (
          <div className="flex flex-col items-center justify-center h-64 gap-4 px-6">
            <div className="w-14 h-14 rounded-full bg-slate-50 flex items-center justify-center border border-slate-100">
              <Activity size={24} strokeWidth={1.5} className="text-slate-300" />
            </div>
            <div className="text-center space-y-1">
              <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Sin registros</p>
              <p className="text-xs text-slate-400 max-w-[260px] leading-relaxed">
                Seleccione un grupo y un rango de fechas, luego presione <strong>Consultar</strong>.
              </p>
            </div>
          </div>
        )}

        {!error && loadingData && (
          <div className="flex flex-col items-center justify-center h-64 gap-3">
            <Loader2 size={28} strokeWidth={1.5} className="text-[#003366] animate-spin" />
            <p className="text-xs text-slate-400 font-medium">Consultando registros…</p>
          </div>
        )}

        {!error && !loadingData && hasQueried && rows.length === 0 && (
          <div className="flex flex-col items-center justify-center h-64 gap-4 px-6">
            <div className="w-14 h-14 rounded-full bg-slate-50 flex items-center justify-center border border-slate-100">
              <Activity size={24} strokeWidth={1.5} className="text-slate-300" />
            </div>
            <div className="text-center space-y-1">
              <p className="text-xs font-bold uppercase tracking-widest text-slate-400">Sin registros</p>
              <p className="text-xs text-slate-400 max-w-[260px] leading-relaxed">
                No se encontraron registros para <strong>{item}</strong> en el grupo y período seleccionado.
              </p>
            </div>
          </div>
        )}

        {!error && !loadingData && hasQueried && rows.length > 0 && (
          <div className="p-5 space-y-5">
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
                            {formatCellValue(k, row[k])}
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
          </div>
        )}
      </div>
    </div>
  );
};

// ── Main Drawer ──────────────────────────────────────────────────────────────

export const ConsultationDrawer = ({
  item, riskStudents, dynamicData, dynamicColumns, loadingData,
  isTeacherModule, hasQueried, groups, selectedGroup, setSelectedGroup,
  selectedStudent, setSelectedStudent,
  fromDate, setFromDate, toDate, setToDate, onQuery, onClose, error,
  executeQuery
}) => {
  const keys = Object.keys(dynamicColumns);
  const [trackingModalOpen, setTrackingModalOpen] = useState(false);
  const [selectedTrackingTarget, setSelectedTrackingTarget] = useState(null);

  const openTracking = (studentId, studentName, trackingId = null, metadata = null) => {
    setSelectedTrackingTarget({ studentId, studentName, trackingId, metadata });
    setTrackingModalOpen(true);
  };

  return (
    <>
      <motion.div key="ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }} className="fixed z-40"
        style={{ top: '52px', left: 0, right: 0, bottom: 0, backgroundColor: 'rgba(2,6,23,0.45)' }}
        onClick={onClose} />
      <motion.div key="dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
        transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
        className="fixed right-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
        style={{ top: '56px', bottom: 0, maxWidth: '640px', borderLeft: '1.5px solid #E2E8F0' }}
      >
        {/* Header */}
        <div className="shrink-0 flex items-center justify-between px-6 py-4" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-9 h-9" style={{ backgroundColor: 'rgba(0,51,102,0.08)' }}>
              <Search size={16} strokeWidth={2} style={{ color: '#003366' }} />
            </div>
            <div>
              <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: '#1E293B' }}>{item}</p>
              <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                {isTeacherModule ? 'Consulta histórica por grupo' : 'Consulta de Datos Institucionales'}
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <X size={18} strokeWidth={2} />
          </button>
        </div>

        {/* Body */}
        {isTeacherModule ? (
          <TeacherQueryPanel
            item={item} groups={groups} selectedGroup={selectedGroup} setSelectedGroup={setSelectedGroup}
            selectedStudent={selectedStudent} setSelectedStudent={setSelectedStudent}
            fromDate={fromDate} setFromDate={setFromDate} toDate={toDate} setToDate={setToDate}
            onQuery={onQuery} loadingData={loadingData} hasQueried={hasQueried}
            dynamicData={dynamicData} error={error}
          />
        ) : (
          <div className="flex-1 overflow-y-auto p-6">
            {loadingData ? (
              <div className="flex flex-col items-center justify-center h-full py-20 gap-4">
                <Loader2 size={32} className="animate-spin text-[#003366] dark:text-slate-400" />
                <p style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.15em', color: '#94A3B8', textTransform: 'uppercase' }}>
                  Cargando datos...
                </p>
              </div>
            ) : item === 'Análisis de Riesgo' && riskStudents.length > 0 ? (
              <div className="overflow-x-auto" style={{ border: '1.5px solid #E2E8F0' }}>
                <table className="w-full min-w-[440px]">
                  <thead>
                    <tr style={{ backgroundColor: '#F8FAFC', borderBottom: '1.5px solid #E2E8F0' }}>
                      {['Estudiante', 'Grupo', 'Score', 'Nivel', 'Acción'].map(h => (
                        <th key={h} className="px-4 py-3 text-left"
                          style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-slate-900">
                    {[...riskStudents]
                      .sort((a, b) => {
                        const la = (a.last_name || '').toLowerCase();
                        const lb = (b.last_name || '').toLowerCase();
                        if (la !== lb) return la.localeCompare(lb, 'es');
                        return (a.first_name || '').toLowerCase().localeCompare((b.first_name || '').toLowerCase(), 'es');
                      })
                      .map((s, i, arr) => (
                      <tr key={s.student_id} className="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
                        style={{ borderBottom: i < arr.length - 1 ? '1px solid #F1F5F9' : 'none' }}>
                        <td className="px-4 py-4">
                          <div className="flex items-center gap-2">
                            <div className="w-7 h-7 shrink-0 flex items-center justify-center text-xs font-black text-white"
                                 style={{ backgroundColor: '#003366' }}>
                              {(s.last_name || s.first_name || '?').charAt(0)}
                            </div>
                            <span className="text-sm font-bold text-slate-800 dark:text-slate-200">{s.last_name} {s.first_name}</span>
                          </div>
                        </td>
                        <td className="px-4 py-4"><span className="text-sm font-semibold text-slate-500 dark:text-slate-400">{s.group_name}</span></td>
                        <td className="px-4 py-4">
                          <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '13px', fontWeight: 700, color: s.risk_score >= 85 ? '#DC2626' : '#D97706' }}>
                            {s.risk_score}
                          </span>
                        </td>
                        <td className="px-4 py-4"><RiskBadge level={s.risk_level} /></td>
                        <td className="px-4 py-4 text-right">
                          <button 
                            onClick={() => openTracking(s.student_id, `${s.last_name} ${s.first_name}`)}
                            className="text-[10px] font-bold uppercase tracking-widest text-[#003366] hover:underline"
                          >
                            Empezar Seguimiento
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : item !== 'Análisis de Riesgo' && dynamicData.length > 0 ? (
              <div className="overflow-x-auto" style={{ border: '1.5px solid #E2E8F0' }}>
                <table className="w-full min-w-[500px]">
                  <thead>
                    <tr style={{ backgroundColor: '#F8FAFC', borderBottom: '1.5px solid #E2E8F0' }}>
                      {keys.map(k => (
                        <th key={k} className="px-4 py-3 text-left"
                          style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                          {dynamicColumns[k]}
                        </th>
                      ))}
                      {['Seguimiento Estudiantil', 'Alertas'].includes(item) && (
                        <th className="px-4 py-3 text-right" style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                          Acción
                        </th>
                      )}
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-slate-900">
                    {dynamicData.map((row, i) => (
                      <tr key={i} className="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
                        style={{ borderBottom: i < dynamicData.length - 1 ? '1px solid #F1F5F9' : 'none' }}>
                        {keys.map(k => (
                          <td key={k} className="px-4 py-3 text-[11px] text-slate-700 dark:text-slate-300 whitespace-nowrap max-w-[200px] truncate">
                            {formatCellValue(k, row[k])}
                          </td>
                        ))}
                        {['Seguimiento Estudiantil', 'Alertas'].includes(item) && row.student_id && (
                          <td className="px-4 py-3 text-right">
                            <button 
                              onClick={() => openTracking(row.student_id, `${row.last_name} ${row.first_name}`, row.tracking_id, typeof row.metadata_json === 'string' ? JSON.parse(row.metadata_json) : row.metadata_json)}
                              className="text-[10px] font-bold uppercase tracking-widest text-[#003366] hover:bg-[#003366]/10 px-2 py-1 rounded transition-colors"
                            >
                              Ver
                            </button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <div className="flex flex-col items-center justify-center h-full py-20 gap-4">
                <Activity size={32} strokeWidth={1} className="text-slate-200 dark:text-slate-700" />
                <div className="text-center space-y-1">
                  <p style={{ fontSize: '11px', fontWeight: 700, letterSpacing: '0.15em', color: '#CBD5E1', textTransform: 'uppercase' }}>Sin datos disponibles</p>
                  <p style={{ fontSize: '11px', color: '#CBD5E1' }} className="max-w-xs">No se encontraron registros para este módulo.</p>
                </div>
              </div>
            )}
          </div>
        )}
      </motion.div>

      <AnimatePresence>
        {trackingModalOpen && selectedTrackingTarget && (
          <TrackingModal
            trackingId={selectedTrackingTarget.trackingId}
            studentId={selectedTrackingTarget.studentId}
            studentName={selectedTrackingTarget.studentName}
            metadata={selectedTrackingTarget.metadata}
            onClose={() => setTrackingModalOpen(false)}
            onRefresh={() => {
              if (typeof executeQuery === 'function') {
                executeQuery();
              } else if (typeof onQuery === 'function') {
                onQuery();
              }
            }}
          />
        )}
      </AnimatePresence>
    </>
  );
};
