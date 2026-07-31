/**
 * SCR-ENR-01 Enrollment · SCR-ENR-02 Alta/Biometría
 * A-06: tarjetas accionables. B-13: Drawer unificado.
 * Filtros jornada/grupo/estudiante, tarjetas con foto, perfil al click.
 * Usa PageHeader, Stepper, Drawer, SearchableSelect, humanizeError.
 */
import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useAuth } from '../hooks/useAuth';
import { studentsApi } from '../api/students';
import { ROLES } from '../config/roles';
import { UserPlus, Search, X, ChevronLeft, ChevronRight, Check, Fingerprint, Phone, FileText, Hash, GraduationCap, User, Sparkles } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Surface, Section } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Card } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton, SkeletonRows, SkeletonCards } from '../components/ui/Skeleton';
import { Select } from '../components/ui/Select';
import { SearchableSelect } from '../components/ui/SearchableSelect';
import { Stepper } from '../components/ui/Stepper';
import { Drawer } from '../components/ui/Overlay';
import { humanizeError } from '../utils/messages';

const EASE = [0.22, 1, 0.36, 1];

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

        {saveError && <div className="mb-4 rounded-control bg-[color-mix(in_oklch,var(--nx-danger)_var(--nx-subtle-mix),transparent)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">{saveError}</div>}

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
              <SearchableSelect
                label="Grado institucional"
                options={groups.map((g) => ({ value: g.name || g, label: g.name || g }))}
                value={form.grado}
                onChange={(v) => setForm((p) => ({ ...p, grado: v }))}
                placeholder="— Seleccionar grado —"
                searchPlaceholder="Buscar grado…"
              />
            )}
            {step === 4 && (
              <div className="space-y-5">
                <div className="rounded-control bg-[color-mix(in_oklch,var(--nx-success)_var(--nx-subtle-mix),transparent)] p-4 text-body text-[var(--nx-success)] flex items-center gap-2">
                  <Check size={18} /> Estudiante guardado correctamente.
                </div>
                <div className="space-y-2">
                  <p className="text-label text-[var(--nx-text)]">Lector biométrico</p>
                  {biometricStatus === 'checking' ? <Skeleton className="h-12" /> : (
                    <div className={`rounded-control p-4 text-body ${biometricStatus === 'connected' ? 'bg-[color-mix(in_oklch,var(--nx-success)_var(--nx-subtle-mix),transparent)] text-[var(--nx-success)]' : 'bg-[color-mix(in_oklch,var(--nx-warning)_var(--nx-subtle-mix-w),transparent)] text-[var(--nx-warning)]'}`}>
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

const StudentAvatar = ({ student, size = 'md' }) => {
  const initials = `${(student.first_name || '')[0] || ''}${(student.last_name || '')[0] || ''}`.toUpperCase();
  const sizes = { sm: 'h-9 w-9 text-body-sm', md: 'h-12 w-12 text-h3', lg: 'h-16 w-16 text-h2' };
  return (
    <div className={`grid shrink-0 place-items-center rounded-full bg-[color-mix(in_oklch,var(--nx-accent)_var(--nx-subtle-mix),transparent)] text-[var(--nx-accent)] font-semibold ${sizes[size]}`}>
      {initials || <User size={size === 'lg' ? 28 : 18} />}
    </div>
  );
};

const StudentProfileDrawer = ({ student, onClose }) => {
  if (!student) return null;
  return (
    <Drawer
      title={`${student.last_name} ${student.first_name}`}
      context={student.group_name || student.grade || 'Sin grupo'}
      onClose={onClose}
      size="md"
    >
      <div className="p-6 space-y-6">
        <div className="flex items-center gap-4">
          <StudentAvatar student={student} size="lg" />
          <div>
            <p className="text-h2 text-[var(--nx-text)]">{student.last_name} {student.first_name}</p>
            <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">{student.group_name || student.grade || 'Sin grupo'}</p>
            {student.status && <Badge scheme={student.status === 'active' ? 'success' : 'warning'} dot className="mt-2">{student.status === 'active' ? 'Activo' : 'Inactivo'}</Badge>}
          </div>
        </div>

        <Section title="Datos del estudiante">
          <Surface className="divide-y divide-[var(--nx-border)]">
            {[
              { icon: Hash, label: 'Documento', value: student.document || student.documento || '—' },
              { icon: GraduationCap, label: 'Grupo', value: student.group_name || student.grade || '—' },
              { icon: FileText, label: 'ID', value: student.student_id || student.id || '—' },
            ].map((row) => (
              <div key={row.label} className="flex items-center gap-3 px-5 py-3.5">
                <row.icon size={16} className="shrink-0 text-[var(--nx-text-muted)]" />
                <span className="text-body-sm text-[var(--nx-text-muted)] w-28">{row.label}</span>
                <span className="text-body text-[var(--nx-text)] flex-1">{row.value}</span>
              </div>
            ))}
          </Surface>
        </Section>

        {student.guardian_name && (
          <Section title="Acudiente">
            <Surface className="divide-y divide-[var(--nx-border)]">
              {[
                { icon: User, label: 'Nombre', value: student.guardian_name },
                { icon: Phone, label: 'Teléfono', value: student.guardian_phone || '—' },
              ].map((row) => (
                <div key={row.label} className="flex items-center gap-3 px-5 py-3.5">
                  <row.icon size={16} className="shrink-0 text-[var(--nx-text-muted)]" />
                  <span className="text-body-sm text-[var(--nx-text-muted)] w-28">{row.label}</span>
                  <span className="text-body text-[var(--nx-text)] flex-1">{row.value}</span>
                </div>
              ))}
            </Surface>
          </Section>
        )}
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

  const [allGroups, setAllGroups] = useState([]);
  const [selectedGroup, setSelectedGroup] = useState('');
  const [selectedProfile, setSelectedProfile] = useState(null);

  useEffect(() => {
    studentsApi.getGroups().then(setAllGroups).catch(() => {});
  }, []);

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
      const result = await studentsApi.getAll({ last_id: cursor, limit: 50, search: debouncedSearch, group_name: selectedGroup });
      setStudents((prev) => (reset ? result.students : [...prev, ...result.students]));
      setLastId(result.lastId);
      setHasMore(result.students.length === 50 && !!result.lastId);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
      fetchingRef.current = false;
    }
  }, [debouncedSearch, lastId, selectedGroup]);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { fetchStudents(true); }, [debouncedSearch, selectedGroup]);

  const groupOptions = useMemo(() =>
    allGroups.map((g) => ({ value: g.name || g.group_name || g, label: g.name || g.group_name || g })),
    [allGroups]
  );

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
      <div className="flex items-center justify-between">
        <Button onClick={() => setIsDrawerOpen(true)} leftIcon={<UserPlus size={18} />}>Nuevo estudiante</Button>
      </div>

      <Surface className="p-4 space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
          <Input placeholder="Buscar estudiante…" value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />} />
          <SearchableSelect
            options={groupOptions}
            value={selectedGroup}
            onChange={(v) => { setSelectedGroup(v); setLastId(0); setStudents([]); setHasMore(true); }}
            placeholder="Todos los grupos"
            searchPlaceholder="Buscar grupo…"
            clearable
          />
        </div>
      </Surface>

      {loading && students.length === 0 ? (
        <SkeletonCards count={6} />
      ) : students.length === 0 ? (
        <Surface>
          <EmptyState icon={<Sparkles size={32} className="text-[var(--nx-success)]" />} title="Todo en orden por aquí!" description="No se encontraron estudiantes con los filtros actuales." />
        </Surface>
      ) : (
        <>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {students.map((s, i) => (
              <motion.div
                key={s.student_id || s.id}
                initial={{ opacity: 0, y: 6 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.2, ease: EASE, delay: Math.min(i * 0.03, 0.15) }}
              >
                <Card asAction onClick={() => setSelectedProfile(s)} className="p-4">
                  <div className="flex items-start gap-3">
                    <StudentAvatar student={s} />
                    <div className="min-w-0 flex-1">
                      <p className="text-h3 text-[var(--nx-text)] truncate">{s.last_name} {s.first_name}</p>
                      <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">{s.group_name || s.grade || 'Sin grupo'}</p>
                      {s.document && (
                        <p className="text-caption text-[var(--nx-text-muted)] mt-1.5">Doc: {s.document}</p>
                      )}
                    </div>
                    {s.status && <Badge scheme={s.status === 'active' ? 'success' : 'warning'} dot>{s.status === 'active' ? 'Activo' : 'Inactivo'}</Badge>}
                  </div>
                </Card>
              </motion.div>
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

      <AnimatePresence>
        {selectedProfile && <StudentProfileDrawer student={selectedProfile} onClose={() => setSelectedProfile(null)} />}
      </AnimatePresence>
    </div>
  );
};

export default Enrollment;
