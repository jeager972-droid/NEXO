/**
 * ConsultationDrawer / NEXO Institucional — B-13 Drawer unificado
 * Drawer detallado de consulta por módulo: filtros, tabla dinámica y seguimiento.
 * Usa Drawer de Overlay.jsx, RiskBadge pattern, SkeletonRows, humanizeError.
 */
import { useState, useEffect, useRef } from 'react';
import { Search, Activity, Filter, Eye, AlertTriangle } from 'lucide-react';
import { AnimatePresence } from 'framer-motion';
import { studentsApi } from '../api/students';
import { TrackingModal } from './TrackingModal';
import { useAuth } from '../hooks/useAuth';
import { ROLES } from '../config/roles';
import { Surface } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { EmptyState } from '../components/ui/EmptyState';
import { SkeletonRows } from '../components/ui/Skeleton';
import { Drawer } from '../components/ui/Overlay';
import { RiskBadge } from '../components/patterns/RiskBadge';

const EXCLUDE_COLS = ['student_id', 'id', 'metadata', 'metadata_json', 'raw'];

const humanizeColumn = (k) => String(k).replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase());

const formatCellValue = (k, v) => {
  if (v === null || v === undefined) return '—';
  if (k.toLowerCase().includes('date') || k.toLowerCase().includes('at') || k.toLowerCase().includes('created') || k.toLowerCase().includes('entry')) {
    const d = new Date(v);
    return isNaN(d) ? String(v) : d.toLocaleString('es-CO');
  }
  return String(v);
};

const riskLevelMap = { CRITICAL: 'critico', MEDIUM: 'medio', LOW: 'bajo', HIGH: 'alto' };

const SearchableSelect = ({ label, options, value, onChange, placeholder, loading }) => {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const ref = useRef(null);

  useEffect(() => {
    function handleClickOutside(e) { if (ref.current && !ref.current.contains(e.target)) setOpen(false); }
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
        <Search size={14} className="text-[var(--nx-text-muted)] shrink-0 ml-2" />
      </button>
      {open && (
        <div className="absolute z-20 mt-1 w-full rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-high max-h-60 overflow-auto">
          <div className="sticky top-0 border-b border-[var(--nx-border)] bg-[var(--nx-surface)] p-2">
            <Input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Buscar…" leftIcon={<Search size={14} className="text-[var(--nx-text-muted)]" />} />
          </div>
          {filtered.length === 0 && <div className="px-3 py-2 text-body-sm text-[var(--nx-text-muted)]">Sin coincidencias</div>}
          {filtered.map((o) => (
            <button key={o.id} onClick={() => { onChange(o.id); setOpen(false); setQ(''); }} className={`w-full px-3 py-2 text-left text-body-sm truncate transition-colors ${o.id === value ? 'bg-[color-mix(in_oklch,var(--nx-accent)_10%,transparent)] text-[var(--nx-accent)] font-medium' : 'text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)]'}`}>
              {o.name}
            </button>
          ))}
        </div>
      )}
    </div>
  );
};

const TeacherQueryPanel = ({
  item, groups, selectedGroup, setSelectedGroup, selectedStudent, setSelectedStudent,
  fromDate, setFromDate, toDate, setToDate, onQuery, loadingData, hasQueried, dynamicData, error
}) => {
  const [students, setStudents] = useState([]);
  const [studentsLoading, setStudentsLoading] = useState(false);
  const rows = dynamicData;
  const visibleKeys = rows.length > 0 ? Object.keys(rows[0]).filter((k) => !EXCLUDE_COLS.includes(k)) : [];

  useEffect(() => {
    if (!selectedGroup) { setStudents([]); setSelectedStudent(''); return; }
    setStudentsLoading(true);
    studentsApi.getAll({ limit: 100, group_name: selectedGroup })
      .then((res) => setStudents(res.students || []))
      .catch(() => setStudents([]))
      .finally(() => setStudentsLoading(false));
  }, [selectedGroup]);

  const groupOptions = groups.map((g) => ({ id: g.name || g.group_name || g, name: `${g.name || g.group_name || g}${g.grade_level ? ` (${g.grade_level})` : ''}` }));
  const studentOptions = [...students].sort((a, b) => (a.last_name || '').localeCompare(b.last_name || '', 'es')).map((s) => ({ id: String(s.id || s.student_id), name: `${s.last_name || ''} ${s.first_name || ''}`.trim() }));

  return (
    <div className="flex flex-col">
      <Surface className="border-b border-[var(--nx-border)] p-5 space-y-4 rounded-none">
        <div className="flex items-center gap-2 text-label text-[var(--nx-text-muted)] uppercase">
          <Filter size={14} /> Filtros de consulta
        </div>
        <SearchableSelect label="Grupo académico" placeholder="Seleccionar grupo…" options={groupOptions} value={selectedGroup} onChange={(v) => { setSelectedGroup(v); setSelectedStudent(''); }} />
        <SearchableSelect label="Estudiante" placeholder={!selectedGroup ? 'Primero seleccione un grupo' : 'Todos los estudiantes del grupo'} options={studentOptions} value={selectedStudent} onChange={(v) => setSelectedStudent(v)} loading={studentsLoading} />
        <div className="grid grid-cols-2 gap-3">
          <Input type="date" label="Desde" value={fromDate} onChange={(e) => setFromDate(e.target.value)} />
          <Input type="date" label="Hasta" value={toDate} onChange={(e) => setToDate(e.target.value)} />
        </div>
        <Button onClick={onQuery} loading={loadingData} disabled={!selectedGroup} leftIcon={<Eye size={16} />}>Consultar</Button>
      </Surface>

      <div className="p-5">
        {error ? (
          <EmptyState icon={<AlertTriangle size={32} className="text-[var(--nx-danger)]" />} title="Error de consulta" description={error} />
        ) : !hasQueried && !loadingData ? (
          <EmptyState icon={<Activity size={32} className="text-[var(--nx-border)]" />} title="Sin registros" description="Seleccione un grupo y un rango de fechas, luego presione Consultar." />
        ) : loadingData && rows.length === 0 ? (
          <SkeletonRows count={4} />
        ) : rows.length === 0 ? (
          <EmptyState icon={<Activity size={32} className="text-[var(--nx-border)]" />} title="Sin registros" description={`No se encontraron registros para ${item} en el grupo y período seleccionado.`} />
        ) : (
          <Surface className="overflow-x-auto">
            <table className="w-full min-w-[500px]">
              <thead>
                <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                  {visibleKeys.map((k) => <th key={k} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)] whitespace-nowrap">{humanizeColumn(k)}</th>)}
                </tr>
              </thead>
              <tbody className="divide-y divide-[var(--nx-border)]">
                {rows.map((row, i) => (
                  <tr key={i} className="hover:bg-[var(--nx-surface-subtle)]">
                    {visibleKeys.map((k) => <td key={k} className="px-4 py-3 text-body-sm text-[var(--nx-text)] whitespace-nowrap max-w-[200px] truncate">{formatCellValue(k, row[k])}</td>)}
                  </tr>
                ))}
              </tbody>
            </table>
          </Surface>
        )}
      </div>
    </div>
  );
};

export const ConsultationDrawer = ({
  item, riskStudents, dynamicData, dynamicColumns, loadingData,
  isTeacherModule, hasQueried, groups, selectedGroup, setSelectedGroup,
  selectedStudent, setSelectedStudent, fromDate, setFromDate, toDate, setToDate,
  onQuery, onClose, error, executeQuery
}) => {
  const { user } = useAuth();
  const keys = Object.keys(dynamicColumns);
  const [trackingModalOpen, setTrackingModalOpen] = useState(false);
  const [selectedTrackingTarget, setSelectedTrackingTarget] = useState(null);

  const openTracking = (studentId, studentName, trackingId = null, metadata = null) => {
    setSelectedTrackingTarget({ studentId, studentName, trackingId, metadata });
    setTrackingModalOpen(true);
  };

  return (
    <>
      <Drawer
        title={item}
        context={isTeacherModule ? 'Consulta histórica por grupo' : 'Consulta de datos institucionales'}
        onClose={onClose}
        size="lg"
      >
        {isTeacherModule ? (
          <TeacherQueryPanel
            item={item} groups={groups} selectedGroup={selectedGroup} setSelectedGroup={setSelectedGroup}
            selectedStudent={selectedStudent} setSelectedStudent={setSelectedStudent}
            fromDate={fromDate} setFromDate={setFromDate} toDate={toDate} setToDate={setToDate}
            onQuery={onQuery} loadingData={loadingData} hasQueried={hasQueried} dynamicData={dynamicData} error={error}
          />
        ) : (
          <div className="flex-1 overflow-y-auto p-6">
            {error && !loadingData ? (
              <EmptyState icon={<AlertTriangle size={32} className="text-[var(--nx-danger)]" />} title="Error de consulta" description={error} />
            ) : loadingData ? (
              <SkeletonRows count={4} />
            ) : item === 'Análisis de Riesgo' && riskStudents.length > 0 ? (
              <Surface className="overflow-x-auto">
                <table className="w-full min-w-[440px]">
                  <thead>
                    <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                      {['Estudiante', 'Grupo', 'Score', 'Nivel', 'Acción'].map((h) => <th key={h} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">{h}</th>)}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[var(--nx-border)]">
                    {[...riskStudents].sort((a, b) => (a.last_name || '').localeCompare(b.last_name || '', 'es')).map((s) => (
                      <tr key={s.student_id} className="hover:bg-[var(--nx-surface-subtle)]">
                        <td className="px-4 py-3 text-body text-[var(--nx-text)]">{s.last_name} {s.first_name}</td>
                        <td className="px-4 py-3 text-body-sm text-[var(--nx-text-muted)]">{s.group_name}</td>
                        <td className="px-4 py-3 text-body font-mono" style={{ color: s.risk_score >= 85 ? 'var(--nx-danger)' : 'var(--nx-warning)' }}>{s.risk_score}</td>
                        <td className="px-4 py-3"><RiskBadge level={riskLevelMap[s.risk_level] || 'bajo'} /></td>
                        <td className="px-4 py-3 text-right">
                          <Button size="sm" variant="quiet" onClick={() => openTracking(s.student_id, `${s.last_name} ${s.first_name}`, null, { risk_score: s.risk_score, absence_count: s.absence_count, late_count: s.late_count })}>Seguimiento</Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Surface>
            ) : item !== 'Análisis de Riesgo' && dynamicData.length > 0 ? (
              <Surface className="overflow-x-auto">
                <table className="w-full min-w-[500px]">
                  <thead>
                    <tr className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)]">
                      {keys.map((k) => <th key={k} className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">{dynamicColumns[k] || humanizeColumn(k)}</th>)}
                      {['Seguimiento Estudiantil', 'Alertas', 'Seguimientos completados'].includes(item) && user?.role !== ROLES.DOCENTE && <th className="px-4 py-3 text-left text-caption font-medium uppercase text-[var(--nx-text-muted)]">Acción</th>}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-[var(--nx-border)]">
                    {dynamicData.map((row, i) => (
                      <tr key={i} className="hover:bg-[var(--nx-surface-subtle)]">
                        {keys.map((k) => <td key={k} className="px-4 py-3 text-body-sm text-[var(--nx-text)] whitespace-nowrap max-w-[200px] truncate">{formatCellValue(k, row[k])}</td>)}
                        {['Seguimiento Estudiantil', 'Alertas', 'Seguimientos completados'].includes(item) && user?.role !== ROLES.DOCENTE && row.student_id && (
                          <td className="px-4 py-3">
                            <Button size="sm" variant="quiet" onClick={() => openTracking(row.student_id, `${row.last_name} ${row.first_name}`, row.tracking_id, row.metadata_json)}>Ver</Button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </Surface>
            ) : (
              <EmptyState icon={<Activity size={32} className="text-[var(--nx-border)]" />} title="Sin datos disponibles" description="No se encontraron registros para este módulo." />
            )}
          </div>
        )}
      </Drawer>

      <AnimatePresence>
        {trackingModalOpen && selectedTrackingTarget && (
          <TrackingModal
            trackingId={selectedTrackingTarget.trackingId}
            studentId={selectedTrackingTarget.studentId}
            studentName={selectedTrackingTarget.studentName}
            metadata={selectedTrackingTarget.metadata}
            onClose={() => setTrackingModalOpen(false)}
            onRefresh={() => { if (typeof executeQuery === 'function') executeQuery(); else if (typeof onQuery === 'function') onQuery(); }}
          />
        )}
      </AnimatePresence>
    </>
  );
};
