/**
 * SCR-ENR-01 Enrollment · SCR-ENR-02 Alta/Biometría
 * A-06: tarjetas accionables. B-13: Drawer unificado.
 * Usa PageHeader, Stepper, Drawer de Overlay.jsx, Select, humanizeError.
 */
import { useState, useEffect, useCallback, useRef } from 'react';
import { useAuth } from '../hooks/useAuth';
import { studentsApi } from '../api/students';
import { ROLES } from '../config/roles';
import { UserPlus, Search, X, ChevronLeft, ChevronRight, Check, Fingerprint } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Surface, PageHeader } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Card } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton, SkeletonRows } from '../components/ui/Skeleton';
import { Select } from '../components/ui/Select';
import { Stepper } from '../components/ui/Stepper';
import { Drawer } from '../components/ui/Overlay';
import { humanizeError } from '../utils/messages';

const STEPS = ['Datos básicos', 'Documento', 'Grupo'];

const EnrollmentDrawer = ({ onClose, onRefresh }) => {
  const [loading, setLoading] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [step, setStep] = useState(1);
  const [form, setForm] = useState({ nombres: '', apellidos: '', documento: '', grado: '' });
  const [groups, setGroups] = useState([]);
  const [biometricStatus, setBiometricStatus] = useState('checking');

  const set = (f) => (e) => setForm((p) => ({ ...p, [f]: e.target.value }));
  const canNext = step === 1 ? !!(form.nombres && form.apellidos) : step === 2 ? !!form.documento : step === 3 ? !!form.grado : false;

  useEffect(() => {
    studentsApi.getGroups().then(setGroups).catch(() => {});
  }, []);

  const handleSaveAndProceed = async () => {
    setLoading(true);
    setSaveError('');
    try {
      await studentsApi.create({ first_name: form.nombres, last_name: form.apellidos, document: form.documento, grade: form.grado });
      onRefresh?.();
      setStep(4);
    } catch (err) {
      setSaveError(humanizeError(err, 'Error al guardar el estudiante. Intenta de nuevo.'));
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
    <Drawer
      title="Nuevo estudiante"
      onClose={onClose}
      size="sm"
      footer={
        <div className="flex gap-3">
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
      }
    >
      <div className="p-6">
        <div className="mb-6">
          <Stepper steps={STEPS} current={step} />
        </div>

        {saveError && <div className="mb-4 rounded-control bg-[color-mix(in_oklch,var(--nx-danger)_8%,transparent)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">{saveError}</div>}

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
              <Select
                label="Grado institucional"
                options={[{ value: '', label: '— Seleccionar grado —' }, ...groups.map((g) => ({ value: g.name || g, label: g.name || g }))]
                }
                value={form.grado}
                onChange={(e) => set('grado')(e)}
              />
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
    </Drawer>
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
    return (
      <div className="max-w-3xl">
        <EmptyState
          variant="denied"
          title="Acceso restringido"
          description="Solo disponible para Secretaría."
        />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Gestión de estudiantes"
        title="Matrícula"
        actions={<Button onClick={() => setIsDrawerOpen(true)} leftIcon={<UserPlus size={18} />}>Nuevo estudiante</Button>}
      />
      <Input placeholder="Buscar estudiante…" value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />} />
      {loading && students.length === 0 ? (
        <Surface><SkeletonRows count={4} /></Surface>
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
