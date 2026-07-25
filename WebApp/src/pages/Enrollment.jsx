/**
 * Enrollment page / NEXO Institucional
 * Responsabilidad: Registro y gestión de estudiantes: listado paginado con scroll infinito,
 * búsqueda, drawer de registro en múltiples pasos y huella digital (mock/lector externo).
 * Limitado a SECRETARIA.
 * Dependencias: React, react-router-dom, framer-motion, studentsApi, useAuth, ROLES.
 */
import { useState, useEffect, useCallback, useRef } from 'react';
import {
  UserPlus, Fingerprint, Trash2, Search,
  ChevronLeft, ChevronRight, Check, X, Loader2, AlertTriangle
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { studentsApi } from '../api/students';
import { Navigate } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import { ROLES } from '../config/roles';

// ── Shared primitives ─────────────────────────────────────────────────────────

const INPUT_S = {
  width: '100%', padding: '10px 12px', outline: 'none', fontSize: '13px',
  fontWeight: 500, color: 'var(--nx-text)', backgroundColor: 'var(--nx-surface-subtle)',
  border: '1.5px solid var(--nx-border)', transition: 'border-color 0.2s',
};
const onFocus = e => { e.target.style.borderColor = 'var(--nx-accent)'; };
const onBlur  = e => { e.target.style.borderColor = 'var(--nx-border)'; };

const FL = ({ children }) => (
  <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: 'var(--nx-text-muted)', textTransform: 'uppercase', marginBottom: '6px' }}>{children}</p>
);

const Badge = ({ variant = 'inactive', children }) => {
  const v = {
    bio:     ['var(--nx-success)', 'var(--nx-success)',  'color-mix(in oklch, var(--nx-success) 7%, transparent)'  ],
    pending: ['var(--nx-warning)', 'var(--nx-warning)',  'color-mix(in oklch, var(--nx-warning) 7%, transparent)'  ],
    active:  ['var(--nx-accent)', 'var(--nx-accent)',  'color-mix(in oklch, var(--nx-accent) 7%, transparent)'   ],
    inactive:['var(--nx-text-muted)', 'var(--nx-border)',  'transparent'           ],
    critical:['var(--nx-danger)', 'var(--nx-danger)',  'color-mix(in oklch, var(--nx-danger) 7%, transparent)'  ],
    high:    ['var(--nx-warning)', 'var(--nx-warning)',  'color-mix(in oklch, var(--nx-warning) 7%, transparent)'  ],
  }[variant] || ['var(--nx-text-muted)', 'var(--nx-border)', 'transparent'];
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
              backgroundColor: s.n < current ? 'var(--nx-success)' : s.n === current ? 'var(--nx-accent)' : 'transparent',
              color: s.n <= current ? 'var(--nx-accent-text)' : 'var(--nx-text-muted)',
              border: s.n > current ? '1.5px solid var(--nx-border)' : 'none',
            }}>
            {s.n < current ? <Check size={11} strokeWidth={3} /> : s.n}
          </div>
          <span className="hidden sm:block" style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.15em',
            textTransform: 'uppercase', color: s.n === current ? 'var(--nx-accent)' : 'var(--nx-text-muted)' }}>
            {s.label}
          </span>
        </div>
        {i < steps.length - 1 && (
          <div className="flex-1 mx-3 h-px" style={{ backgroundColor: s.n < current ? 'var(--nx-success)' : 'var(--nx-border)', minWidth: 20 }} />
        )}
      </div>
    ))}
  </div>
);

// ── Stepper Drawer ────────────────────────────────────────────────────────────

const STEPS = [
  { n: 1, label: 'Datos Básicos'   },
  { n: 2, label: 'Identificación'  },
  { n: 3, label: 'Grado y Grupo'   },
  { n: 4, label: 'Registro Biométrico' },
];

const EnrollmentDrawer = ({ onClose, onRefresh }) => {
  const [loading, setLoading] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [step, setStep] = useState(1);
  const [form, setForm] = useState({ nombres: '', apellidos: '', documento: '', grado: '' });
  const [groups, setGroups] = useState([]);
  const [biometricStatus, setBiometricStatus] = useState('checking'); // 'checking' | 'connected' | 'error'
  const [studentSaved, setStudentSaved] = useState(false);
  const [savedStudentId, setSavedStudentId] = useState(null);

  const set = f => e => setForm(p => ({ ...p, [f]: e.target.value }));
  const canNext = step === 1 ? !!(form.nombres && form.apellidos)
                : step === 2 ? !!form.documento
                : step === 3 ? !!form.grado
                : false;

  useEffect(() => {
    studentsApi.getGroups().then(setGroups).catch(() => {});
  }, []);

  const handleSaveAndProceed = async () => {
    setLoading(true);
    setSaveError('');
    try {
      const result = await studentsApi.create({
        first_name: form.nombres,
        last_name:  form.apellidos,
        document:   form.documento,
        grade:      form.grado,
      });
      setSavedStudentId(result.student_id);
      setStudentSaved(true);
      onRefresh?.();
      setStep(4);
    } catch (err) {
      console.error('Error al guardar estudiante:', err);
      const msg = err?.response?.data?.message || 'Error al guardar el estudiante. Intenta de nuevo.';
      setSaveError(msg);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (step !== 4) return;
    setBiometricStatus('checking');
    // Verificar si hay lector biométrico disponible
    fetch('http://localhost:8765/status', { signal: AbortSignal.timeout(3000) })
      .then(res => res.json())
      .then(data => {
        if (data.connected) setBiometricStatus('connected');
        else setBiometricStatus('error');
      })
      .catch(() => setBiometricStatus('error'));
  }, [step]);

  return (
    <>
      <motion.div key="ov" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
        transition={{ duration: 0.2 }} className="fixed inset-0 z-40"
        style={{ backgroundColor: 'rgba(2,6,23,0.5)', backdropFilter: 'blur(2px)' }}
        onClick={onClose} />
      <motion.div key="dw" initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }}
        transition={{ type: 'spring', damping: 30, stiffness: 300, mass: 0.8 }}
        className="fixed right-0 inset-y-0 z-50 flex flex-col bg-white dark:bg-slate-900 w-full overflow-hidden"
        style={{ maxWidth: '440px', borderLeft: '1.5px solid var(--nx-border)' }}
      >
        {/* Header */}
        <div className="shrink-0 flex items-center justify-between px-6 py-4" style={{ borderBottom: '1.5px solid var(--nx-surface-subtle)' }}>
          <div className="flex items-center gap-3">
            <div className="flex items-center justify-center w-9 h-9" style={{ backgroundColor: 'color-mix(in oklch, var(--nx-accent) 8%, transparent)' }}>
              <UserPlus size={18} strokeWidth={2} style={{ color: 'var(--nx-accent)' }} />
            </div>
            <div>
              <p className="text-sm font-black uppercase dark:text-white" style={{ letterSpacing: '0.06em', color: 'var(--nx-text)' }}>
                Nuevo Estudiante
              </p>
              <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: 'var(--nx-text-muted)', textTransform: 'uppercase' }}>
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
                  style={{ border: '1.5px solid color-mix(in oklch, var(--nx-success) 30%, transparent)', backgroundColor: 'color-mix(in oklch, var(--nx-success) 5%, transparent)' }}>
                  <Fingerprint size={15} strokeWidth={2} style={{ color: 'var(--nx-success)', marginTop: '1px' }} className="shrink-0" />
                  <p style={{ fontSize: '11px', color: 'var(--nx-success)', fontWeight: 600, lineHeight: 1.5 }}>
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
                    {groups.length > 0
                      ? groups.map(g => <option key={g.name} value={g.name}>{g.name}</option>)
                      : <option disabled>Sin grupos — contacta al administrador</option>
                    }
                  </select>
                </div>
                {/* Resumen */}
                <div style={{ border: '1.5px solid var(--nx-border)', backgroundColor: 'var(--nx-surface-subtle)' }} className="p-4 space-y-3 dark:bg-slate-800">
                  <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: 'var(--nx-text-muted)', textTransform: 'uppercase' }}>
                    Resumen del Registro
                  </p>
                  {[
                    ['Nombre', `${form.nombres} ${form.apellidos}`.trim() || '—', false],
                    ['Documento', form.documento || '—', true],
                    ['Grado', form.grado || '—', false],
                    ['Biometría', 'Pendiente', false],
                  ].map(([k, v, mono]) => (
                    <div key={k} className="flex justify-between items-baseline gap-4">
                      <span style={{ fontSize: '10px', color: 'var(--nx-text-muted)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.1em', flexShrink: 0 }}>{k}</span>
                      <span style={{ fontSize: '12px', fontWeight: 700, color: 'var(--nx-text)', fontFamily: mono ? 'ui-monospace, monospace' : undefined, letterSpacing: mono ? '0.08em' : undefined }}
                            className="dark:text-slate-200 truncate">{v}</span>
                    </div>
                  ))}
                </div>
                {saveError && (
                  <p className="text-xs font-semibold text-red-500 mt-2">{saveError}</p>
                )}
              </>)}

              {step === 4 && (
                <div className="space-y-5">
                  {biometricStatus === 'checking' && (
                    <div className="flex flex-col items-center gap-3 py-8">
                      <Loader2 size={32} className="animate-spin text-[var(--nx-accent)]" />
                      <p className="text-xs font-semibold text-slate-500">Buscando lector biométrico...</p>
                    </div>
                  )}
                  {biometricStatus === 'error' && (
                    <div className="p-5 border-2 border-red-200 bg-red-50 space-y-3 mt-4">
                      <div className="flex items-center gap-2">
                        <AlertTriangle size={18} className="text-red-500" />
                        <p className="text-sm font-bold text-red-700">Lector no encontrado</p>
                      </div>
                      <p className="text-xs text-red-600 leading-relaxed">
                        No se encontró conexión con el lector de huellas. El estudiante fue registrado en el sistema pero <strong>no puede completar el enrolamiento biométrico</strong> en este momento.
                      </p>
                      <p className="text-xs text-red-600">
                        Para vincular la huella, conecta el lector e ingresa nuevamente al registro del estudiante.
                      </p>
                      <button
                        onClick={onClose}
                        className="w-full py-2.5 mt-2 text-xs font-bold uppercase text-white"
                        style={{ backgroundColor: 'var(--nx-accent)', letterSpacing: '0.12em' }}
                      >
                        Cerrar — Completar biometría después
                      </button>
                    </div>
                  )}
                  {biometricStatus === 'connected' && (
                    <div className="space-y-4 mt-4 p-5" style={{ border: '1.5px solid color-mix(in oklch, var(--nx-success) 30%, transparent)', backgroundColor: 'color-mix(in oklch, var(--nx-success) 5%, transparent)' }}>
                      <div className="flex items-center gap-2 text-green-600">
                        <Fingerprint size={20} />
                        <p className="text-sm font-bold">Lector conectado.</p>
                      </div>
                      <p className="text-xs text-slate-600 font-medium">Solicita al estudiante que coloque su dedo en el lector para completar el registro.</p>
                    </div>
                  )}
                </div>
              )}
            </motion.div>
          </AnimatePresence>
        </div>

        {/* Navigation */}
        <div className="shrink-0 flex gap-3 px-6 py-4" style={{ borderTop: '1.5px solid var(--nx-surface-subtle)' }}>
          <button onClick={() => step > 1 ? setStep(s => s - 1) : onClose()}
            className="flex items-center gap-1.5 px-4 py-3 text-xs font-bold uppercase text-slate-500 hover:text-slate-800 dark:hover:text-white transition-colors"
            style={{ border: '1.5px solid var(--nx-border)', letterSpacing: '0.12em' }}>
            <ChevronLeft size={13} strokeWidth={2} />
            {step === 1 ? 'Cancelar' : 'Atrás'}
          </button>
          {step < STEPS.length ? (
            <button onClick={() => canNext && setStep(s => s + 1)} disabled={!canNext}
              className="flex-1 flex items-center justify-center gap-1.5 py-3 text-xs font-bold uppercase text-white disabled:opacity-50 transition-colors"
              style={{ backgroundColor: 'var(--nx-accent)', letterSpacing: '0.12em' }}>
              Siguiente <ChevronRight size={13} strokeWidth={2} />
            </button>
          ) : step === 3 ? (
            <button onClick={handleSaveAndProceed} disabled={!canNext || loading}
              className="flex-1 flex items-center justify-center gap-1.5 py-3 text-xs font-bold uppercase text-white disabled:opacity-50 transition-colors"
              style={{ backgroundColor: 'var(--nx-accent)', letterSpacing: '0.12em' }}>
              {loading ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} strokeWidth={2.5} />} 
              {loading ? 'Guardando...' : 'Guardar y Continuar'}
            </button>
          ) : null}
        </div>
      </motion.div>
    </>
  );
};

// ── Enrollment page ───────────────────────────────────────────────────────────

const Enrollment = () => {
  const { user } = useAuth();
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [searchTerm, setSearchTerm]   = useState('');
  const [students, setStudents]       = useState([]);
  const [loading, setLoading]         = useState(true);
  const [lastId, setLastId]           = useState(0);
  const [hasMore, setHasMore]         = useState(true);
  const [limit, setLimit]             = useState(50);
  const [debouncedSearch, setDebouncedSearch] = useState('');
  // BUG-02 FIX: ref para rastrear in-flight sin depender del closure de loading
  const fetchingRef = useRef(false);

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
    // BUG-02 FIX: usar ref en vez de loading (evita stale closure)
    if (fetchingRef.current && !reset) return;
    fetchingRef.current = true;
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
      fetchingRef.current = false;
      setLoading(false);
    }
  }, [lastId, limit, debouncedSearch]);

  // Carga inicial y recarga cuando cambia la búsqueda
  useEffect(() => {
    fetchStudents(true);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedSearch, limit]);

  if (user?.role !== ROLES.SECRETARIA) {
    return <Navigate to="/" replace />;
  }

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
          <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.25em', color: 'var(--nx-text-muted)', textTransform: 'uppercase', userSelect: 'none' }}>Enrolamiento</p>
          <p style={{ fontSize: '13px', fontWeight: 800, color: 'var(--nx-accent)', marginTop: '2px' }} className="dark:text-slate-200">Registro y vinculación biométrica</p>
        </div>
        <button onClick={() => setIsDrawerOpen(true)}
          className="flex items-center gap-2 px-5 py-3 text-xs font-bold uppercase text-white hover:opacity-90 transition-opacity"
          style={{ backgroundColor: 'var(--nx-accent)', letterSpacing: '0.12em' }}>
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
            style={{ border: '1.5px solid var(--nx-border)', backgroundColor: 'var(--nx-surface-subtle)', fontSize: '13px', fontWeight: 500, color: 'var(--nx-text)' }}
            onFocus={onFocus} onBlur={onBlur} />
        </div>
        <select value={limit} onChange={e => { setLimit(Number(e.target.value)); setLastId(0); setStudents([]); setHasMore(true); }}
          className="text-xs font-bold outline-none dark:bg-slate-900 dark:text-white appearance-none px-3 py-2.5"
          style={{ border: '1.5px solid var(--nx-border)', backgroundColor: 'var(--nx-surface-subtle)', color: 'var(--nx-text)', minWidth: '96px' }}>
          {[10, 25, 50, 100].map(n => <option key={n} value={n}>{n} por pág.</option>)}
        </select>
      </div>

      {/* Data Grid */}
      {loading && students.length === 0 ? (
        <div style={{ border: '1.5px solid var(--nx-border)' }}>
          {[1,2,3,4,5].map(i => (
            <div key={i} className="flex items-center gap-4 px-6 py-4 bg-white dark:bg-slate-900" style={{ borderBottom: '1px solid var(--nx-surface-subtle)' }}>
              <div className="h-8 w-8 bg-slate-200 dark:bg-slate-700 animate-pulse" />
              <div className="flex-1 space-y-1.5"><div className="h-2 w-36 bg-slate-200 dark:bg-slate-700 animate-pulse" /><div className="h-2 w-24 bg-slate-100 dark:bg-slate-800 animate-pulse" /></div>
              <div className="h-5 w-20 bg-slate-100 dark:bg-slate-700 animate-pulse rounded-full hidden sm:block" />
            </div>
          ))}
        </div>
      ) : students.length > 0 ? (
        <div style={{ border: '1.5px solid var(--nx-border)' }} className="overflow-x-auto">
          <table className="w-full min-w-[640px]">
            <thead>
              <tr style={{ backgroundColor: 'var(--nx-surface-subtle)', borderBottom: '1.5px solid var(--nx-border)' }}>
                {['Estudiante', 'Documento', 'Grado', 'Biometría', 'Estado', ''].map((h, i) => (
                  <th key={i} className="px-5 py-3 text-left whitespace-nowrap"
                    style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: 'var(--nx-text-muted)', textTransform: 'uppercase' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody className="bg-white dark:bg-slate-900">
              {students.map((s, i) => (
                <tr key={s.id} className="hover:bg-slate-50 dark:hover:bg-white/5 transition-colors"
                  style={{ borderBottom: i < students.length - 1 ? '1px solid var(--nx-surface-subtle)' : 'none' }}>
                  <td className="px-5 py-4">
                    <div className="flex items-center gap-3">
                      <div className="shrink-0 flex items-center justify-center w-8 h-8 text-xs font-black text-white" style={{ backgroundColor: 'var(--nx-accent)' }}>
                        {s.name?.charAt(0)?.toUpperCase()}
                      </div>
                      <span className="text-sm font-bold text-slate-800 dark:text-slate-200 truncate" style={{ maxWidth: '160px' }}>{s.name}</span>
                    </div>
                  </td>
                  <td className="px-5 py-4">
                    <span style={{ fontFamily: 'ui-monospace, monospace', fontSize: '12px', color: 'var(--nx-text-muted)', letterSpacing: '0.08em' }} className="dark:text-slate-400">{s.id || '—'}</span>
                  </td>
                  <td className="px-5 py-4">
                    <span className="text-sm font-semibold text-slate-600 dark:text-slate-400">{s.group || '—'}</span>
                  </td>
                  <td className="px-5 py-4"><Badge variant={s.fingerprintId ? 'bio' : 'pending'}>{s.fingerprintId ? 'Vinculada' : 'Pendiente'}</Badge></td>
                  <td className="px-5 py-4"><Badge variant={s.active ? 'active' : 'inactive'}>{s.active ? 'Activo' : 'Inactivo'}</Badge></td>
                  <td className="px-5 py-4">
                    <div className="flex items-center gap-1">
                      <button className="p-1.5 text-slate-300 hover:text-[var(--nx-accent)] transition-colors" title="Vincular huella"><Fingerprint size={14} strokeWidth={2} /></button>
                      <button className="p-1.5 text-slate-300 hover:text-red-500 transition-colors" title="Eliminar"><Trash2 size={14} strokeWidth={2} /></button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="flex items-center justify-center py-20" style={{ border: '1.5px solid var(--nx-border)' }}>
          <p style={{ fontSize: '9px', fontWeight: 700, letterSpacing: '0.2em', color: 'var(--nx-text-muted)', textTransform: 'uppercase' }}>Sin registros encontrados</p>
        </div>
      )}

      {/* Load more */}
      {hasMore && !loading && (
        <div className="flex justify-center">
          <button onClick={() => fetchStudents(false)}
            className="px-6 py-2.5 text-xs font-bold uppercase hover:bg-[var(--nx-accent)] hover:text-white transition-colors"
            style={{ border: '1.5px solid var(--nx-border)', color: 'var(--nx-accent)', letterSpacing: '0.12em' }}>
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
