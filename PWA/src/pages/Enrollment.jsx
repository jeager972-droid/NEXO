/**
 * SCR-ENR-01 Enrollment · SCR-ENR-02 Alta/Biometría
 * A-06: tarjetas accionables. B-13: Drawer unificado.
 * Filtros jornada/grupo/estudiante, tarjetas con foto, perfil al click.
 * Usa PageHeader, Stepper, Drawer, SearchableSelect, humanizeError.
 */
import { useState, useEffect, useCallback, useRef, useMemo } from 'react';
import { useAuth } from '../hooks/useAuth';
import { studentsApi } from '../api/students';
import { devicesApi } from '../api/devices';
import { ROLES } from '../config/roles';
import { UserPlus, Search, X, ChevronLeft, ChevronRight, Check, Fingerprint, Phone, Hash, GraduationCap, User, Sparkles, Loader2, AlertCircle, Trash2 } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Surface } from '../components/ui/Surface';
import { Input } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Card } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton, SkeletonRows, SkeletonCards } from '../components/ui/Skeleton';
import { SearchableSelect } from '../components/ui/SearchableSelect';
import { Stepper } from '../components/ui/Stepper';
import { Drawer } from '../components/ui/Overlay';
import { humanizeError } from '../utils/messages';
import { formatGroupName } from '../utils/groupFormat';
import { GRADO_OPTIONS } from '../config/grados';

const EASE = [0.22, 1, 0.36, 1];

const STEPS = ['Datos básicos', 'Acudiente', 'Grado y grupo', 'Huella dactilar', 'Finalizado'];

const EnrollmentDrawer = ({ onClose, onRefresh }) => {
  const [loading, setLoading] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [step, setStep] = useState(1);
  const [form, setForm] = useState({
    nombres: '', apellidos: '', documento: '', jornada: '',
    acudienteNombre: '', acudienteApellidos: '', acudienteDocumento: '', acudienteCelular: '',
    grado: '', grupo: '',
  });
  const [groups, setGroups] = useState([]);
  const [biometricStatus, setBiometricStatus] = useState('checking');
  const [edgeDevice, setEdgeDevice] = useState(null);
  const [enrollCmd, setEnrollCmd] = useState({ state: 'idle', message: '' });
  const enrollPollRef = useRef(null);

  const set = (f) => (e) => setForm((p) => ({ ...p, [f]: e.target.value }));
  const canNext =
    step === 1 ? !!(form.nombres && form.apellidos && form.documento) :
    step === 2 ? !!(form.acudienteNombre && form.acudienteApellidos && form.acudienteDocumento && form.acudienteCelular) :
    step === 3 ? !!(form.grado && form.grupo) :
    step === 4 ? true :
    false;

  useEffect(() => {
    studentsApi.getGroups().then(setGroups).catch(() => {});
  }, []);

  const handleSaveAndProceed = async () => {
    setLoading(true);
    setSaveError('');
    try {
      await studentsApi.create({
        first_name: form.nombres, last_name: form.apellidos, document: form.documento,
        work_shift: form.jornada,
        grade: form.grado, group: form.grupo,
        guardian_name: `${form.acudienteNombre} ${form.acudienteApellidos}`,
        guardian_document: form.acudienteDocumento, guardian_phone: form.acudienteCelular,
      });
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
    setEnrollCmd({ state: 'idle', message: '' });
    devicesApi.getByRole()
      .then((device) => {
        setEdgeDevice(device);
        if (!device) {
          setBiometricStatus('error');
        } else {
          // is_online viene del backend (last_ping < 2min). Si el campo no existe
          // (backend viejo sin deploy), asumir online si hay last_ping reciente.
          const isOnline = device.is_online === true ||
                           device.is_online === 'true' ||
                           device.is_online === 't' ||
                           device.is_online === 1;
          setBiometricStatus(isOnline ? 'connected' : 'offline');
        }
      })
      .catch(() => setBiometricStatus('error'));
    return () => {
      if (enrollPollRef.current) { clearInterval(enrollPollRef.current); enrollPollRef.current = null; }
    };
  }, [step]);

  const handleEnrollCommand = async () => {
    if (!edgeDevice) return;
    setEnrollCmd({ state: 'sending', message: '' });
    // Limpiar polling anterior si existe
    if (enrollPollRef.current) { clearInterval(enrollPollRef.current); enrollPollRef.current = null; }
    try {
      await devicesApi.requestEnrollment(edgeDevice.device_id, {
        doc: form.documento,
        nombre: `${form.nombres} ${form.apellidos}`.trim(),
        tel: form.acudienteCelular,
      });
      setEnrollCmd({
        state: 'sent',
        message: `Listo. Coloca el dedo del alumno en el sensor de secretaría ("${edgeDevice.device_name || 'Secretaría'}") para registrar su huella. Debes levantar y poner el dedo 4 veces.`,
      });
      // Iniciar polling: verificar cada 5s si el estudiante ya tiene huella registrada
      const studentDoc = form.documento;
      let attempts = 0;
      const maxAttempts = 36; // 36 * 5s = 3 minutos máximo
      enrollPollRef.current = setInterval(async () => {
        attempts++;
        if (attempts > maxAttempts) {
          if (enrollPollRef.current) { clearInterval(enrollPollRef.current); enrollPollRef.current = null; }
          setEnrollCmd({
            state: 'error',
            message: 'Tiempo de espera agotado. El sensor no respondió en 3 minutos. Verifica que el alumno ponga el dedo correctamente e inténtalo de nuevo.',
          });
          return;
        }
        try {
          const res = await studentsApi.getAll({ search: studentDoc, limit: 1 });
          const found = res.students?.[0] || res.data?.[0];
          if (found?.has_fingerprint) {
            if (enrollPollRef.current) { clearInterval(enrollPollRef.current); enrollPollRef.current = null; }
            setEnrollCmd({
              state: 'success',
              message: `¡Huella registrada exitosamente para ${form.nombres} ${form.apellidos}! El alumno ya puede usar el sensor para registrar su asistencia.`,
            });
          }
        } catch (e) {
          // Error temporal, seguir intentando
        }
      }, 5000);
    } catch (err) {
      setEnrollCmd({ state: 'error', message: humanizeError(err, 'No pudimos enviar la instrucción al sensor. Verifica que esté conectado e inténtalo de nuevo.') });
    }
  };

  return (
    <Drawer
      title="Nuevo alumno"
      onClose={onClose}
      size="sm"
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" onClick={() => (step > 1 ? setStep((s) => s - 1) : onClose())} leftIcon={step === 1 ? <X size={16} /> : <ChevronLeft size={16} />}>
            {step === 1 ? 'Cancelar' : 'Atrás'}
          </Button>
          {step < 3 ? (
            <Button className="flex-1" onClick={() => canNext && setStep((s) => s + 1)} disabled={!canNext} rightIcon={<ChevronRight size={16} />}>Siguiente</Button>
          ) : step === 3 ? (
            <Button className="flex-1" loading={loading} onClick={handleSaveAndProceed} leftIcon={<Check size={16} />}>Guardar y continuar</Button>
          ) : step === 4 ? (
            <Button className="flex-1" onClick={() => canNext && setStep(5)} rightIcon={<ChevronRight size={16} />}>Siguiente</Button>
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

        {saveError && <div className="mb-4 rounded-control bg-[var(--nx-subtle-bg-danger)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">{saveError}</div>}

        <AnimatePresence mode="wait">
          <motion.div key={step} initial={{ opacity: 0, x: 10 }} animate={{ opacity: 1, x: 0 }} exit={{ opacity: 0, x: -10 }} transition={{ duration: 0.16 }} className="space-y-5">
            {step === 1 && (
              <>
                <div className="border-b border-[var(--nx-border)] pb-3 mb-1">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">Datos del alumno</p>
                  </div>
                </div>
                <Input label="Nombres" value={form.nombres} onChange={set('nombres')} placeholder="Ej. Juan Carlos" />
                <Input label="Apellidos" value={form.apellidos} onChange={set('apellidos')} placeholder="Ej. Pérez Torres" />
                <Input label="Número de documento" value={form.documento} onChange={set('documento')} placeholder="12345678" />
                <div className="space-y-1.5">
                  <label className="text-label text-[var(--nx-text)]">Jornada</label>
                  <select
                    value={form.jornada}
                    onChange={set('jornada')}
                    className="w-full rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 py-2.5 text-body text-[var(--nx-text)] outline-none focus:border-[var(--nx-accent)]"
                  >
                    <option value="mañana">Mañana</option>
                    <option value="tarde">Tarde</option>
                    <option value="completa">Completa</option>
                  </select>
                </div>
              </>
            )}
            {step === 2 && (
              <>
                <div className="border-b border-[var(--nx-border)] pb-3 mb-1">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">Datos del acudiente</p>
                  </div>
                </div>
                <Input label="Nombres" value={form.acudienteNombre} onChange={set('acudienteNombre')} placeholder="Ej. María" />
                <Input label="Apellidos" value={form.acudienteApellidos} onChange={set('acudienteApellidos')} placeholder="Ej. Gómez Ruiz" />
                <Input label="Número de documento" value={form.acudienteDocumento} onChange={set('acudienteDocumento')} placeholder="12345678" />
                <Input label="Número de celular" value={form.acudienteCelular} onChange={set('acudienteCelular')} placeholder="300 123 4567" />
              </>
            )}
            {step === 3 && (
              <>
                <SearchableSelect
                  label="Grado"
                  options={GRADO_OPTIONS}
                  value={form.grado}
                  onChange={(v) => setForm((p) => ({ ...p, grado: v, grupo: '' }))}
                  placeholder="— Seleccionar grado —"
                  searchPlaceholder="Buscar grado…"
                />
                <SearchableSelect
                  label="Grupo"
                  options={groups
                    .filter((g) => {
                      if (!form.grado) return true;
                      const gName = g.name || g.group_name || g;
                      return String(gName).startsWith(form.grado) || g.grade_level === form.grado;
                    })
                    .map((g) => ({ value: g.name || g.group_name || g, label: formatGroupName(g.name || g.group_name || g) }))}
                  value={form.grupo}
                  onChange={(v) => setForm((p) => ({ ...p, grupo: v }))}
                  placeholder={!form.grado ? 'Primero seleccione un grado' : '— Seleccionar grupo —'}
                  searchPlaceholder="Buscar grupo…"
                />
              </>
            )}
            {step === 4 && (
              <div className="space-y-5">
                <div className="rounded-control bg-[var(--nx-subtle-bg-success)] p-4 text-body text-[var(--nx-success)] flex items-center gap-2">
                  <Check size={18} /> Alumno guardado correctamente.
                </div>
                <div className="space-y-2">
                  <p className="text-label text-[var(--nx-text)]">Lector biométrico</p>
                  {biometricStatus === 'checking' ? <Skeleton className="h-12" /> : (
                    <div className={`rounded-control p-4 text-body ${biometricStatus === 'connected' ? 'bg-[var(--nx-subtle-bg-success)] text-[var(--nx-success)]' : biometricStatus === 'offline' ? 'bg-[var(--nx-subtle-bg-warning)] text-[var(--nx-warning)]' : 'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]'}`}>
                      {biometricStatus === 'connected'
                        ? `El sensor "${edgeDevice?.device_name || 'Secretaría'}" está listo. Presiona "Registrar huella" para comenzar.`
                        : biometricStatus === 'offline'
                          ? `El sensor "${edgeDevice?.device_name || 'Secretaría'}" está asignado pero APAGADO. Enciende el dispositivo edge para registrar huellas.`
                          : edgeDevice
                            ? `Error al verificar el estado del sensor "${edgeDevice?.device_name || 'Secretaría'}". Intenta de nuevo.`
                            : 'No tienes ningún sensor asignado. Pide al rector que te asigne uno para registrar huellas.'}
                    </div>
                  )}
                  <Button
                    variant="secondary"
                    className="w-full"
                    disabled={biometricStatus !== 'connected' || enrollCmd.state === 'sending'}
                    loading={enrollCmd.state === 'sending'}
                    onClick={handleEnrollCommand}
                    leftIcon={<Fingerprint size={16} />}
                  >
                    {enrollCmd.state === 'sent' ? 'Volver a enviar la instrucción' : 'Registrar huella'}
                  </Button>
                  {enrollCmd.message && (
                    <div className={`rounded-control p-4 text-body-sm ${enrollCmd.state === 'error' ? 'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]' : enrollCmd.state === 'success' ? 'bg-[var(--nx-subtle-bg-success)] text-[var(--nx-success)]' : 'bg-[var(--nx-subtle-bg-info)] text-[var(--nx-info)]'}`} role="status">
                      {enrollCmd.state === 'success' && <Check size={16} className="inline mr-2" />}
                      {enrollCmd.message}
                    </div>
                  )}
                </div>
              </div>
            )}
            {step === 5 && (
              <div className="space-y-5">
                <div className="rounded-control bg-[var(--nx-subtle-bg-success)] p-4 text-body text-[var(--nx-success)] flex items-center gap-2">
                  <Check size={18} /> Alumno registrado exitosamente.
                </div>
                {biometricStatus !== 'connected' && (
                  <div className="rounded-control bg-[var(--nx-subtle-bg-warning)] p-4 text-body text-[var(--nx-warning)] flex items-center gap-2">
                    <AlertCircle size={18} /> No tienes sensor asignado. El alumno queda pendiente de registrar su huella.
                  </div>
                )}
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
    <div className={`grid shrink-0 place-items-center rounded-full bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)] font-semibold ${sizes[size]}`}>
      {initials || <User size={size === 'lg' ? 28 : 18} />}
    </div>
  );
};

const StudentProfileDrawer = ({ student, onClose, onDeleted }) => {
  const [biometricStatus, setBiometricStatus] = useState('checking');
  const [edgeDevice, setEdgeDevice] = useState(null);
  const [enrollCmd, setEnrollCmd] = useState({ state: 'idle', message: '' });
  const [hasFingerprint, setHasFingerprint] = useState(null);
  const enrollPollRef = useRef(null);
  const [deleteState, setDeleteState] = useState('idle'); // idle | confirm | deleting | error

  useEffect(() => {
    if (!student) return;
    devicesApi.getByRole()
      .then((device) => {
        setEdgeDevice(device);
        if (!device) {
          setBiometricStatus('error');
        } else {
          // is_online viene del backend (last_ping < 2min). Si el campo no existe
          // (backend viejo sin deploy), asumir online si hay last_ping reciente.
          const isOnline = device.is_online === true ||
                           device.is_online === 'true' ||
                           device.is_online === 't' ||
                           device.is_online === 1;
          setBiometricStatus(isOnline ? 'connected' : 'offline');
        }
      })
      .catch(() => setBiometricStatus('error'));
    studentsApi.getAll({ search: student.document || student.documento, limit: 1 })
      .then((res) => {
        const found = res.students?.[0] || res.data?.[0];
        setHasFingerprint(!!found?.has_fingerprint);
      })
      .catch(() => setHasFingerprint(false));
    return () => {
      if (enrollPollRef.current) { clearInterval(enrollPollRef.current); enrollPollRef.current = null; }
    };
  }, [student]);

  const handleChangeFingerprint = async () => {
    if (!edgeDevice) return;
    setEnrollCmd({ state: 'sending', message: '' });
    if (enrollPollRef.current) { clearInterval(enrollPollRef.current); enrollPollRef.current = null; }
    try {
      await devicesApi.requestEnrollment(edgeDevice.device_id, {
        doc: student.document || student.documento,
        nombre: `${student.first_name || ''} ${student.last_name || ''}`.trim(),
        tel: student.guardian_phone || '',
      });
      setEnrollCmd({
        state: 'sent',
        message: `Listo. Coloca el dedo del alumno en el sensor de secretaría ("${edgeDevice.device_name || 'Secretaría'}") para registrar su huella. Debes levantar y poner el dedo 4 veces.`,
      });
      // Polling: verificar cada 5s si la huella quedó registrada
      const studentDoc = student.document || student.documento;
      let attempts = 0;
      const maxAttempts = 36; // 36 * 5s = 3 minutos máximo
      enrollPollRef.current = setInterval(async () => {
        attempts++;
        if (attempts > maxAttempts) {
          if (enrollPollRef.current) { clearInterval(enrollPollRef.current); enrollPollRef.current = null; }
          setEnrollCmd({ state: 'error', message: 'Tiempo de espera agotado. El sensor no respondió en 3 minutos. Inténtalo de nuevo.' });
          return;
        }
        try {
          const res = await studentsApi.getAll({ search: studentDoc, limit: 1 });
          const found = res.students?.[0] || res.data?.[0];
          if (found?.has_fingerprint) {
            if (enrollPollRef.current) { clearInterval(enrollPollRef.current); enrollPollRef.current = null; }
            setHasFingerprint(true);
            setEnrollCmd({
              state: 'success',
              message: `¡Huella registrada exitosamente para ${student.first_name || ''} ${student.last_name || ''}!`,
            });
          }
        } catch (e) { /* retry silencioso */ }
      }, 5000);
    } catch (err) {
      setEnrollCmd({ state: 'error', message: humanizeError(err, 'No pudimos enviar la instrucción al sensor. Verifica que esté conectado e inténtalo de nuevo.') });
    }
  };

  if (!student) return null;
  return (
    <Drawer
      title="Perfil del estudiante"
      onClose={onClose}
      size="md"
    >
      <div className="p-6 space-y-6">
        <div className="border-b border-[var(--nx-border)] pb-3">
          <div className="border-l-2 border-[var(--nx-accent)] pl-3">
            <p className="text-label text-[var(--nx-text)]">Datos del estudiante</p>
          </div>
        </div>
        <div className="flex items-center gap-4">
          <StudentAvatar student={student} size="lg" />
          <div>
            <p className="text-h2 text-[var(--nx-text)]">{student.last_name} {student.first_name}</p>
            <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">{formatGroupName(student.group_name) || student.grade || 'Sin grupo'}</p>
            {student.status && <Badge scheme={student.status === 'active' ? 'success' : 'warning'} dot className="mt-2">{student.status === 'active' ? 'Activo' : 'Inactivo'}</Badge>}
          </div>
        </div>

        <Surface className="divide-y divide-[var(--nx-border)]">
          {[
            { icon: Hash, label: 'Documento', value: student.document || student.documento || '—' },
            { icon: GraduationCap, label: 'Grupo', value: formatGroupName(student.group_name) || student.grade || '—' },
          ].map((row) => (
            <div key={row.label} className="flex items-center gap-3 px-5 py-3.5">
              <row.icon size={16} className="shrink-0 text-[var(--nx-text-muted)]" />
              <span className="text-body-sm text-[var(--nx-text-muted)] w-28">{row.label}</span>
              <span className="text-body text-[var(--nx-text)] flex-1">{row.value}</span>
            </div>
          ))}
        </Surface>

        <Surface className="divide-y divide-[var(--nx-border)]">
          {[
            { icon: User, label: 'Acudiente', value: student.guardian_name || '—' },
            { icon: Hash, label: 'Cédula', value: student.guardian_document || student.guardian_id || '—' },
            { icon: Phone, label: 'Teléfono', value: student.guardian_phone || '—' },
          ].map((row) => (
              <div key={row.label} className="flex items-center gap-3 px-5 py-3.5">
                <row.icon size={16} className="shrink-0 text-[var(--nx-text-muted)]" />
                <span className="text-body-sm text-[var(--nx-text-muted)] w-28">{row.label}</span>
                <span className="text-body text-[var(--nx-text)] flex-1">{row.value}</span>
              </div>
            ))}
        </Surface>

        <div className="grid grid-cols-1">
          <button
            onClick={handleChangeFingerprint}
            disabled={biometricStatus !== 'connected' || enrollCmd.state === 'sending'}
            className="flex items-center gap-3 rounded-panel border border-[var(--nx-border-success)] bg-[var(--nx-surface-success)] p-4 text-left transition-all duration-fast hover:shadow-medium disabled:opacity-45"
          >
            <div className="flex h-10 w-10 items-center justify-center rounded-control bg-[var(--nx-icon-bg-success)] text-[color-mix(in_oklch,var(--nx-success)_72%,var(--nx-icon-mix))]">
              <Fingerprint size={20} />
            </div>
            <div className="min-w-0 flex-1">
              <p className="text-h3 text-[var(--nx-text)]">Cambiar huella del estudiante</p>
              <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">
                {biometricStatus === 'checking'
                  ? 'Verificando sensor…'
                  : biometricStatus !== 'connected'
                    ? 'No tienes ningún sensor asignado. Pide al rector que te asigne uno.'
                    : hasFingerprint === null
                      ? 'Verificando si hay huella registrada…'
                      : hasFingerprint
                        ? 'Este estudiante ya tiene huella. Presiona aquí para volver a registrarla.'
                        : 'Este estudiante no tiene huella todavía. Presiona aquí para registrarla.'}
              </p>
            </div>
            {enrollCmd.state === 'sending' && <Loader2 size={18} className="animate-spin text-[var(--nx-success)]" />}
          </button>
          {enrollCmd.message && (
            <div className={`mt-2 rounded-control p-3 text-body-sm ${enrollCmd.state === 'error' ? 'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]' : enrollCmd.state === 'success' ? 'bg-[var(--nx-subtle-bg-success)] text-[var(--nx-success)]' : 'bg-[var(--nx-subtle-bg-info)] text-[var(--nx-info)]'}`} role="status">
              {enrollCmd.state === 'success' && <Check size={16} className="inline mr-2" />}
              {enrollCmd.message}
            </div>
          )}
        </div>

        {/* Eliminar estudiante — opción sutil al final del drawer */}
        <div className="border-t border-[var(--nx-border)] pt-4">
          {deleteState === 'idle' ? (
            <button
              onClick={() => setDeleteState('confirm')}
              className="text-caption text-[var(--nx-text-muted)] hover:text-[var(--nx-danger)] transition-colors duration-fast flex items-center gap-1.5"
            >
              <Trash2 size={12} />
              Eliminar estudiante del sistema
            </button>
          ) : deleteState === 'confirm' ? (
            <div className="rounded-panel border border-[var(--nx-border-danger)] bg-[var(--nx-surface-danger)] p-4 space-y-3">
              <div className="flex items-start gap-2">
                <AlertCircle size={16} className="shrink-0 text-[var(--nx-danger)] mt-0.5" />
                <div>
                  <p className="text-body-sm text-[var(--nx-danger)] font-medium">¿Eliminar a {student.last_name} {student.first_name}?</p>
                  <p className="text-caption text-[var(--nx-text-muted)] mt-1">
                    Se borrarán todos sus datos: huella, asistencias, permisos y grupo. Esta acción no se puede deshacer.
                  </p>
                </div>
              </div>
              <div className="flex gap-2">
                <button
                  onClick={() => setDeleteState('idle')}
                  className="flex-1 rounded-control border border-[var(--nx-border)] px-3 py-2 text-body-sm text-[var(--nx-text-muted)] hover:bg-[var(--nx-hover)] transition-colors duration-fast"
                >
                  Cancelar
                </button>
                <button
                  onClick={async () => {
                    setDeleteState('deleting');
                    try {
                      await studentsApi.delete(student.id);
                      setDeleteState('idle');
                      onDeleted?.(student.id);
                      onClose();
                    } catch (err) {
                      setDeleteState('error');
                    }
                  }}
                  disabled={deleteState === 'deleting'}
                  className="flex-1 rounded-control bg-[var(--nx-danger)] px-3 py-2 text-body-sm text-white hover:opacity-90 transition-opacity duration-fast disabled:opacity-50"
                >
                  {deleteState === 'deleting' ? <Loader2 size={14} className="animate-spin inline" /> : 'Sí, eliminar'}
                </button>
              </div>
            </div>
          ) : deleteState === 'error' ? (
            <div className="rounded-panel border border-[var(--nx-border-danger)] bg-[var(--nx-surface-danger)] p-3">
              <p className="text-body-sm text-[var(--nx-danger)]">Error al eliminar. Intenta de nuevo.</p>
              <button onClick={() => setDeleteState('confirm')} className="text-caption text-[var(--nx-text-muted)] hover:text-[var(--nx-text)] mt-1">
                Volver
              </button>
            </div>
          ) : null}
        </div>
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
  const [selectedGrade, setSelectedGrade] = useState('');
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
      const result = await studentsApi.getAll({ last_id: cursor, limit: 50, search: debouncedSearch, group_name: selectedGroup, grade: selectedGrade });
      setStudents((prev) => (reset ? result.students : [...prev, ...result.students]));
      setLastId(result.lastId);
      setHasMore(result.students.length === 50 && !!result.lastId);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
      fetchingRef.current = false;
    }
  }, [debouncedSearch, lastId, selectedGroup, selectedGrade]);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { fetchStudents(true); }, [debouncedSearch, selectedGroup, selectedGrade]);

  const groupOptions = useMemo(() =>
    allGroups.map((g) => ({ value: g.name || g.group_name || g, label: formatGroupName(g.name || g.group_name || g) })),
    [allGroups]
  );

  const [view, setView] = useState('home');

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

  const sortedStudents = [...students].sort((a, b) =>
    `${a.last_name || ''} ${a.first_name || ''}`.localeCompare(`${b.last_name || ''} ${b.first_name || ''}`, 'es')
  );

  if (view === 'home') {
    return (
      <div className="space-y-6">
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl">
          <button
            onClick={() => { setIsDrawerOpen(true); }}
            className="flex flex-col p-5 rounded-panel border bg-[var(--nx-surface-accent)] border-[var(--nx-border-accent)] hover:shadow-medium transition-all duration-fast text-left"
          >
            <div className="flex items-start justify-between">
              <div className="flex h-10 w-10 items-center justify-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[color-mix(in_oklch,var(--nx-accent)_72%,var(--nx-icon-mix))]">
                <UserPlus size={20} />
              </div>
              <ChevronRight size={18} className="text-[var(--nx-text-muted)]" />
            </div>
            <p className="mt-4 text-h3 text-[var(--nx-text)]">Nuevo alumno</p>
            <p className="text-body-sm text-[var(--nx-text-muted)] mt-1">Registra un nuevo alumno paso a paso</p>
          </button>

          <button
            onClick={() => { setView('search'); }}
            className="flex flex-col p-5 rounded-panel border bg-[var(--nx-surface-success)] border-[var(--nx-border-success)] hover:shadow-medium transition-all duration-fast text-left"
          >
            <div className="flex items-start justify-between">
              <div className="flex h-10 w-10 items-center justify-center rounded-control bg-[var(--nx-icon-bg-success)] text-[color-mix(in_oklch,var(--nx-success)_72%,var(--nx-icon-mix))]">
                <Search size={20} />
              </div>
              <ChevronRight size={18} className="text-[var(--nx-text-muted)]" />
            </div>
            <p className="mt-4 text-h3 text-[var(--nx-text)]">Buscar estudiante</p>
            <p className="text-body-sm text-[var(--nx-text-muted)] mt-1">Consulta y edita alumnos existentes</p>
          </button>
        </div>

        <AnimatePresence>
          {isDrawerOpen && <EnrollmentDrawer onClose={() => setIsDrawerOpen(false)} onRefresh={() => fetchStudents(true)} />}
        </AnimatePresence>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <button
        onClick={() => setView('home')}
        className="flex items-center gap-1 text-body-sm text-[var(--nx-accent)] font-medium hover:underline"
      >
        <ChevronLeft size={16} /> Volver
      </button>

      <Surface className="p-4 space-y-3">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <Input placeholder="Buscar estudiante…" value={searchTerm} onChange={(e) => setSearchTerm(e.target.value)} leftIcon={<Search size={16} className="text-[var(--nx-text-muted)]" />} />
          <SearchableSelect
            options={GRADO_OPTIONS}
            value={selectedGrade}
            onChange={(v) => { setSelectedGrade(v); setLastId(0); setStudents([]); setHasMore(true); }}
            placeholder="Todos los grados"
            searchPlaceholder="Buscar grado…"
            clearable
          />
          <SearchableSelect
            options={groupOptions.filter((o) => !selectedGrade || String(o.label).startsWith(selectedGrade))}
            value={selectedGroup}
            onChange={(v) => { setSelectedGroup(v); setLastId(0); setStudents([]); setHasMore(true); }}
            placeholder="Todos los grupos"
            searchPlaceholder="Buscar grupo…"
            clearable
          />
        </div>
      </Surface>

      {!selectedGroup && !selectedGrade && !debouncedSearch ? (
        <Surface>
          <EmptyState
            icon={<Search size={32} className="text-[var(--nx-success)]" />}
            title="No hay nada para mostrar."
            description="Elige un grupo o busca un estudiante para ver los resultados aquí."
          />
        </Surface>
      ) : loading && students.length === 0 ? (
        <SkeletonCards count={6} />
      ) : sortedStudents.length === 0 ? (
        <Surface>
          <EmptyState icon={<Sparkles size={32} className="text-[var(--nx-success)]" />} title="No hay nada para mostrar." description="No se encontraron estudiantes con los filtros actuales." />
        </Surface>
      ) : (
        <>
          <p className="text-body-sm text-[var(--nx-text-muted)]">
            Se encontraron {sortedStudents.length} resultado{sortedStudents.length !== 1 ? 's' : ''}
          </p>
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {sortedStudents.map((s, i) => (
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
                      <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">{formatGroupName(s.group_name) || s.grade || 'Sin grupo'}</p>
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
        {selectedProfile && <StudentProfileDrawer student={selectedProfile} onClose={() => setSelectedProfile(null)} onDeleted={(deletedId) => {
          setStudents((prev) => prev.filter((s) => s.id !== deletedId));
          setSelectedProfile(null);
        }} />}
      </AnimatePresence>
    </div>
  );
};

export default Enrollment;
