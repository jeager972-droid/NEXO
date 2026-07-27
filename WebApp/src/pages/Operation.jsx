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
  ChevronRight, Loader2, FileText
} from 'lucide-react';
import { operationsApi } from '../api/operations';
import { studentsApi } from '../api/students';
import { usersApi } from '../api/users';
import { ROLES, getRoleDisplay } from '../config/roles';
import { PageHeader } from '../components/ui/Surface';
import { Card } from '../components/ui/Card';
import { Input, Textarea } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { Select } from '../components/ui/Select';
import { SearchableSelect } from '../components/ui/SearchableSelect';
import { SkeletonCards } from '../components/ui/Skeleton';
import { Stepper } from '../components/ui/Stepper';
import { OperationResult } from '../components/patterns/OperationResult';
import { humanizeError } from '../utils/messages';

const COMMANDS_CATALOG = [
  { id: 'citar',       title: 'Citar acudiente',     icon: Calendar,   roles: [ROLES.COORDINADOR, ROLES.DOCENTE, ROLES.PSICORIENTADOR], fields: ['group', 'student', 'date', 'time', 'message'] },
  { id: 'autorizar',   title: 'Autorizar salida',    icon: ShieldCheck,roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['group', 'student', 'reason'] },
  { id: 'sos',         title: 'SOS',                 icon: AlertOctagon,roles: Object.values(ROLES), fields: ['location', 'message'] },
  { id: 'daño',        title: 'Reportar daño',       icon: Wrench,     roles: [ROLES.AUXILIAR, ROLES.PORTERO], fields: ['location', 'description'] },
  { id: 'solicitud',   title: 'Mandar solicitud',    icon: Send,       roles: Object.values(ROLES), fields: ['targetRole', 'targets', 'message'] },
  { id: 'seguimiento', title: 'Solicitar seguimiento',icon: FileText,  roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['group', 'student', 'reason'] },
  { id: 'pedagogica',  title: 'Salida pedagógica',   icon: Bus,        roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['group', 'reason'] },
  { id: 'horario',     title: 'Cambio de horario',   icon: Clock,      roles: [ROLES.COORDINADOR, ROLES.RECTOR], fields: ['group', 'reason', 'time'], warning: 'Este comando avisará a todos los padres de familia del grupo elegido.' },
  { id: 'permiso',     title: 'Generar permiso',     icon: UserCheck,  roles: [ROLES.DOCENTE, ROLES.COORDINADOR, ROLES.RECTOR], fields: ['group', 'student', 'reason', 'timeRange'] },
  { id: 'incidente',   title: 'Reportar incidente',  icon: ShieldAlert,roles: [ROLES.DOCENTE, ROLES.PSICORIENTADOR], fields: ['group', 'student', 'location', 'message', 'targets'] },
];

const FIELD_LABELS = {
  group: 'Grupo', student: 'Estudiante', date: 'Fecha', time: 'Hora', timeRange: 'Rango de horas',
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
    .filter((cmd) => userRole && cmd.roles.includes(userRole))
    .sort((a, b) => {
      if (a.id === 'sos') return 1;
      if (b.id === 'sos') return -1;
      return 0;
    }), [userRole]);

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
        <PageHeader eyebrow="Comandos institucionales" title="Operaciones" subtitle="Selecciona un comando institucional" />
        <SkeletonCards count={skeletonCount} />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Comandos institucionales"
        title="Operaciones"
        subtitle={activeCommand ? activeCommand.title : 'Selecciona un comando institucional'}
      />

      {!activeCommand ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {filteredCommands.map((cmd) => (
            <Card key={cmd.id} asAction onClick={() => setActiveCommand(cmd)} className="p-5">
              <div className="flex items-start justify-between">
                <div className="flex h-10 w-10 items-center justify-center rounded-control bg-[color-mix(in_oklch,var(--nx-accent)_12%,transparent)]">
                  <cmd.icon size={20} className="text-[var(--nx-accent)]" />
                </div>
                <ChevronRight size={18} className="text-[var(--nx-text-muted)]" />
              </div>
              <p className="mt-4 text-h3 text-[var(--nx-text)]">{cmd.title}</p>
            </Card>
          ))}
        </div>
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
      usersApi.getByRole(form.targetRole, true)
        .then((res) => setTargetUsers(res.data || []))
        .catch(() => setTargetUsers([]))
        .finally(() => setLoadingUsers(false));
    }
  }, [form.targetRole, command.fields]);

  const filteredStudents = form.group
    ? students.filter((s) => (s.group_name || s.group) === form.group || `${s.group_name || ''}`.toLowerCase().includes(form.group.toLowerCase()))
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
      if (command.fields.includes('timeRange')) {
        const tr = (form.timeRange || '').split('-').map((s) => s.trim());
        payload.timeStart = tr[0] || null;
        payload.timeEnd = tr[1] || null;
      }

      if (command.id === 'solicitud' && form.targets) {
        const ids = form.targets.split(',').filter(Boolean);
        payload.recipient_id = ids[0] || null;
      }

      let result;
      switch (command.id) {
        case 'sos':         result = await operationsApi.sos(payload); break;
        case 'citar':       result = await operationsApi.citacion(payload); break;
        case 'autorizar':   result = await operationsApi.salida(payload); break;
        case 'permiso':     result = await operationsApi.permiso(payload); break;
        case 'solicitud':   result = await operationsApi.execute('solicitud', payload, '/operations/solicitud'); break;
        case 'seguimiento': result = await operationsApi.execute('seguimiento', payload, '/operations/seguimiento'); break;
        case 'daño':        result = await operationsApi.execute('daño', payload, '/operations/daño'); break;
        case 'pedagogica':  result = await operationsApi.execute('pedagogica', payload, '/operations/pedagogica'); break;
        case 'horario':     result = await operationsApi.execute('horario', payload, '/operations/horario'); break;
        case 'incidente':   result = await operationsApi.execute('incidente', payload, '/operations/incidente'); break;
        default: throw new Error('Comando no soportado');
      }

      setResult({ variant: 'success', message: result?.message || 'Operación exitosa' });
      if (result?.message_ids?.length) pollTwilio(result.message_ids);
    } catch (error) {
      setResult({ variant: 'danger', message: humanizeError(error, 'Error al ejecutar el comando') });
    } finally {
      setIsSubmitting(false);
    }
  };

  const accent = 'var(--nx-accent)';

  const renderField = (field) => {
    if (field === 'group') {
      const groupLabel = (g) => g?.name || g?.group_name || g;
      const options = groups.map((g) => ({ value: groupLabel(g), label: groupLabel(g) }));
      return <SearchableSelect key={field} label={FIELD_LABELS[field]} options={options} value={form.group || ''} onChange={(v) => updateField('group', v)} placeholder="— Seleccionar grupo —" searchPlaceholder="Buscar grupo…" clearable />;
    }
    if (field === 'student') {
      const options = filteredStudents.map((s) => ({ value: s.student_id || s.id, label: `${s.last_name || ''} ${s.first_name || ''}`.trim() || s.student_id }));
      return <SearchableSelect key={field} label={FIELD_LABELS[field]} options={options} value={form.student || ''} onChange={(v) => updateField('student', v)} placeholder="— Seleccionar estudiante —" searchPlaceholder="Buscar estudiante…" clearable />;
    }
    if (field === 'targetRole') {
      const roles = Object.entries(ROLES).map(([k, v]) => ({ value: v, label: getRoleDisplay(v) || k }));
      return <Select key={field} label={FIELD_LABELS[field]} options={[{ value: '', label: '— Seleccionar rol —' }, ...roles]} value={form.targetRole || ''} onChange={(e) => updateField('targetRole', e.target.value)} />;
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
      return (
        <div key={field} className="space-y-1.5">
          <label className="block text-label text-[var(--nx-text)]">{FIELD_LABELS[field]}</label>
          <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-2 max-h-32 overflow-y-auto space-y-1">
            {options.map((o) => (
              <label key={o.value} className="flex items-center gap-2 text-body-sm text-[var(--nx-text)]">
                <input
                  type="checkbox"
                  checked={(form.targets || '').split(',').includes(o.value)}
                  onChange={(e) => {
                    const current = (form.targets || '').split(',').filter(Boolean);
                    const next = e.target.checked ? [...current, o.value] : current.filter((v) => v !== o.value);
                    updateField('targets', next.join(','));
                  }}
                  className="accent-[var(--nx-accent)]"
                />
                {o.label}
              </label>
            ))}
          </div>
        </div>
      );
    }
    if (field === 'message' || field === 'description' || field === 'reason') {
      return <Textarea key={field} label={FIELD_LABELS[field]} value={form[field] || ''} onChange={(e) => updateField(field, e.target.value)} rows={3} />;
    }
    if (field === 'date') {
      return <Input key={field} type="date" label={FIELD_LABELS[field]} value={form[field] || ''} onChange={(e) => updateField(field, e.target.value)} />;
    }
    if (field === 'time') {
      return <Input key={field} type="time" label={FIELD_LABELS[field]} value={form[field] || ''} onChange={(e) => updateField(field, e.target.value)} />;
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
          primaryLabel="Nueva operación"
          onSecondary={onClose}
          secondaryLabel="Volver al inicio"
        />
      ) : (
        <Card className="p-6">
          <div className="flex items-center gap-3 mb-6">
            <div className="flex h-10 w-10 items-center justify-center rounded-control" style={{ backgroundColor: `color-mix(in oklch, ${accent} 12%, transparent)` }}>
              <command.icon size={20} style={{ color: accent }} />
            </div>
            <div>
              <p className="text-h2 text-[var(--nx-text)]">{command.title}</p>
              {command.warning && (
                <div className="mt-2 flex items-center gap-2 rounded-control bg-[color-mix(in_oklch,var(--nx-warning)_10%,transparent)] px-3 py-2">
                  <AlertOctagon size={14} className="shrink-0 text-[var(--nx-warning)]" />
                  <p className="text-body-sm text-[var(--nx-warning)]">{command.warning}</p>
                </div>
              )}
            </div>
          </div>

          {fetchError && (
            <div className="mb-4 flex items-center gap-3 rounded-control border border-[color-mix(in_oklch,var(--nx-danger)_30%,var(--nx-border))] bg-[color-mix(in_oklch,var(--nx-danger)_8%,transparent)] px-4 py-3" role="alert">
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
                {command.id === 'sos' ? 'Enviar alerta' : 'Ejecutar'}
              </Button>
            </div>
          </form>
        </Card>
      )}
    </div>
  );
};

export default Operation;
