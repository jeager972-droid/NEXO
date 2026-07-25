/**
 * SCR-ENR-01 Enrollment
 * Matrícula y gestión de estudiantes. Listado paginado y formulario de creación.
 */
import { useState, useEffect, useCallback, useRef } from 'react';
import { useAuth } from '../hooks/useAuth';
import { studentsApi } from '../api/students';
import { ROLES } from '../config/roles';
import { UserPlus, Search, X, ChevronLeft, ChevronRight, Check, Loader2, AlertTriangle, Fingerprint } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Section, Surface } from '../components/ui/Surface';
import { Input, Textarea } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Card } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton } from '../components/ui/Skeleton';

const STEPS = ['Datos básicos', 'Documento', 'Grupo'];

const EnrollmentDrawer = ({ onClose, onRefresh }) => {
  const [loading, setLoading] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [step, setStep] = useState(1);
  const [form, setForm] = useState({ nombres: '', apellidos: '', documento: '', grado: '' });
  const [groups, setGroups] = useState([]);
  const [biometricStatus, setBiometricStatus] = useState('checking');
  const [studentSaved, setStudentSaved] = useState(false);
  const [savedStudentId, setSavedStudentId] = useState(null);

  const set = (f) => (e) => setForm((p) => ({ ...p, [f]: e.target.value }));
  const canNext = step === 1 ? !!(form.nombres && form.apellidos) : step === 2 ? !!form.documento : step === 3 ? !!form.grado : false;

  useEffect(() => {
    studentsApi.getGroups().then(setGroups).catch(() => {});
  }, []);

  const handleSaveAndProceed = async () => {
    setLoading(true);
    setSaveError('');
    try {
      const result = await studentsApi.create({ first_name: form.nombres, last_name: form.apellidos, document: form.documento, grade: form.grado });
      setSavedStudentId(result.student_id);
      setStudentSaved(true);
      onRefresh?.();
      setStep(4);
    } catch (err) {
      setSaveError(err?.response?.data?.message || 'Error al guardar el estudiante. Intenta de nuevo.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (step !== 4) return;
    setBiometricStatus('checking');
    fetch('http://localhost:8765/status', { signal: AbortSignal.timeout(3000) })
      .then((res) => res.json())
      .then((data) => setBiometricStatus(data.connected ? 'connected' : 'error'))
      .catch(() => setBiometricStatus('error'));
  }, [step]);

  return (
    <>
      <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} onClick={onClose} className="fixed inset-0 z-40 bg-[color-mix(in_oklch,var(--nx-text)_45%,transparent)]" />
      <motion.div initial={{ x: '100%' }} animate={{ x: 0 }} exit={{ x: '100%' }} className="fixed right-0 top-0 z-50 flex h-full w-full max-w-[440px] flex-col overflow-hidden border-l border-[var(--nx-border)] bg-[var(--nx-surface)]">
        <div className="flex shrink-0 items-center justify-between border-b border-[var(--nx-border)] px-6 py-4">
          <p className="text-h3 text-[var(--nx-text)]">Nuevo estudiante</p>
          <button onClick={onClose} className="p-2 rounded-control hover:bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]"><X size={20} /></button>
        </div>

        <div className="flex-1 overflow-y-auto p-6">
          <div className="mb-6 flex items-center justify-between">
            {STEPS.map((s, i) => (
              <div key={s} className="flex flex-1 items-center">
                <div className={`flex h-7 w-7 items-center justify-center rounded-full text-caption font-medium ${i + 1 <= step ? 'bg-[var(--nx-accent)] text-[var(--nx-accent-text)]' : 'border border-[var(--nx-border)] text-[var(--nx-text-muted)]'}`}>
                  {i + 1 < step ? <Check size={14} /> : i + 1}
                </div>
                {i < STEPS.length - 1 && <div className={`h-0.5 flex-1 mx-2 ${i + 1 < step ? 'bg-[var(--nx-accent)]' : 'bg-[var(--nx-border)]'}`} />}
              </div>
            ))}
          </div>

          {saveError && <div className="mb-4 rounded-control bg-[color-mix(in_oklch,var(--nx-danger)_8%,transparent)] px-4 py-3 text-body-sm text-[var(--nx-danger)]">{saveError}</div>}

          <AnimatePresence mode="wait">
            <motion.div key={step} initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -10 }} transition={{ duration: 0.16 }} className="space-y-5">
              {step === 1 && (
                <>
                  <Input label="Nombres" value={form.nombres} onChange={set('nombres')} placeholder="Ej. Juan Carlos" />
                  <Input label="Apellidos" value={form.apellidos} onChange={set('apellidos')} placeholder="Ej. Pérez Torres" />
                </>
              )}
              {step === 2 && <Input label="Número de documento" value={form.documento} onChange={set('documento')} placeholder="12345678" />}
              {step === 3 && (
                <div className="space-y-2">
                  <label className="block text-label text-[var(--nx-text)]">Grado institucional</label>
                  <select value={form.grado} onChange={set('grado')} className="h-12 w-full rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-3.5 text-body text-[var(--nx-text)] outline-none focus:border-[var(--nx-accent)]">
                    <option value="">— Seleccionar grado —</option>
                    {groups.map((g) => <option key={g.id || g} value={g.name || g}>{g.name || g}</option>)}
                  </select>
                </div>
              )}
              {step === 4 && (
                <div className="space-y-5">
                  <div className="rounded-control bg-[color-mix(in_oklch,var(--nx-success)_10%,transparent)] p-4 text-body text-[var(--nx-success)] flex items-center gap-2">
                    <Check size={18} /> Estudiante guardado correctamente.
                  </div>
                  <div className="space-y-2">
                    <p className="text-label text-[var(--nx-text)]">Lector biométrico</p>
                    {biometricStatus === 'checking' ? <Skeleton className="h-12" /> : (
                      <div className={`rounded-control p-4 text-body ${biometricStatus === 'connected' ? 'bg-[color-mix(in_oklch,var(--nx-success)_10%,transparent)] text-[var(--nx-success)]' : 'bg-[color-mix(in_oklch,var(--nx-warning)_10%,transparent)] text-[var(--nx-warning)]'}`}>
                        {biometricStatus === 'connected' ? 'Lector conectado. Puedes registrar huella.' : 'No se detectó lector biométrico.'}
                      </div>
                    )}
                    <Button variant="secondary" className="w-full" disabled={biometricStatus !== 'connected'} leftIcon={<Fingerprint size={16} />}>Registrar huella</Button>
                  </div>
                </div>
              )}
            </motion.div>
          </AnimatePresence>
        </div>

        <div className="flex shrink-0 gap-3 border-t border-[var(--nx-border)] px-6 py-4">
          <Button variant="secondary" onClick={() => (step > 1 ? setStep((s) => s - 1) : onClose())} leftIcon={step === 1 ? <X size={16} /> : <ChevronLeft size={16} />}>
            {step === 1 ? 'Cancelar' : 'Atrás'}
          </Button>
          {step < STEPS.length ? (
            <Button className="flex-1" onClick={() => canNext && setStep((s) => s + 1)} disabled={!canNext} rightIcon={<ChevronRight size={16} />}>Siguiente</Button>
          ) : step === 3 ? (
            <Button className="flex-1" loading={loading} onClick={handleSaveAndProceed} leftIcon={<Check size={16} />}>Guardar</Button>
          ) : (
            <Button className="flex-1" onClick={onClose}>Finalizar</Button>
          )}
        </div>
      </motion.div>
    </>
  );
};

const Enrollment = () => {
  const { user } = useAuth();
  const [isDrawerOpen, setIsDrawerOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [lastId, setLastId] = useState(0);
  const [hasMore, setHasMore] = useState(true);
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const fetchingRef = useRef(false);

  useEffect(() => {
    const timer = setTimeout(() => { setDebouncedSearch(searchTerm); setLastId(0); setStudents([]); setHasMore(true); }, 400);
    return () => clearTimeout(timer);
  }, [searchTerm]);

  const fetchStudents = useCallback(async (reset = false) => {
    if (fetchingRef.current && !reset) return;
    fetchingRef.current = true;
    setLoading(true);
    try {
      const cursor = reset ? 0 : lastId;
      const result = await studentsApi.getAll({ last_id: cursor, limit: 50, search: debouncedSearch });
      setStudents((prev) => (reset ? result.students : [...prev, ...result.students]));
      setLastId(result.lastId);
      setHasMore(result.students.length === 50 && !!result.lastId);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
      fetchingRef.current = false;
    }
  }, [debouncedSearch, lastId]);

  useEffect(() => { fetchStudents(true); }, [debouncedSearch]);

  if (user?.role !== ROLES.SECRETARIA) {
    return <div className="rounded-control bg-[color-mix(in_oklch,var(--nx-warning)_10%,transparent)] p-4 text-body text-[var(--nx-warning)]">Solo disponible para Secretaría.</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <Section title="Matrícula" subtitle="Gestión de estudiantes" />
        <Button onClick={() => setIsDrawerOpen(true)} leftIcon={<UserPlus size={18} />}>Nuevo estudiante</Button>
      </div>
      <Input placeholder="Buscar estudiante…" value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />} />
      {loading && students.length === 0 ? (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {[1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-20" />)}
        </div>
      ) : students.length === 0 ? (
        <Surface><EmptyState icon={<UserPlus size={32} className="text-[var(--nx-border)]" />} title="Sin estudiantes" description="No se encontraron estudiantes." /></Surface>
      ) : (
        <>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {students.map((s) => (
              <Card key={s.student_id || s.id} className="p-4">
                <p className="text-h3 text-[var(--nx-text)]">{s.last_name} {s.first_name}</p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">{s.group_name || s.grade || 'Sin grupo'}</p>
                <div className="mt-3 flex items-center gap-2 text-caption text-[var(--nx-text-muted)]">
                  {s.document && <Badge scheme="info">Doc: {s.document}</Badge>}
                  {s.status && <Badge scheme={s.status === 'active' ? 'success' : 'warning'}>{s.status}</Badge>}
                </div>
              </Card>
            ))}
          </div>
          {hasMore && (
            <div className="flex justify-center">
              <Button variant="secondary" loading={loading} onClick={() => fetchStudents()}>Cargar más</Button>
            </div>
          )}
        </>
      )}
      <AnimatePresence>
        {isDrawerOpen && <EnrollmentDrawer onClose={() => setIsDrawerOpen(false)} onRefresh={() => fetchStudents(true)} />}
      </AnimatePresence>
    </div>
  );
};

export default Enrollment;
