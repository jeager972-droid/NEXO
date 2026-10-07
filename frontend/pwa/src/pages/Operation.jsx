/**
 * SCR-OPS-01 Operation · SCR-OPS-02 Flujo · SCR-OPS-03 Resultado
 * DEC-FE-08: flujo guiado de 3 pasos con Stepper.
 * Usa PageHeader, Stepper, OperationResult, humanizeError.
 */
import { useState, useEffect, useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useAuth } from '../hooks/useAuth';
import {
  AlertOctagon,
  ChevronRight, Loader2,
} from 'lucide-react';
import { operationsApi } from '../api/operations';
import { studentsApi } from '../api/students';

import { usersApi } from '../api/users';
import { ROLES, getRoleDisplay } from '../config/roles';
import { OPERATIONS_CATALOG as COMMANDS_CATALOG, normalizeCmdTitle } from '../config/operations';
import { Surface } from '../components/ui/Surface';
import { Card } from '../components/ui/Card';
import { Input, Textarea } from '../components/ui/Input';
import { Button } from '../components/ui/Button';
import { SearchableSelect } from '../components/ui/SearchableSelect';
import { SkeletonCards } from '../components/ui/Skeleton';
import { Stepper } from '../components/ui/Stepper';
import { OperationResult } from '../components/patterns/OperationResult';
import { humanizeError } from '../utils/messages';
import { formatGroupName } from '../utils/groupFormat';
import { GRADO_OPTIONS } from '../config/grados';
import { NexoChatBubble } from '../components/patterns/NexoChat';

const CMD_TONE_STYLES = {
  accent:  { bg: 'bg-[var(--nx-surface-accent)]', icon: 'bg-[var(--nx-icon-bg-accent)] text-[color-mix(in_oklch,var(--nx-accent)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-accent)]' },
  warning: { bg: 'bg-[var(--nx-surface-warning)]', icon: 'bg-[var(--nx-icon-bg-warning)] text-[color-mix(in_oklch,var(--nx-warning)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-warning)]' },
  danger:  { bg: 'bg-[var(--nx-surface-danger)]', icon: 'bg-[var(--nx-icon-bg-danger)] text-[color-mix(in_oklch,var(--nx-danger)_72%,var(--nx-icon-mix))]', border: 'border-[var(--nx-danger)]' },
};

const FIELD_LABELS = {
  group: 'Grupo', student: 'Estudiante', grade: 'Grado', date: 'Fecha', time: 'Hora',
  timeStart: 'Hora de salida', timeEnd: 'Hora de retorno',
  message: 'Mensaje', reason: 'Motivo', location: 'Ubicación', description: 'Descripción',
  targetRole: 'Rol destinatario', targets: 'Destinatario',
  dependency: 'Dependencia responsable', assignee: 'Persona responsable',
};

// Dependencia del caso → rol del que se cargan los responsables elegibles.
const DEPENDENCY_ROLES = {
  coordinacion: 'COORDINATOR',
  psicoorientacion: 'COUNSELOR',
  rectoria: 'RECTOR',
  docencia: 'TEACHER',
};
const DEPENDENCY_OPTIONS = [
  { value: 'coordinacion', label: 'Coordinación' },
  { value: 'psicoorientacion', label: 'Psicoorientación' },
  { value: 'rectoria', label: 'Rectoría' },
  { value: 'docencia', label: 'Docencia' },
];

// Borrador por usuario: si sales de /operacion a mitad de formulario, al
// volver se restaura el comando y lo ya diligenciado — no se «devuelve al
// inicio». Se limpia al enviar con éxito o al cerrar el formulario.
const DRAFT_KEY = (uid) => `nx:operation:draft:${uid || 'anon'}`;
const readDraft = (uid) => {
  try { return JSON.parse(localStorage.getItem(DRAFT_KEY(uid)) || 'null'); } catch { return null; }
};

const Operation = () => {
  const { user } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const uid = user?.user_id || user?.id;
  const [draft] = useState(() => readDraft(uid));
  const [activeCommand, setActiveCommand] = useState(() =>
    draft?.cmdId ? (COMMANDS_CATALOG.find((c) => c.id === draft.cmdId) ?? null) : null);
  const [preselect, setPreselect] = useState(draft?.preselect || null);
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
      // match tolerante (mayúsculas/tildes) — el chat puede emitir una
      // variante del título del catálogo; sin esto el chip caía en la
      // sección genérica en vez de abrir el formulario.
      const norm = normalizeCmdTitle(cmdTitle);
      const found = filteredCommands.find((c) => normalizeCmdTitle(c.title) === norm);
      if (found) {
        // Capturar TODOS los params antes de limpiar la URL — el chip del
        // chat trae student/group pre-cargados («cita al acudiente de X»)
        const sid = searchParams.get('student');
        const grp = searchParams.get('group');
        // Las notificaciones llevan contexto propio (motivo/dependencia)
        // además de estudiante y grupo — todo llega pre-llenado.
        const reason = searchParams.get('reason');
        const dep = searchParams.get('dependency');
        const extra = {};
        if (reason) { extra.reason = reason; extra.description = reason; extra.message = reason; }
        if (dep) extra.dependency = dep;
        if (sid || grp || reason || dep) setPreselect({ student: sid || '', group: grp || '', extra });
        setActiveCommand(found);
      }
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
          <Surface className="p-6">
            <NexoChatBubble message="No hay operaciones disponibles para tu rol en este momento." />
          </Surface>
        ) : (
          <>
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
                    {cmd.desc && <p className="mt-1 text-caption text-[var(--nx-text-muted)]">{cmd.desc}</p>}
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
          preselect={preselect}
          uid={uid}
          onClose={() => { setActiveCommand(null); setPreselect(null); try { localStorage.removeItem(DRAFT_KEY(uid)); } catch {} }}
          fetchError={fetchError}
        />
      )}
    </div>
  );
};

const CommandForm = ({ command, groups, students, preselect, uid, onClose, fetchError }) => {
  const [form, setForm] = useState(() => {
    const d = readDraft(uid);
    return d?.cmdId === command.id && d?.form ? d.form : {};
  });
  const [targetUsers, setTargetUsers] = useState([]);
  const [loadingUsers, setLoadingUsers] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [result, setResult] = useState(null);
  const [deliveryStatus, setDeliveryStatus] = useState(null);

  const steps = ['Comando', 'Detalles', 'Resultado'];
  const currentStep = result ? 2 : 1;

  const updateField = (field, value) => setForm((f) => ({ ...f, [field]: value }));

  // Deep-link del chat: pre-rellena estudiante (y su grupo/grado) para que
  // «cita al acudiente de Tomás» abra el formulario ya apuntando a Tomás.
  useEffect(() => {
    if (!preselect || !students.length) return;
    setForm((f) => {
      const next = { ...f };
      if (preselect.student) {
        const st = students.find((s) => (s.student_id || s.id) === preselect.student);
        if (st) {
          next.student = preselect.student;
          const g = st.group_name || st.group || '';
          if (g) next.group = g;
          const grade = String(g).match(/^(\d+)/)?.[1];
          if (grade) next.grade = grade;
        } else {
          next.student = preselect.student;
        }
      } else if (preselect.group) {
        next.group = preselect.group;
      }
      // Campos libres que la notificación pre-llena (motivo, dependencia…)
      if (preselect.extra) {
        for (const [k, v] of Object.entries(preselect.extra)) {
          if (v && command.fields.includes(k) && !next[k]) next[k] = v;
        }
      }
      return next;
    });
  }, [preselect, students]); // eslint-disable-line react-hooks/exhaustive-deps

  // Borrador persistente: salir a otra sección no pierde lo diligenciado.
  useEffect(() => {
    try {
      localStorage.setItem(DRAFT_KEY(uid), JSON.stringify({ cmdId: command.id, form, preselect }));
    } catch { /* storage lleno — el borrador es best-effort */ }
  }, [command.id, form, preselect, uid]);

  useEffect(() => {
    const wantsUsers = command.fields.includes('targets') && form.targetRole;
    const wantsAssignee = command.fields.includes('assignee') && form.dependency;
    if (!wantsUsers && !wantsAssignee) return;
    // Docente: el responsable se filtra por su jornada (colegas de su turno);
    // otros roles ven el directorio completo de la dependencia.
    const sameShift = user?.role === ROLES.DOCENTE;
    const role = wantsAssignee ? DEPENDENCY_ROLES[form.dependency] : form.targetRole;
    if (!role) return;
    setLoadingUsers(true);
    usersApi.getByRole(role, sameShift)
      .then((res) => setTargetUsers(res.data || []))
      .catch(() => setTargetUsers([]))
      .finally(() => setLoadingUsers(false));
  }, [form.targetRole, form.dependency, command.fields, user]);

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
      if (command.fields.includes('dependency')) payload.dependency = form.dependency || null;
      if (command.fields.includes('assignee')) payload.assigned_to_user_id = form.assignee || null;

      let result;
      switch (command.id) {
        case 'situacion_critica': result = await operationsApi.execute('situacion_critica', payload, '/operations/situacion_critica'); break;
        case 'citar':       result = await operationsApi.citacion(payload); break;
        case 'autorizar':   result = await operationsApi.salida(payload); break;
        case 'permiso':     result = await operationsApi.permiso(payload); break;
        case 'seguimiento': result = await operationsApi.execute('seguimiento', payload, '/operations/seguimiento'); break;
        case 'daño':        result = await operationsApi.execute('daño', payload, '/operations/daño'); break;
        case 'pedagogica':  result = await operationsApi.execute('pedagogica', payload, '/operations/pedagogica'); break;
        case 'horario':     result = await operationsApi.execute('horario', payload, '/operations/horario'); break;
        case 'incidente':   result = await operationsApi.execute('incidente', payload, '/operations/incidente'); break;
        case 'fusionar_bloque': result = await operationsApi.execute('fusionar_bloque', payload, '/operations/fusionar_bloque'); break;
        case 'extender_bloque': result = await operationsApi.execute('extender_bloque', payload, '/operations/extender_bloque'); break;
        case 'registro_manual': result = await operationsApi.execute('registro_manual', payload, '/operations/registro_manual'); break;
        default: throw new Error('Comando no soportado');
      }

      setResult({ variant: 'success', message: result?.message || 'Operación exitosa' });
      if (result?.message_ids?.length) pollTwilio(result.message_ids);
      try { localStorage.removeItem(DRAFT_KEY(uid)); } catch {}

      // La integración edge para autorizar_salida la maneja el backend:
      // el API crea la autorización PENDING_FINGERPRINT y envía WAIT_EXIT_FINGERPRINT
      // al sensor de coordinación. El estudiante debe poner su huella allí.
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
    if (field === 'dependency') {
      return (
        <SearchableSelect
          key={field}
          label={FIELD_LABELS[field]}
          options={DEPENDENCY_OPTIONS}
          value={form.dependency || ''}
          onChange={(v) => { updateField('dependency', v); updateField('assignee', ''); }}
          placeholder="— Seleccionar dependencia —"
          clearable
        />
      );
    }
    if (field === 'assignee') {
      if (!form.dependency) {
        return (
          <div key={field} className="rounded-control bg-[var(--nx-surface-subtle)] px-4 py-3 text-body-sm text-[var(--nx-text-muted)]">
            Selecciona primero la dependencia para ver las personas responsables.
          </div>
        );
      }
      if (loadingUsers) return <div key={field} className="h-20 w-full nx-skeleton rounded-control" aria-hidden />;
      const options = targetUsers.map((u) => ({
        value: u.user_id || u.id,
        label: `${u.first_name || ''} ${u.last_name || ''}`.trim() || u.email || u.user_id,
        sublabel: u.work_shift ? `Jornada ${u.work_shift}` : '',
      }));
      return (
        <SearchableSelect
          key={field}
          label={FIELD_LABELS[field]}
          options={options}
          value={form.assignee || ''}
          onChange={(v) => updateField('assignee', v || '')}
          clearable
          placeholder="Sin responsable específico — lo recibe la dependencia"
          searchPlaceholder="Buscar persona…"
          emptyText="Sin personas en esa dependencia"
        />
      );
    }
    if (field === 'targets') {
      // Solo «incidente» lo usa hoy: destinatarios de la notificación
      // (acudiente/rectoría/coordinación), no personas.
      const options = [
        { value: 'padre', label: 'Acudiente' },
        { value: 'rector', label: 'Rectoría' },
        { value: 'coordinacion', label: 'Coordinación' },
      ];
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
            <div className="mb-4 flex items-center gap-3 rounded-control border border-[var(--nx-danger)] bg-[var(--nx-subtle-bg-danger)] px-4 py-3" role="alert">
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
