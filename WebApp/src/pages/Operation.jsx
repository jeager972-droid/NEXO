/**
 * SCR-OPS-01 Operation · SCR-OPS-02 Flujo · SCR-OPS-03 Resultado
 * DEC-FE-08: flujo guiado de 3 pasos con Stepper.
 * Usa PageHeader, Stepper, OperationResult, humanizeError.
 */
import { useState, useEffect, useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import {
  AlertOctagon, ShieldCheck, ShieldAlert,
  Clock, Bus, Calendar, Wrench, Send, UserCheck,
  ChevronRight, Loader2, FileText, Siren,
  GitMerge, Maximize2
} from 'lucide-react';
import { operationsApi } from '../api/operations';
import { studentsApi } from '../api/students';
import { devicesApi } from '../api/devices';
import { usersApi } from '../api/users';
import { ROLES, getRoleDisplay } from '../config/roles';
import { Surface } from '../components/ui/Surface';
import { Card } from '../components/ui/Card';
import { Input, Textarea } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { SearchableSelect } from '../components/ui/SearchableSelect';
import { SkeletonCards } from '../components/ui/Skeleton';
import { Stepper } from '../components/ui/Stepper';
import { OperationResult } from '../components/patterns/OperationResult';
import { humanizeError } from '../utils/messages';
import { formatGroupName } from '../utils/groupFormat';
import { GRADO_OPTIONS } from '../config/grados';
import { NexoChatBubble } from '../components/patterns/NexoChat';

const COMMANDS_CATALOG = [
  { id: 'citar',       title: 'Citar acudiente',     icon: Calendar,   roles: [ROLES.RECTOR, ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR], fields: ['grade', 'group', 'student', 'date', 'time', 'message'], tone: 'accent' },
  { id: 'autorizar',   title: 'Autorizar salida',    icon: ShieldCheck,roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['grade', 'group', 'student', 'reason'], tone: 'success' },
  { id: 'situacion_critica', title: 'Situación Crítica', icon: Siren, roles: Object.values(ROLES), fields: ['location', 'message'], tone: 'danger' },
  { id: 'daño',        title: 'Reportar daño',       icon: Wrench,     roles: [ROLES.AUXILIAR, ROLES.PORTERO], fields: ['location', 'description'], tone: 'warning' },
  { id: 'solicitud',   title: 'Mandar solicitud',    icon: Send,       roles: Object.values(ROLES), fields: ['targetRole', 'targets', 'message'], tone: 'accent' },
  { id: 'seguimiento', title: 'Solicitar seguimiento',icon: FileText,  roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['grade', 'group', 'student', 'reason'], tone: 'accent' },
  { id: 'pedagogica',  title: 'Salida pedagógica',   icon: Bus,        roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['grade', 'group', 'reason'], tone: 'success' },
  { id: 'horario',     title: 'Cambio de horario',   icon: Clock,      roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['grade', 'group', 'reason', 'time'], warning: 'Este comando avisará a todos los padres de familia del grupo elegido.', tone: 'warning' },
  { id: 'permiso',     title: 'Generar permiso',     icon: UserCheck,  roles: [ROLES.DOCENTE, ROLES.COORDINADOR, ROLES.RECTOR], fields: ['grade', 'group', 'student', 'reason', 'timeStart', 'timeEnd'], tone: 'success' },
  { id: 'incidente',   title: 'Reportar incidente',  icon: ShieldAlert,roles: [ROLES.DOCENTE, ROLES.PSICORIENTADOR], fields: ['grade', 'group', 'student', 'location', 'message', 'targets'], tone: 'danger' },
  { id: 'fusionar_bloque', title: 'Fusionar bloque', icon: GitMerge,   roles: [ROLES.DOCENTE], fields: ['grade', 'group', 'reason'], tone: 'accent' },
  { id: 'extender_bloque', title: 'Extender bloque', icon: Maximize2,  roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['time'], tone: 'warning' },
];

const CMD_TONE_STYLES = {
  accent:  { bg: 'bg-[var(--nx-surface-accent)]', icon: 'bg-[var(--nx-icon-bg-accent)] text-[color-mix(in_oklch,var(--nx-accent)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-border-accent)]' },
  success: { bg: 'bg-[var(--nx-surface-success)]', icon: 'bg-[var(--nx-icon-bg-success)] text-[color-mix(in_oklch,var(--nx-success)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-border-success)]' },
  warning: { bg: 'bg-[var(--nx-surface-warning)]', icon: 'bg-[var(--nx-icon-bg-warning)] text-[color-mix(in_oklch,var(--nx-warning)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-border-warning)]' },
  danger:  { bg: 'bg-[var(--nx-surface-danger)]', icon: 'bg-[var(--nx-icon-bg-danger)] text-[color-mix(in_oklch,var(--nx-danger)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-border-danger)]' },
};

const FIELD_LABELS = {
  group: 'Grupo', student: 'Estudiante', grade: 'Grado', date: 'Fecha', time: 'Hora',
  timeStart: 'Hora de salida', timeEnd: 'Hora de retorno',
  message: 'Mensaje', reason: 'Motivo', location: 'Ubicación', description: 'Descripción',
  targetRole: 'Rol destinatario', targets: 'Destinatarios'
};

const Operation = () => {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const [activeCommand, setActiveCommand] = useState(null);
  const [groups, setGroups] = useState([]);
  const [students, setStudents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [fetchError, setFetchError] = useState('');

  const fetchData = useCallback(async (signal) => {
    setLoading(true);
    setFetchError('');
    try {
      const uRole = user?.role_name || user?.role;
      const isTeacherRole = uRole === ROLES.DOCENTE || uRole === ROLES.PSICORIENTADOR;
      const groupsData = await studentsApi.getGroups(isTeacherRole);
      if (!signal.aborted) setGroups(Array.isArray(groupsData) ? groupsData : []);
    } catch (error) {
      if (!signal.aborted) setFetchError((p) => p ? `${p} | ${error.message}` : error.message);
    }
    try {
      const allStudents = await studentsApi.getAllPaginated();
      if (!signal.aborted) setStudents(Array.isArray(allStudents) ? allStudents : []);
    } catch (error) {
      if (!signal.aborted) setFetchError((p) => p ? `${p} | ${error.message}` : error.message);
    }
    if (!signal.aborted) setLoading(false);
  }, [user]);

  useEffect(() => {
    const controller = new AbortController();
    fetchData(controller.signal);
    return () => controller.abort();
  }, [fetchData]);

  const userRole = user?.role;
  const filteredCommands = useMemo(() => COMMANDS_CATALOG
    .filter((cmd) => userRole && cmd.roles.includes(userRole)), [userRole]);

  useEffect(() => {
    const cmdTitle = searchParams.get('cmd');
    if (cmdTitle) {
      const found = filteredCommands.find((c) => c.title === cmdTitle);
      if (found) setActiveCommand(found);
      setSearchParams({}, { replace: true });
    }
  }, [searchParams, setSearchParams, filteredCommands]);

  if (loading) {
    const skeletonCount = filteredCommands.length > 0 ? filteredCommands.length : 6;
    return (
      <div className="space-y-6">
        <SkeletonCards count={skeletonCount} />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {!activeCommand ? (
        filteredCommands.length === 0 ? (
          <div className="space-y-4">
            <div className="flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
              <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
              <p className="text-label text-[var(--nx-text)]">Novedades</p>
            </div>
            <Surface className="p-6" style={{ backgroundColor: 'oklch(97% 0.006 80)' }}>
              <NexoChatBubble message="No hay novedades para mostrar." />
            </Surface>
          </div>
        ) : (
          <>
            <div className="mb-5 flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
              <div className="h-6 w-0.5 rounded-full bg-[var(--nx-accent)]" />
              <p className="text-label text-[var(--nx-text)]">Atajos disponibles</p>
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {filteredCommands.map((cmd) => {
                const ts = CMD_TONE_STYLES[cmd.tone] || CMD_TONE_STYLES.accent;
                return (
                  <button
                    key={cmd.id}
                    onClick={() => setActiveCommand(cmd)}
                    className={`flex flex-col p-5 rounded-panel border ${ts.bg} ${ts.border} hover:shadow-medium transition-all duration-fast text-left`}
                  >
                    <div className="flex items-start justify-between">
                      <div className={`flex h-10 w-10 items-center justify-center rounded-control ${ts.icon}`}>
                        <cmd.icon size={20} />
                      </div>
                      <ChevronRight size={18} className="text-[var(--nx-text-muted)]" />
                    </div>
                    <p className="mt-4 text-h3 text-[var(--nx-text)]">{cmd.title}</p>
                  </button>
                );
              })}
            </div>
          </>
        )
      ) : (
        <CommandForm
          command={activeCommand}
          groups={groups}
          students={students}
          onClose={() => setActiveCommand(null)}
          fetchError={fetchError}
        />
      )}
    </div>
  );
};

const CommandForm = ({ command, groups, students, onClose, fetchError }) => {
  const [form, setForm] = useState({});
  const [targetUsers, setTargetUsers] = useState([]);
  const [loadingUsers, setLoadingUsers] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [result, setResult] = useState(null);
  const [deliveryStatus, setDeliveryStatus] = useState(null);

  const steps = ['Comando', 'Detalles', 'Resultado'];
  const currentStep = result ? 2 : 1;

  const updateField = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  useEffect(() => {
    if (command.fields.includes('targets') && form.targetRole) {
      setLoadingUsers(true);
      usersApi.getByRole(form.targetRole, false)
        .then((res) => setTargetUsers(res.data || []))
        .catch(() => setTargetUsers([]))
        .finally(() => setLoadingUsers(false));
    }
  }, [form.targetRole, command.fields]);

  const filteredStudents = form.group
    ? students.filter((s) => (s.group_name || s.group) === form.group || `${s.group_name || ''}`.toLowerCase().includes(form.group.toLowerCase()))
    : form.grade
      ? students.filter((s) => {
          const gName = s.group_name || s.group || '';
          return String(gName).startsWith(form.grade);
        })
      : students;

  const pollTwilio = (msgIds) => {
    let attempts = 0;
    const interval = setInterval(async () => {
      attempts++;
      try {
        const res = await operationsApi.checkTwilioStatus(msgIds);
        if (res?.data) {
          const allSent = res.data.every((m) => ['SENT', 'DELIVERED', 'READ'].includes(m.delivery_status));
          const anyFailed = res.data.some((m) => m.delivery_status?.startsWith('FAILED'));
          if (allSent) {
            setDeliveryStatus('Mensajes entregados');
            clearInterval(interval);
          }
          if (anyFailed || attempts > 15) {
            setDeliveryStatus('Algunos mensajes pudieron fallar');
            clearInterval(interval);
          }
        }
      } catch (e) {
        clearInterval(interval);
      }
    }, 2000);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setIsSubmitting(true);
    setResult(null);
    setDeliveryStatus(null);
    try {
      const payload = { ...form };
      if (command.fields.includes('student')) payload.student_id = form.student;
      if (command.fields.includes('group')) {
        payload.group = form.group;
        payload.group_name = form.group;
      }
      if (command.fields.includes('targets')) payload.targets = form.targets?.split(',').map((t) => t.trim()).filter(Boolean) || [];
      if (command.fields.includes('timeStart')) payload.timeStart = form.timeStart || null;
      if (command.fields.includes('timeEnd')) payload.timeEnd = form.timeEnd || null;

      if (command.id === 'solicitud') {
        if (!form.message || !form.message.trim()) {
          setResult({ variant: 'danger', message: 'El mensaje no puede estar vacío.' });
          setIsSubmitting(false);
          return;
        }
        if (!form.targets || !form.targets.trim()) {
          setResult({ variant: 'danger', message: 'Debes seleccionar al menos un destinatario.' });
          setIsSubmitting(false);
          return;
        }
        const ids = form.targets.split(',').filter(Boolean);
        payload.recipient_id = ids[0] || null;
      }

      let result;
      switch (command.id) {
        case 'situacion_critica': result = await operationsApi.execute('situacion_critica', payload, '/operations/situacion_critica'); break;
        case 'citar':       result = await operationsApi.citacion(payload); break;
        case 'autorizar':   result = await operationsApi.salida(payload); break;
        case 'permiso':     result = await operationsApi.permiso(payload); break;
        case 'solicitud':   result = await operationsApi.execute('solicitud', payload, '/operations/solicitud'); break;
        case 'seguimiento': result = await operationsApi.execute('seguimiento', payload, '/operations/seguimiento'); break;
        case 'daño':        result = await operationsApi.execute('daño', payload, '/operations/daño'); break;
        case 'pedagogica':  result = await operationsApi.execute('pedagogica', payload, '/operations/pedagogica'); break;
        case 'horario':     result = await operationsApi.execute('horario', payload, '/operations/horario'); break;
        case 'incidente':   result = await operationsApi.execute('incidente', payload, '/operations/incidente'); break;
        case 'fusionar_bloque': result = await operationsApi.execute('fusionar_bloque', payload, '/operations/fusionar_bloque'); break;
        case 'extender_bloque': result = await operationsApi.execute('extender_bloque', payload, '/operations/extender_bloque'); break;
        default: throw new Error('Comando no soportado');
      }

      setResult({ variant: 'success', message: result?.message || 'Operación exitosa' });
      if (result?.message_ids?.length) pollTwilio(result.message_ids);

      // Integración edge: al autorizar salida, notificar al lector biométrico
      // para que registre SALIDA_AUTORIZADA en el dispositivo (best-effort).
      if (command.id === 'autorizar') {
        try {
          const student = students.find((s) => (s.student_id || s.id) === form.student);
          const doc = student?.document || student?.documento;
          if (doc) {
            const devices = await devicesApi.getAll();
            const device = devices.find((d) => d.active) || devices[0];
            if (device) {
              await devicesApi.authorizeExit(device.device_id, String(doc));
              setDeliveryStatus((prev) => prev || 'Salida notificada al lector biométrico');
            }
          }
        } catch { /* la autorización ya quedó registrada en la nube */ }
      }
    } catch (error) {
      setResult({ variant: 'danger', message: humanizeError(error, 'Error al ejecutar el comando') });
    } finally {
      setIsSubmitting(false);
    }
  };

  const cmdTone = CMD_TONE_STYLES[command.tone] || CMD_TONE_STYLES.accent;

  const renderField = (field) => {
    if (field === 'grade') {
      return <SearchableSelect key={field} label={FIELD_LABELS[field]} options={GRADO_OPTIONS} value={form.grade || ''} onChange={(v) => { updateField('grade', v); updateField('group', ''); updateField('student', ''); }} placeholder="Todos los grados" searchPlaceholder="Buscar grado…" clearable />;
    }
    if (field === 'group') {
      const groupLabel = (g) => g?.name || g?.group_name || g;
      const filteredGroups = form.grade
        ? groups.filter((g) => {
            const gName = groupLabel(g);
            return String(gName).startsWith(form.grade) || g.grade_level === form.grade;
          })
        : groups;
      const options = filteredGroups.map((g) => ({ value: groupLabel(g), label: formatGroupName(groupLabel(g)) }));
      return <SearchableSelect key={field} label={FIELD_LABELS[field]} options={options} value={form.group || ''} onChange={(v) => { updateField('group', v); updateField('student', ''); }} placeholder="— Seleccionar grupo —" searchPlaceholder="Buscar grupo…" clearable />;
    }
    if (field === 'student') {
      const options = filteredStudents.map((s) => ({
        value: s.student_id || s.id,
        label: `${s.last_name || ''} ${s.first_name || ''}`.trim() || s.student_id,
        sublabel: (s.group_name || s.group) ? formatGroupName(s.group_name || s.group) : (s.document_number ? `Doc: ${s.document_number}` : '')
      }));
      return <SearchableSelect key={field} label={FIELD_LABELS[field]} options={options} value={form.student || ''} onChange={(v) => updateField('student', v)} placeholder="— Seleccionar estudiante —" searchPlaceholder="Buscar estudiante…" clearable />;
    }
    if (field === 'targetRole') {
      const roles = Object.entries(ROLES).map(([k, v]) => ({ value: v, label: getRoleDisplay(v) || k }));
      return <SearchableSelect key={field} label={FIELD_LABELS[field]} options={roles} value={form.targetRole || ''} onChange={(v) => updateField('targetRole', v)} placeholder="— Seleccionar rol —" searchPlaceholder="Buscar rol…" clearable />;
    }
    if (field === 'targets') {
      const isIncident = command.id === 'incidente';
      const incidentOptions = [
        { value: 'padre', label: 'Acudiente' },
        { value: 'rector', label: 'Rectoría' },
        { value: 'coordinacion', label: 'Coordinación' },
      ];
      let options;
      if (isIncident) {
        options = incidentOptions;
      } else {
        if (loadingUsers) return <div className="h-20 w-full nx-skeleton rounded-control" aria-hidden />;
        if (!form.targetRole) return <div className="rounded-control bg-[var(--nx-surface-subtle)] px-4 py-3 text-body-sm text-[var(--nx-text-muted)]">Selecciona primero un rol para ver los destinatarios.</div>;
        options = targetUsers.map((u) => ({ value: u.user_id || u.id, label: `${u.first_name || ''} ${u.last_name || ''}`.trim() || u.email || u.user_id }));
      }
      const value = (form.targets || '').split(',').filter(Boolean);
      return (
        <SearchableSelect
          key={field}
          label={FIELD_LABELS[field]}
          options={options}
          value={value}
          onChange={(arr) => updateField('targets', Array.isArray(arr) ? arr.join(',') : arr)}
          multiple
          clearable
          placeholder="Seleccionar destinatarios…"
          searchPlaceholder="Buscar destinatario…"
          emptyText="Sin destinatarios"
        />
      );
    }
    if (field === 'message' || field === 'description' || field === 'reason') {
      return <Textarea key={field} label={FIELD_LABELS[field]} value={form[field] || ''} onChange={(e) => updateField(field, e.target.value)} rows={3} />;
    }
    if (field === 'date') {
      return <Input key={field} type="date" label={FIELD_LABELS[field]} value={form[field] || ''} onChange={(e) => updateField(field, e.target.value)} />;
    }
    if (field === 'time' || field === 'timeStart' || field === 'timeEnd') {
      return <Input key={field} type="time" label={FIELD_LABELS[field]} value={form[field] || ''} onChange={(e) => updateField(field, e.target.value)} required />;
    }
    return <Input key={field} label={FIELD_LABELS[field]} value={form[field] || ''} onChange={(e) => updateField(field, e.target.value)} />;
  };

  return (
    <div className="max-w-3xl mx-auto space-y-6">
      <Stepper steps={steps} current={currentStep} />

      {result ? (
        <OperationResult
          variant={result.variant}
          title={result.variant === 'success' ? 'Operación completada' : 'No se pudo completar'}
          message={result.message}
          deliveryStatus={deliveryStatus}
          recipients={result.recipients}
          onPrimary={() => { setResult(null); setForm({}); setDeliveryStatus(null); }}
          primaryLabel="Repetir operación"
          onSecondary={onClose}
          secondaryLabel="Volver"
        />
      ) : (
        <Card className="p-6">
          <div className="flex items-center gap-3 mb-6">
            <div className={`flex h-10 w-10 items-center justify-center rounded-control ${cmdTone.icon}`}>
              <command.icon size={20} />
            </div>
            <div>
              <p className="text-h2 text-[var(--nx-text)]">{command.title}</p>
              {command.warning && (
                <div className="mt-2 flex items-center gap-2 rounded-control bg-[var(--nx-subtle-bg-warning)] px-3 py-2">
                  <AlertOctagon size={14} className="shrink-0 text-[var(--nx-warning)]" />
                  <p className="text-body-sm text-[var(--nx-warning)]">{command.warning}</p>
                </div>
              )}
            </div>
          </div>

          {fetchError && (
            <div className="mb-4 flex items-center gap-3 rounded-control border border-[var(--nx-border-danger)] bg-[var(--nx-subtle-bg-danger)] px-4 py-3" role="alert">
              <AlertOctagon size={18} className="shrink-0 text-[var(--nx-danger)]" />
              <p className="text-body-sm text-[var(--nx-danger)]">{fetchError}</p>
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-5">
            {command.fields.map((field) => renderField(field))}

            {deliveryStatus && (
              <div className="flex items-center gap-2 rounded-control bg-[var(--nx-surface-subtle)] px-4 py-3">
                <Loader2 size={14} className="animate-spin text-[var(--nx-accent)]" />
                <p className="text-body-sm text-[var(--nx-text-muted)]">{deliveryStatus}</p>
              </div>
            )}

            <div className="flex flex-col-reverse sm:flex-row gap-3 pt-2">
              <Button variant="secondary" type="button" onClick={onClose} className="w-full sm:w-auto">Cancelar</Button>
              <Button type="submit" loading={isSubmitting} variant="primary" className="w-full sm:w-auto">
                {command.id === 'situacion_critica' ? 'Enviar alerta' : 'Ejecutar'}
              </Button>
            </div>
          </form>
        </Card>
      )}
    </div>
  );
};

export default Operation;
