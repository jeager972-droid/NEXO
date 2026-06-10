import { useState, useEffect, useCallback } from 'react';
import {
  UserPlus, Fingerprint, Trash2, Search,
  ChevronLeft, ChevronRight, Check, X,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { studentsApi } from '../api/students';

// ── Shared primitives ─────────────────────────────────────────────────────────

const INPUT_S = {
  width: '100%', padding: '10px 12px', outline: 'none', fontSize: '13px',
  fontWeight: 500, color: '#0F172A', backgroundColor: '#F8FAFC',
  border: '1.5px solid #E2E8F0', transition: 'border-color 0.2s',
};
const onFocus = e => { e.target.style.borderColor = '#003366'; };
const onBlur  = e => { e.target.style.borderColor = '#E2E8F0'; };

const FL = ({ children }) => (
  <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase', marginBottom: '6px' }}>{children}</p>
);

const Badge = ({ variant = 'inactive', children }) => {
  const v = {
    bio:     ['#00A67E', '#00A67E',  'rgba(0,166,126,0.07)'  ],
    pending: ['#D97706', '#D97706',  'rgba(217,119,6,0.07)'  ],
    active:  ['#003366', '#003366',  'rgba(0,51,102,0.07)'   ],
    inactive:['#94A3B8', '#E2E8F0',  'transparent'           ],
    critical:['#DC2626', '#DC2626',  'rgba(220,38,38,0.07)'  ],
    high:    ['#D97706', '#D97706',  'rgba(217,119,6,0.07)'  ],
  }[variant] || ['#94A3B8', '#E2E8F0', 'transparent'];
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', padding: '2px 8px',
      borderRadius: '999px', border: `1px solid ${v[1]}`, backgroundColor: v[2], color: v[0],
      fontSize: '9px', fontWeight: 700, letterSpacing: '0.15em', textTransform: 'uppercase', whiteSpace: 'nowrap' }}>
      {children}
    </span>
  );
};

const StepBar = ({ current, steps }) => (
  <div className="flex items-center mb-6">
    {steps.map((s, i) => (
      <div key={s.n} className="flex items-center" style={{ flex: i < steps.length - 1 ? '1' : 'none' }}>
        <div className="flex items-center gap-2 shrink-0">
          <div className="flex items-center justify-center w-7 h-7 text-xs font-black transition-colors"
            style={{
              backgroundColor: s.n < current ? '#00A67E' : s.n === current ? '#003366' : 'transparent',
              color: s.n <= current ? '#FFFFFF' : '#94A3B8',
              border: s.n > current ? '1.5px solid #E2E8F0' : 'none',
            }}>
            {s.n < current ? <Check size={11} strokeWidth={3} /> : s.n}
          </div>
          <span className="hidden sm:block" style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.15em',
            textTransform: 'uppercase', color: s.n === current ? '#003366' : '#94A3B8' }}>
            {s.label}
          </span>
        </div>
        {i < steps.length - 1 && (
          <div className="flex-1 mx-3 h-px" style={{ backgroundColor: s.n < current ? '#00A67E' : '#E2E8F0', minWidth: 20 }} />
        )}
      </div>
    ))}
  </div>
);

// ── Stepper Drawer ────────────────────────────────────────────────────────────

const STEPS = [
  { n: 1, label: 'Datos Básicos'   },
  { n: 2, label: 'Identificación'  },
  { n: 3, label: 'Grado y Guardar' },
];

const EnrollmentDrawer = ({ onClose, onRefresh }) => {
  const [loading, setLoading] = useState(false);
  const [step, setStep] = useState(1);
  const [form, setForm] = useState({ nombres: '', apellidos: '', documento: '', grado: '' });
  const set = f => e => setForm(p => ({ ...p, [f]: e.target.value }));
  const canNext = step === 1 ? !!(form.nombres && form.apellidos)
                : step === 2 ? !!form.documento
                : !!form.grado;

  const handleSave = async () => {
    setLoading(true);
    try {
      await studentsApi.create({
        first_name: form.nombres,
        last_name:  form.apellidos,
        document:   form.documento,
        grade:      form.grado,
      });
      onRefresh?.();
      onClose();
    } catch (err) {
      console.error('Error al guardar estudiante:', err);
    } finally {
      setLoading(false);
    }
  };

  return (
    <>
      <motion.div key="ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }} className="fixed inset-0 z-40"
        style={{ backgroundColor: 'rgba(2,6,23,0.5)', backdropFilter: 'blur(2px)' }}
        onClick={onClose} />
      <motion.div key="dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
        transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
        className="fixed right-0 inset-y-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
        style={{ maxWidth: '440px', borderLeft: '1.5px solid #E2E8F0' }}
      >
        {/* Header */}
        <div className="shrink-0 flex items-center justify-between px-6 py-4" style={{ borderBottom: '1.5px solid #F1F5F9' }}>
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-9 h-9" style={{ backgroundColor: 'rgba(0,51,102,0.08)' }}>
              <UserPlus size={18} strokeWidth={2} style={{ color: '#003366' }} />
            </div>
            <div>
              <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: '#1E293B' }}>
                Nuevo Estudiante
              </p>
              <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                Paso {step} de {STEPS.length}
              </p>
            </div>
          </div>
          <button onClick={onClose} className="p-1.5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <X size={18} strokeWidth={2} />
          </button>
        </div>

        {/* Stepper */}
        <div className="px-6 pt-5">
          <StepBar current={step} steps={STEPS} />
        </div>

        {/* Step content */}
        <div className="flex-1 overflow-y-auto px-6 pb-4">
          <AnimatePresence mode="wait">
            <motion.div key={step} initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: -10 }} transition={{ duration: 0.16 }} className="space-y-5">

              {step === 1 && (<>
                <div><FL>Nombres</FL><input type="text" value={form.nombres} onChange={set('nombres')} placeholder="Ej. Juan Carlos" style={INPUT_S} className="dark:bg-slate-800 dark:text-white" onFocus={onFocus} onBlur={onBlur} /></div>
                <div><FL>Apellidos</FL><input type="text" value={form.apellidos} onChange={set('apellidos')} placeholder="Ej. Pérez Torres" style={INPUT_S} className="dark:bg-slate-800 dark:text-white" onFocus={onFocus} onBlur={onBlur} /></div>
              </>)}

              {step === 2 && (<>
                <div>
                  <FL>Número de Documento</FL>
                  <input type="text" value={form.documento} onChange={set('documento')} placeholder="12345678"
                    style={{ ...INPUT_S, fontFamily: 'ui-monospace, monospace', letterSpacing: '0.1em' }}
                    className="dark:bg-slate-800 dark:text-white" onFocus={onFocus} onBlur={onBlur} />
                </div>
                <div className="flex items-start gap-3 p-4 mt-1"
                  style={{ border: '1.5px solid rgba(0,166,126,0.3)', backgroundColor: 'rgba(0,166,126,0.05)' }}>
                  <Fingerprint size={15} strokeWidth={2} style={{ color: '#00A67E', marginTop: '1px' }} className="shrink-0" />
                  <p style={{ fontSize: '11px', color: '#00A67E', fontWeight: 600, lineHeight: 1.5 }}>
                    La vinculación biométrica se completa desde el dispositivo físico tras el registro.
                  </p>
                </div>
              </>)}

              {step === 3 && (<>
                <div>
                  <FL>Grado Institucional</FL>
                  <select value={form.grado} onChange={set('grado')} style={INPUT_S}
                    className="dark:bg-slate-800 dark:text-white appearance-none" onFocus={onFocus} onBlur={onBlur}>
                    <option value="">— Seleccionar grado —</option>
                    {['6-A','6-B','7-A','7-B','8-A','8-B','9-A','9-B','10-A','10-B','11-A','11-B'].map(g =>
                      <option key={g} value={g}>{g}</option>)}
                  </select>
                </div>
                {/* Resumen */}
                <div style={{ border: '1.5px solid #E2E8F0', backgroundColor: '#F8FAFC' }} className="p-4 space-y-3 dark:bg-slate-800">
                  <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>
                    Resumen del Registro
                  </p>
                  {[
                    ['Nombre', `${form.nombres} ${form.apellidos}`.trim() || '—', false],
                    ['Documento', form.documento || '—', true],
                    ['Grado', form.grado || '—', false],
                    ['Biometría', 'Pendiente', false],
                  ].map(([k, v, mono]) => (
                    <div key={k} className="flex justify-between items-baseline gap-4">
                      <span style={{ fontSize: '10px', color: '#94A3B8', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.1em', flexShrink: 0 }}>{k}</span>
                      <span style={{ fontSize: '12px', fontWeight: 700, color: '#1E293B', fontFamily: mono ? 'ui-monospace, monospace' : undefined, letterSpacing: mono ? '0.08em' : undefined }}
                            className="dark:text-slate-200 truncate">{v}</span>
                    </div>
                  ))}
                </div>
              </>)}
            </motion.div>
          </AnimatePresence>
        </div>

        {/* Navigation */}
        <div className="shrink-0 flex gap-3 px-6 py-4" style={{ borderTop: '1.5px solid #F1F5F9' }}>
          <button onClick={() => step > 1 ? setStep(s => s - 1) : onClose()}
            className="flex items-center gap-1.5 px-4 py-3 text-xs font-bold uppercase text-slate-500 hover:text-slate-800 dark:hover:text-white transition-colors"
            style={{ border: '1.5px solid #E2E8F0', letterSpacing: '0.12em' }}>
            <ChevronLeft size={13} strokeWidth={2} />
            {step === 1 ? 'Cancelar' : 'Atrás'}
          </button>
          {step < STEPS.length ? (
            <button onClick={() => canNext && setStep(s => s + 1)} disabled={!canNext}
              className="flex-1 flex items-center justify-center gap-1.5 py-3 text-xs font-bold uppercase text-white disabled:opacity-50 transition-colors"
              style={{ backgroundColor: '#003366', letterSpacing: '0.12em' }}>
              Siguiente <ChevronRight size={13} strokeWidth={2} />
            </button>
          ) : (
            <button onClick={handleSave} disabled={!canNext || loading}
              className="flex-1 flex items-center justify-center gap-1.5 py-3 text-xs font-bold uppercase text-white disabled:opacity-50 transition-colors"
              style={{ backgroundColor: '#003366', letterSpacing: '0.12em' }}>
              <Check size={13} strokeWidth={2.5} /> {loading ? 'Guardando...' : 'Guardar Registro'}
            </button>
          )}
        </div>
      </motion.div>
    </>
  );
};

// ── Enrollment page ───────────────────────────────────────────────────────────

const Enrollment = () => {
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [searchTerm, setSearchTerm]   = useState('');
  const [students, setStudents]       = useState([]);
  const [loading, setLoading]         = useState(true);
  const [lastId, setLastId]           = useState(0);
  const [hasMore, setHasMore]         = useState(true);
  const [limit, setLimit]             = useState(50);
  const [debouncedSearch, setDebouncedSearch] = useState('');

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchTerm);
      setLastId(0);
      setStudents([]);
      setHasMore(true);
    }, 400);
    return () => clearTimeout(timer);
  }, [searchTerm]);

  const fetchStudents = useCallback(async (reset = false) => {
    if (loading && !reset) return;
    setLoading(true);
    try {
      const cursor = reset ? 0 : lastId;
      const result = await studentsApi.getAll({
        last_id: cursor,
        limit,
        search: debouncedSearch,
      });
      setStudents(prev => reset ? result.students : [...prev, ...result.students]);
      setLastId(result.lastId);
      setHasMore(result.hasMore);
    } catch (error) {
      console.error('Error fetching students', error);
    } finally {
      setLoading(false);
    }
  }, [lastId, limit, debouncedSearch]);

  // Carga inicial y recarga cuando cambia la búsqueda
  useEffect(() => {
    fetchStudents(true);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch, limit]);

  const loadMore = () => {
    if (!loading && hasMore) {
      fetchStudents(false);
    }
  };

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div>
          <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: '#94A3B8', textTransform: 'uppercase', userSelect: 'none' }}>Enrolamiento</p>
          <p style={{ fontSize: '13px', fontWeight: 800, color: '#003366', marginTop: '2px' }} className="dark:text-slate-200">Registro y vinculación biométrica</p>
        </div>
        <button onClick={() => setIsDrawerOpen(true)}
          className="flex items-center gap-2 px-5 py-3 text-xs font-bold uppercase text-white hover:opacity-90 transition-opacity"
          style={{ backgroundColor: '#003366', letterSpacing: '0.12em' }}>
          <UserPlus size={14} strokeWidth={2} /> Nuevo Estudiante
        </button>
      </div>

      {/* Toolbar */}
      <div className="flex flex-col sm:flex-row gap-3">
        <div className="relative flex-1">
          <Search size={14} strokeWidth={2} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-300 pointer-events-none" />
          <input type="text" placeholder="Buscar por nombre, documento o grado…" value={searchTerm}
            onChange={e => setSearchTerm(e.target.value)}
            className="w-full pl-9 pr-4 py-2.5 text-sm outline-none dark:bg-slate-900 dark:text-white"
            style={{ border: '1.5px solid #E2E8F0', backgroundColor: '#F8FAFC', fontSize: '13px', fontWeight: 500, color: '#0F172A' }}
            onFocus={onFocus} onBlur={onBlur} />
        </div>
        <select value={limit} onChange={e => { setLimit(Number(e.target.value)); setLastId(0); setStudents([]); setHasMore(true); }}
          className="text-xs font-bold outline-none dark:bg-slate-900 dark:text-white appearance-none px-3 py-2.5"
          style={{ border: '1.5px solid #E2E8F0', backgroundColor: '#F8FAFC', color: '#1E293B', minWidth: '96px' }}>
          {[10, 25, 50, 100].map(n => <option key={n} value={n}>{n} por pág.</option>)}
        </select>
      </div>

      {/* Data Grid */}
      {loading && students.length === 0 ? (
        <div style={{ border: '1.5px solid #E2E8F0' }}>
          {[1,2,3,4,5].map(i => (
            <div key={i} className="flex items-center gap-4 px-6 py-4 bg-white dark:bg-slate-900" style={{ borderBottom: '1px solid #F1F5F9' }}>
              <div className="h-8 w-8 bg-slate-200 dark:bg-slate-700 animate-pulse" />
              <div className="flex-1 space-y-1.5"><div className="h-2 w-36 bg-slate-200 dark:bg-slate-700 animate-pulse" /><div className="h-2 w-24 bg-slate-100 dark:bg-slate-800 animate-pulse" /></div>
              <div className="h-5 w-20 bg-slate-100 dark:bg-slate-700 animate-pulse rounded-full hidden sm:block" />
            </div>
          ))}
        </div>
      ) : students.length > 0 ? (
        <div style={{ border: '1.5px solid #E2E8F0' }} className="overflow-x-auto">
          <table className="w-full min-w-[640px]">
            <thead>
              <tr style={{ backgroundColor: '#F8FAFC', borderBottom: '1.5px solid #E2E8F0' }}>
                {['Estudiante', 'Documento', 'Grado', 'Biometría', 'Estado', ''].map((h, i) => (
                  <th key={i} className="px-5 py-3 text-left whitespace-nowrap"
                    style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#94A3B8', textTransform: 'uppercase' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-slate-900">
              {students.map((s, i) => (
                <tr key={s.id} className="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
                  style={{ borderBottom: i < students.length - 1 ? '1px solid #F1F5F9' : 'none' }}>
                  <td className="px-5 py-4">
                    <div className="flex items-center gap-3">
                      <div className="shrink-0 flex items-center justify-center w-8 h-8 text-xs font-black text-white" style={{ backgroundColor: '#003366' }}>
                        {s.name?.charAt(0)?.toUpperCase()}
                      </div>
                      <span className="text-sm font-bold text-slate-800 dark:text-slate-200 truncate" style={{ maxWidth: '160px' }}>{s.name}</span>
                    </div>
                  </td>
                  <td className="px-5 py-4">
                    <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '12px', color: '#475569', letterSpacing: '0.08em' }} className="dark:text-slate-400">{s.id || '—'}</span>
                  </td>
                  <td className="px-5 py-4">
                    <span className="text-sm font-semibold text-slate-600 dark:text-slate-400">{s.group || '—'}</span>
                  </td>
                  <td className="px-5 py-4"><Badge variant={s.fingerprintId ? 'bio' : 'pending'}>{s.fingerprintId ? 'Vinculada' : 'Pendiente'}</Badge></td>
                  <td className="px-5 py-4"><Badge variant={s.active ? 'active' : 'inactive'}>{s.active ? 'Activo' : 'Inactivo'}</Badge></td>
                  <td className="px-5 py-4">
                    <div className="flex items-center gap-1">
                      <button className="p-1.5 text-slate-300 hover:text-gov-900 transition-colors" title="Vincular huella"><Fingerprint size={14} strokeWidth={2} /></button>
                      <button className="p-1.5 text-slate-300 hover:text-red-500 transition-colors" title="Eliminar"><Trash2 size={14} strokeWidth={2} /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="flex items-center justify-center py-20" style={{ border: '1.5px solid #E2E8F0' }}>
          <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: '#CBD5E1', textTransform: 'uppercase' }}>Sin registros encontrados</p>
        </div>
      )}

      {/* Load more */}
      {hasMore && !loading && (
        <div className="flex justify-center">
          <button onClick={() => fetchStudents(false)}
            className="px-6 py-2.5 text-xs font-bold uppercase hover:bg-gov-900 hover:text-white transition-colors"
            style={{ border: '1.5px solid #E2E8F0', color: '#003366', letterSpacing: '0.12em' }}>
            Cargar más registros
          </button>
        </div>
      )}

      <AnimatePresence>
        {isDrawerOpen && <EnrollmentDrawer onClose={() => setIsDrawerOpen(false)} onRefresh={() => fetchStudents(true)} />}
      </AnimatePresence>
    </div>
  );
};

export default Enrollment;
