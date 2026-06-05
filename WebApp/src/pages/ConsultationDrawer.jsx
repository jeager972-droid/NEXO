import { useState, useEffect } from 'react';
import {
  Search, Activity, X, Loader2, CalendarDays, Filter, Eye
} from 'lucide-react';
import { motion } from 'framer-motion';

const MONTHS_ES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

function fmtShortDate(iso) {
  const d = new Date(iso);
  if (isNaN(d)) return iso;
  const day = d.getDate();
  const month = MONTHS_ES[d.getMonth()];
  const year = d.getFullYear();
  const hh = String(d.getHours()).padStart(2, '0');
  const mm = String(d.getMinutes()).padStart(2, '0');
  return `${day} ${month} ${year}, ${hh}:${mm}`;
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
  const s = String(value);
  if (s.length > 100) return s.slice(0, 100) + '…';
  return s;
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

const EXCLUDE_COLS = ['school_id','student_id','guardian_id','incident_id','alert_id','metadata_json','command_payload'];

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

// ── Teacher Query Panel (patrón AuditDrawer del rector) ──────────────────────

const TeacherQueryPanel = ({
  item, groups, selectedGroup, setSelectedGroup,
  fromDate, setFromDate, toDate, setToDate, onQuery, loadingData, hasQueried,
  dynamicData
}) => {
  const rows = dynamicData;
  const visibleKeys = rows.length > 0
    ? Object.keys(rows[0]).filter(k => !EXCLUDE_COLS.includes(k))
    : [];

  return (
    <div className="flex-1 flex flex-col p-0 overflow-hidden bg-white dark:bg-slate-900">
      {/* Filter Panel — SIEMPRE visible */}
      <div className="shrink-0 p-5 border-b border-slate-100 dark:border-slate-800 space-y-4">
        <div className="flex items-center gap-2 mb-1">
          <Filter size={14} strokeWidth={2} className="text-slate-400" />
          <span className="text-[10px] font-bold uppercase tracking-widest text-slate-400">Filtros de consulta</span>
        </div>

        <div className="space-y-1">
          <label className="block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Grupo académico</label>
          <select
            value={selectedGroup}
            onChange={e => setSelectedGroup(e.target.value)}
            className="w-full p-2.5 text-xs bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-[#003366]/30 focus:border-[#003366]"
          >
            <option value="">— Seleccionar grupo —</option>
            {groups.map(g => (
              <option key={g.id || g.group_id || g.name} value={g.name || g.group_name}>
                {g.name || g.group_name}{g.grade_level ? ` (${g.grade_level})` : ''}
              </option>
            ))}
          </select>
          {groups.length === 0 && (
            <p className="text-[10px] text-amber-600">No se encontraron grupos asignados.</p>
          )}
        </div>

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
        {!hasQueried && !loadingData && (
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

        {loadingData && (
          <div className="flex flex-col items-center justify-center h-64 gap-3">
            <Loader2 size={28} strokeWidth={1.5} className="text-[#003366] animate-spin" />
            <p className="text-xs text-slate-400 font-medium">Consultando registros…</p>
          </div>
        )}

        {!loadingData && hasQueried && rows.length === 0 && (
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

        {!loadingData && hasQueried && rows.length > 0 && (
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
  fromDate, setFromDate, toDate, setToDate, onQuery, onClose
}) => {
  const keys = Object.keys(dynamicColumns);

  return (
    <>
      <motion.div key="ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }} className="fixed inset-0 z-40"
        style={{ backgroundColor: 'rgba(2,6,23,0.5)', backdropFilter: 'blur(2px)' }}
        onClick={onClose} />
      <motion.div key="dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
        transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
        className="fixed right-0 inset-y-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
        style={{ maxWidth: '640px', borderLeft: '1.5px solid #E2E8F0' }}
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
            fromDate={fromDate} setFromDate={setFromDate} toDate={toDate} setToDate={setToDate}
            onQuery={onQuery} loadingData={loadingData} hasQueried={hasQueried}
            dynamicData={dynamicData}
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
                    </tr>
                  </thead>
                  <tbody className="bg-white dark:bg-slate-900">
                    {dynamicData.map((row, i) => (
                      <tr key={i} className="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
                        style={{ borderBottom: i < dynamicData.length - 1 ? '1px solid #F1F5F9' : 'none' }}>
                        {keys.map(k => (
                          <td key={k} className="px-4 py-3 text-sm font-semibold text-slate-700 dark:text-slate-300">
                            {row[k]}
                          </td>
                        ))}
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
    </>
  );
};
