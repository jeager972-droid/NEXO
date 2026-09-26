/**
 * OnboardingFlow — configuración inicial guiada por Nexus.
 *
 * Reemplaza los modales OnboardingSchedule/Groups/Risk: en vez de una
 * sobre-pantalla, el onboarding ES la pantalla — nada del sistema se
 * muestra hasta completar (o quedar pendiente de otro rol).
 *
 * Flujo por rol:
 *   RECTOR/COORDINATOR: welcome → jornadas → grupos* → riesgo → listo
 *     *grupos editable solo RECTOR; COORDINATOR lo ve como pendiente.
 *   TEACHER: welcome → criterios de aviso (opcional) → listo
 *
 * Nexus habla desde la esquina SOLO después de "Comenzar" — la
 * bienvenida es silenciosa. La topbar solo muestra "Paso N de M".
 */
import { useEffect, useMemo, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { clsx } from 'clsx';
import { NexoAvatar } from '../../components/patterns/NexoChat';
import { Clock, Layers, BellRing, X, MessageCircle } from 'lucide-react';
import { useAuth } from '../../hooks/useAuth';
import { Button } from '../../components/ui/Button';
import { Input } from '../../components/ui/Input';
import { Select } from '../../components/ui/Select';
import { SearchableSelect } from '../../components/ui/SearchableSelect';
import { NexusGuide } from '../../components/patterns/NexusGuide';
import { schoolApi } from '../../api/school';
import { riskApi } from '../../api/risk';
import { teacherApi } from '../../api/teacher';
import { chatApi } from '../../api/chat';
import { ROLES } from '../../config/roles';
import { humanizeError } from '../../utils/messages';

const EASE = [0.22, 1, 0.36, 1];
const SHIFTS = ['mañana', 'tarde', 'noche', 'completa'];
const SHIFT_LABEL = { 'mañana': 'Mañana', 'tarde': 'Tarde', 'noche': 'Noche', 'completa': 'Jornada completa' };
const GRADES = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11'];
const LEVELS = ['LEVE', 'MODERADA', 'ALTA', 'MUY_ALTA'];
const LEVEL_LABEL = { LEVE: 'Leve', MODERADA: 'Moderada', ALTA: 'Alta', MUY_ALTA: 'Muy alta' };
// barra lateral por nivel — la misma semántica de color del sistema
const LEVEL_EDGE = { LEVE: 'var(--nx-accent)', MODERADA: 'var(--nx-warning)', ALTA: 'var(--nx-danger)', MUY_ALTA: 'var(--nx-danger)' };
const RULE_KINDS = [
  { v: 'LATE', l: 'Llegadas tarde' },
  { v: 'ABSENCE', l: 'Inasistencias' },
  { v: 'EVASION', l: 'Salidas sin retorno' },
  { v: 'EXIT', l: 'Salidas del salón' },
  { v: 'PERMISSION_EXPIRY', l: 'Permisos vencidos' },
];

/* ── scripts de Nexus por paso ── */
const SCRIPTS = {
  scheduleA: [
    { text: 'Primero dime <b>qué jornadas</b> tiene tu institución. Puedes elegir más de una.' },
    { text: 'Con eso sé cuándo una llegada es <b>a tiempo</b>, cuándo es <b>tarde</b> y cuándo alguien <b>faltó</b>.' },
    { text: 'Marca las jornadas y toca <b>Continuar</b> — después configuramos cada una.' },
  ],
  scheduleB: [
    { text: 'Ahora configuramos <b>cada jornada por separado</b>: entrada, salida y descanso.' },
    { text: 'Si en una jornada los estudiantes <b>rotan de aula por bloques</b>, actívalo y dime los bloques con sus horas — así sé dónde debería estar cada grupo.' },
    { text: 'El descanso es opcional, pero ayuda: durante ese rango no cuento salidas como evasiones.' },
  ],
  groups: [
    { text: 'Ahora los <b>grupos</b>. Dime qué grados hay, cuántos grupos por grado y su jornada.' },
    { text: 'La <b>nomenclatura</b> es cómo se llaman: 7A, 7B… o 7-1, 7-2… Yo genero los nombres.' },
    { text: 'Cada grupo necesita <b>al menos un docente</b> — es quien recibe sus avisos. Abajo ves la vista previa; si falta alguien te aviso antes de guardar.' },
  ],
  risk: [
    { text: 'Último paso: <b>desde cuándo te aviso</b>. Una repetición se vuelve situación según estos umbrales.' },
    { text: 'Ejemplo: «3 llegadas tarde en 30 días» avisa al docente y a coordinación. La institución manda; yo solo detecto y aviso.' },
    { text: 'Los valores sugeridos son un punto de partida — se afinan siempre desde configuración.' },
  ],
  groupsPending: [
    { text: 'Este paso lo completa <b>rectoría</b>: los grados, los grupos y sus docentes.' },
    { text: 'Tu parte no se bloquea — el sistema se activa cuando ambos terminen. Continúa al último paso.' },
  ],
  rules: [
    { text: 'Aquí decides <b>desde cuándo te aviso</b> de repeticiones en tus clases.' },
    { text: 'Por ejemplo: «avísame cuando un estudiante acumule 3 llegadas tarde en 30 días». Yo detecto, tú decides.' },
    { text: 'Son solo para ti. Sin reglas propias, recibes los avisos generales de la institución.' },
  ],
};

/* ── helpers ── */
const groupNames = (grades, perGrade, nomenclature, sep = '-') => {
  const out = [];
  for (const g of grades) {
    const n = perGrade[g] || 0;
    for (let i = 0; i < n; i++) {
      out.push(nomenclature === 'alphabetic' ? g + String.fromCharCode(65 + i)
        : nomenclature === 'numeric' ? `${g}-${i + 1}` : `${g}${sep}${i + 1}`);
    }
  }
  return out;
};

/** Bloques equidistantes entre entrada y salida (excluye el descanso). */
const makeBlocks = (entry, exit, count, recessStart, recessEnd) => {
  const toMin = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
  const toHH = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
  const spans = [];
  let cur = toMin(entry);
  const end = toMin(exit);
  const rs = recessStart ? toMin(recessStart) : null, re = recessEnd ? toMin(recessEnd) : null;
  while (cur < end && spans.length < count) {
    const len = Math.floor((end - toMin(entry) - (rs !== null && cur < rs ? Math.max(0, re - rs) : 0)) / count);
    let bStart = cur, bEnd = Math.min(cur + Math.max(len, 20), end);
    if (rs !== null && bEnd > rs && bStart < re) { bEnd = rs; }
    spans.push({ start: toHH(bStart), end: toHH(bEnd) });
    cur = (rs !== null && bEnd === rs) ? re : bEnd;
  }
  while (spans.length < count) { const last = spans[spans.length - 1]; spans.push({ start: last ? last.end : entry, end: exit }); }
  return spans.slice(0, count);
};

/* ── piezas UI del flujo ── */
const StepHead = ({ kicker, title, lede }) => (
  <>
    {kicker && <span className="text-[12px] font-[650] uppercase tracking-[.04em] text-[var(--nx-accent)]">{kicker}</span>}
    <h1 className="text-[19px] sm:text-[20px] leading-snug font-[650] tracking-[-.01em] text-[var(--nx-text)]">{title}</h1>
    {lede && <p className="text-[13.5px] text-[var(--nx-text-muted)] max-w-[56ch]">{lede}</p>}
  </>
);

const Work = ({ children, spotlight }) => (
  <section className={clsx(
    'rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] p-5 sm:p-6',
    'flex flex-col gap-6 shadow-[var(--nx-shadow-medium)]', spotlight && 'nx-spotlight'
  )}>
    {children}
  </section>
);

const Stepper = ({ value, onChange, min = 0, max = 26, step = 1, label }) => (
  <div className="flex items-center gap-3">
    <button type="button" aria-label={`Disminuir ${label || ''}`} onClick={() => onChange(Math.max(min, value - step))}
      className="h-10 w-10 rounded-control border border-[var(--nx-border)] bg-[var(--nx-canvas)] text-lg font-semibold text-[var(--nx-text-muted)] transition-colors hover:border-[var(--nx-accent)] hover:text-[var(--nx-accent)]">−</button>
    <span className="min-w-[40px] text-center text-[15px] font-[650] tabular-nums">{value}</span>
    <button type="button" aria-label={`Aumentar ${label || ''}`} onClick={() => onChange(Math.min(max, value + step))}
      className="h-10 w-10 rounded-control border border-[var(--nx-border)] bg-[var(--nx-canvas)] text-lg font-semibold text-[var(--nx-text-muted)] transition-colors hover:border-[var(--nx-accent)] hover:text-[var(--nx-accent)]">+</button>
  </div>
);

const PickChip = ({ on, children, ...props }) => (
  <button type="button" {...props} className={clsx(
    'h-[46px] rounded-control border-[1.5px] font-semibold text-[14px] transition-all duration-150',
    on
      ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]'
      : 'border-[var(--nx-border)] bg-[var(--nx-canvas)] text-[var(--nx-text)] hover:border-[var(--nx-border-accent)]'
  )}>{children}</button>
);

const Switch = ({ checked, onChange, title, help }) => (
  <label className="flex items-center gap-4 py-1 cursor-pointer">
    <input type="checkbox" className="sr-only peer" checked={checked} onChange={(e) => onChange(e.target.checked)} />
    <span className="h-[26px] w-[46px] shrink-0 rounded-full bg-[var(--nx-border)] relative transition-colors duration-150 peer-checked:bg-[var(--nx-accent)] peer-focus-visible:outline-2 peer-focus-visible:outline-[var(--nx-accent)] peer-focus-visible:outline-offset-2 after:content-[''] after:absolute after:top-[3px] after:left-[3px] after:h-5 after:w-5 after:rounded-full after:bg-white after:shadow after:transition-transform after:duration-200 peer-checked:after:translate-x-5" />
    <span className="text-[13.5px] font-[550] text-[var(--nx-text)]">{title}
      {help && <span className="block text-[12px] font-normal text-[var(--nx-text-muted)]">{help}</span>}
    </span>
  </label>
);

/* ══════════════════════════ flujo ══════════════════════════ */
export default function OnboardingFlow({ role, missing = {}, onAllDone, simulate = false, mode = 'initial', onCancel }) {
  const { logout } = useAuth();
  const isUpdate = mode === 'update'; // re-configuración desde Configuración
  const isTeacher = role === ROLES.DOCENTE;
  const isRector = role === ROLES.RECTOR;

  // secuencia de pasos según rol + lo que falta
  const steps = useMemo(() => {
    if (isTeacher) return ['welcome', 'rules', 'done'];
    const s = ['welcome'];
    if (missing.schedule) s.push('schedule');
    if (missing.groups) s.push(isRector ? 'groups' : 'groupsPending');
    if (missing.risk) s.push('risk');
    if (missing.chat) s.push('chatpol');
    s.push('done');
    return s;
  }, [isTeacher, isRector, missing.schedule, missing.groups, missing.risk, missing.chat]);

  const [stepIdx, setStepIdx] = useState(0);
  const step = steps[stepIdx];
  const totalReal = steps.length - 2; // sin welcome ni done
  const realIdx = Math.min(Math.max(stepIdx, 0), steps.length - 2);

  const next = () => setStepIdx((i) => Math.min(i + 1, steps.length - 1));
  const done = () => onAllDone?.();

  /* ── estado del formulario ── */
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  // jornadas
  const [schedPhase, setSchedPhase] = useState('pick'); // pick | config
  const [pickedShifts, setPickedShifts] = useState([]);
  const [jIdx, setJIdx] = useState(0);
  const [jornadas, setJornadas] = useState([]);

  // grupos
  const [grades, setGrades] = useState(['6', '7', '8', '9', '10', '11']);
  const [perGrade, setPerGrade] = useState({ 6: 2, 7: 2, 8: 2, 9: 2, 10: 1, 11: 1 });
  const [gradeShifts, setGradeShifts] = useState({});
  const [nomenclature, setNomenclature] = useState('alphabetic');
  const [nomenSep, setNomenSep] = useState('-');
  const [teachers, setTeachers] = useState([]);
  const [assignments, setAssignments] = useState({});

  // riesgo
  const [riskCfg, setRiskCfg] = useState(null);
  // políticas del asistente Nexus
  const [chatPol, setChatPol] = useState({
    'chat.teacher.risk_students': true,
    'chat.teacher.student_fields': true,
    'chat.teacher.aggregates': true,
    'chat.teacher.derive_actions': true,
    'chat.smalltalk.enabled': true,
  });
  const [thresholds, setThresholds] = useState({
    LEVE: { n: 2, d: 30 }, MODERADA: { n: 3, d: 30 }, ALTA: { n: 2, d: 15 }, MUY_ALTA: { n: 1, d: 1 },
  });

  // reglas docente — todos los casos pre-cargados; el docente desactiva
  // los que no quiera en sus clases (no borra: solo no aplica)
  const [rules, setRules] = useState(() =>
    RULE_KINDS.map((k) => ({ kind: k.v, n: k.v === 'EVASION' || k.v === 'EXIT' ? 1 : 3, d: 30, on: !isUpdate ? true : false, id: null }))
  );

  // En modo actualización: pre-cargar las reglas existentes del docente
  useEffect(() => {
    if (!(isTeacher && isUpdate) || simulate) return;
    teacherApi.getAlertRules()
      .then((res) => {
        const existing = res?.data?.rules || res?.rules || res?.data || [];
        setRules((prev) => prev.map((r) => {
          const found = (existing || []).find((x) => x.event_kind === r.kind);
          return found
            ? { ...r, n: found.threshold_count ?? r.n, d: found.window_days ?? r.d, on: !!found.active, id: found.rule_id }
            : { ...r, on: false, id: null };
        }));
      })
      .catch(() => { /* mantener defaults */ });
  }, [isTeacher, isUpdate, simulate]);

  /* políticas actuales del asistente (modo actualización) */
  useEffect(() => {
    if (step !== 'chatpol' || simulate) return;
    chatApi.policies().then((p) => setChatPol((prev) => ({ ...prev, ...p }))).catch(() => {});
  }, [step, simulate]);

  /* cargar docentes para asignación de grupos */
  useEffect(() => {
    if (step !== 'groups') return;
    if (simulate) {
      setTeachers([
        { user_id: 'u1', first_name: 'Ana', last_name: 'Pérez' },
        { user_id: 'u2', first_name: 'Carlos', last_name: 'Roa' },
        { user_id: 'u3', first_name: 'María', last_name: 'Gómez' },
      ]);
      return;
    }
    schoolApi.getTeachers().then((r) => setTeachers(r?.data?.teachers || r?.teachers || [])).catch(() => {});
  }, [step, simulate]);

  /* cargar política de riesgo para preservar mapping/reglas */
  useEffect(() => {
    if (step !== 'risk') return;
    if (simulate) { setRiskCfg({ rules: [], mapping: [] }); return; }
    riskApi.getPolicy().then((r) => {
      const cfg = r?.data?.config;
      setRiskCfg(cfg || { rules: [], mapping: [] });
      const existing = cfg?.rules || [];
      if (existing.length) {
        setThresholds((prev) => {
          const nx = { ...prev };
          for (const lvl of LEVELS) {
            const rule = existing.find((x) => x.risk_level === lvl);
            if (rule) nx[lvl] = { n: rule.recurrence_count ?? prev[lvl].n, d: rule.window_days ?? prev[lvl].d };
          }
          return nx;
        });
      }
    }).catch(() => setRiskCfg({ rules: [], mapping: [] }));
  }, [step, simulate]);

  /* guion activo de Nexus */
  const script = useMemo(() => {
    if (step === 'welcome' || step === 'done') return [];
    if (step === 'schedule') return schedPhase === 'pick' ? SCRIPTS.scheduleA : SCRIPTS.scheduleB;
    return SCRIPTS[step] || [];
  }, [step, schedPhase]);

  const botActive = step !== 'welcome'; // la bienvenida es silenciosa

  /* ── acciones de guardado ──
     `simulate` solo existe para la ruta dev /dev/onboarding: omite la
     llamada real al backend y simula latencia para poder recorrer el
     flujo sin login. En producción nunca se activa. */
  const fakeSave = () => { setSaving(true); setTimeout(() => { setSaving(false); next(); }, 650); };

  const saveSchedule = async () => {
    if (simulate) return fakeSave();
    setSaving(true); setError('');
    try {
      await schoolApi.completeOnboarding({
        jornadas: jornadas.map((j) => ({
          work_shift: j.shift,
          rotates_classrooms: j.rotates,
          entry_time: j.entry,
          exit_time: j.exit,
          recess_start_time: j.recess || null,
          recess_end_time: j.recessEnd || null,
          time_blocks: j.rotates ? j.blocks.map((b, i) => ({
            block_number: i + 1, block_name: `Bloque ${i + 1}`, start_time: b.start, end_time: b.end,
          })) : [],
        })),
      });
      next();
    } catch (e) { setError(humanizeError(e, 'No se pudo guardar la jornada.')); }
    finally { setSaving(false); }
  };

  const saveGroups = async () => {
    const names = groupNames(grades, perGrade, nomenclature, nomenSep);
    const faltan = names.filter((n) => !(assignments[n] || []).length);
    if (faltan.length) { setError(`Falta asignar docente a: ${faltan.join(', ')}`); return; }
    if (simulate) return fakeSave();
    setSaving(true); setError('');
    try {
      await schoolApi.completeGroupsOnboarding({
        grades, nomenclature, nomenclature_separator: nomenSep,
        groups_per_grade: perGrade, grade_shifts: gradeShifts, teacher_assignments: assignments,
      });
      next();
    } catch (e) { setError(humanizeError(e, 'No se pudieron guardar los grupos.')); }
    finally { setSaving(false); }
  };

  const saveRisk = async () => {
    if (simulate) return fakeSave();
    setSaving(true); setError('');
    try {
      const base = { LEVE: 1.0, MODERADA: 3.0, ALTA: 6.0, MUY_ALTA: 10.0 };
      const oldRules = riskCfg?.rules || [];
      const rules = LEVELS.map((lvl) => {
        const ex = oldRules.find((r) => r.risk_level === lvl) || {};
        const th = thresholds[lvl];
        return {
          risk_level: lvl,
          weight_base: ex.weight_base ?? base[lvl],
          half_life_days: ex.half_life_days ?? 5,
          activation_threshold: ex.activation_threshold ?? th.n,
          cooldown_days: ex.cooldown_days ?? 5,
          single_occurrence: lvl === 'MUY_ALTA',
          requires_human_review: lvl === 'ALTA',
          recurrence_count: th.n, window_days: th.d,
          min_recurrence: ex.min_recurrence ?? 1, max_recurrence: ex.max_recurrence ?? 20,
          min_window_days: ex.min_window_days ?? 1, max_window_days: ex.max_window_days ?? 90,
          min_weight: ex.min_weight ?? 0.5, max_weight: ex.max_weight ?? 15.0,
          min_half_life: ex.min_half_life ?? 3, max_half_life: ex.max_half_life ?? 14,
          min_threshold: ex.min_threshold ?? 1.0, max_threshold: ex.max_threshold ?? 20.0,
        };
      });
      await riskApi.createPolicyVersion(
        { rules, mapping: riskCfg?.mapping || [] },
        'Configuración inicial del motor de análisis de riesgo'
      );
      await schoolApi.completeRiskConfig();
      next();
    } catch (e) { setError(humanizeError(e, 'No se pudo guardar la política de avisos.')); }
    finally { setSaving(false); }
  };

  const saveTeacherRules = async () => {
    if (simulate) return fakeSave();
    setSaving(true); setError('');
    try {
      for (const r of rules) {
        if (r.id) {
          // regla existente → actualizar umbral/ventana y activación
          await teacherApi.updateAlertRule(r.id, { threshold_count: r.n, window_days: r.d, active: r.on });
        } else if (r.on) {
          await teacherApi.createAlertRule({ event_kind: r.kind, threshold_count: r.n, window_days: r.d });
        }
      }
      await teacherApi.completeOnboarding({ skipped: isUpdate ? false : !rules.length });
      next();
    } catch (e) { setError(humanizeError(e, 'No se pudieron guardar tus criterios.')); }
    finally { setSaving(false); }
  };

  const skipTeacherRules = async () => {
    if (simulate) return fakeSave();
    setSaving(true);
    try { await teacherApi.completeOnboarding({ skipped: true }); next(); }
    catch (e) { setError(humanizeError(e, 'No se pudo continuar.')); }
    finally { setSaving(false); }
  };

  /* ══════════ pasos ══════════ */
  const renderWelcome = () => {
    // En actualización la agenda solo muestra la sección que se abrió —
    // no las tres, si el usuario solo vino a tocar una.
    const SECTION_META = {
      schedule: { icon: Clock,    t: 'Jornadas y horarios',       d: 'Entrada, salida, descanso y bloques por jornada', action: 'Actualizar jornadas',  lede: 'Vas a actualizar las jornadas y horarios de tu institución. Todo lo que cambies se puede volver a ajustar.' },
      groups:   { icon: Layers,   t: 'Grados, grupos y docentes', d: isRector ? 'La estructura del año con su docente asignado' : 'Lo completa rectoría — aquí revisas el avance', action: 'Actualizar grupos', lede: isRector ? 'Vas a actualizar la estructura académica del año: grados, grupos y sus docentes.' : 'Vas a revisar la estructura académica — su edición completa corresponde a rectoría.' },
      risk:     { icon: BellRing, t: 'Umbrales de aviso',         d: 'A partir de cuántas repeticiones Nexus alerta',  action: 'Actualizar umbrales',  lede: 'Vas a ajustar desde cuándo Nexus te alerta de repeticiones.' },
      chat:     { icon: MessageCircle, t: 'Asistente Nexus',      d: 'Qué puede consultar cada rol con el chatbot',   action: 'Actualizar asistente', lede: 'Vas a decidir qué capacidades del chatbot están activas para cada rol — se pueden apagar y encender cuando quieras.' },
    };
    const agenda = isTeacher
      ? [{ icon: BellRing, t: 'Tus criterios de aviso', d: 'Desde cuándo te aviso de repeticiones en tus clases' }]
      : ['schedule', 'groups', 'risk', 'chat']
          .filter((k) => missing[k])
          .map((k) => SECTION_META[k]);

    const singleScope = isUpdate && agenda.length === 1 ? agenda[0] : null;
    const welcomeTitle = isUpdate
      ? (singleScope ? `Actualizar ${singleScope.t.toLowerCase()}` : 'Actualizar configuración')
      : 'Bienvenid@';
    const welcomeLede = isUpdate
      ? (singleScope?.lede || 'Vas a actualizar los detalles de tu institución. Nexus te acompaña paso a paso — igual que la primera vez.')
      : 'Tu institución aún no está configurada. Nexus — la voz del sistema — te acompaña paso a paso.';
    const actionLabel = isUpdate
      ? (singleScope?.action || 'Actualizar configuración')
      : 'Comenzar';

    return (
      <div className="flex flex-col items-center gap-7 pt-4 text-center">
        {/* hero: bot sobre halo suave — la marca habla sola */}
        <div className="relative">
          <span
            aria-hidden
            className="absolute -inset-6 rounded-full"
            style={{ background: 'radial-gradient(closest-side, var(--nx-subtle-bg-accent), transparent 72%)' }}
          />
          <img src="/imagenbot.png" alt="Nexus" className="relative h-28 w-28 rounded-full object-contain drop-shadow-[0_10px_20px_oklch(30%_.08_245/.25)]"
            style={{ animation: 'nx-float 3.2s ease-in-out infinite' }} />
          <span className="absolute -inset-2 rounded-full border-2 border-[var(--nx-border-accent)]"
            style={{ animation: 'nx-pulse 2.6s var(--nx-ease-out, ease-out) infinite' }} aria-hidden />
        </div>

        <div className="space-y-2.5">
          <span className="text-[12px] font-[650] uppercase tracking-[.06em] text-[var(--nx-accent)]">
            {isUpdate ? 'Configuración' : 'Configuración inicial'}
          </span>
          <h1 className="text-[20px] font-[680] tracking-[-.02em] text-[var(--nx-text)]">{welcomeTitle}</h1>
          <p className="mx-auto max-w-[46ch] text-[14px] leading-relaxed text-[var(--nx-text-muted)]">
            {welcomeLede}
          </p>
        </div>

        {/* agenda: solo lo que este flujo va a tocar */}
        <div className="flex w-full max-w-[520px] flex-col gap-2.5">
          {agenda.map((a, i) => (
            <motion.div
              key={i}
              initial={{ opacity: 0, y: 10 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, delay: 0.15 + i * 0.08, ease: EASE }}
              className="flex items-center gap-4 rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] px-5 py-4 text-left shadow-[var(--nx-shadow-low,0_1px_2px_rgb(23_26_32/.04))]"
            >
              <span className="grid h-10 w-10 shrink-0 place-items-center rounded-control bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]">
                <a.icon size={18} />
              </span>
              <div className="flex-1 min-w-0">
                <p className="text-[14px] font-[620]">{a.t}</p>
                <p className="text-[12.5px] text-[var(--nx-text-muted)]">{a.d}</p>
              </div>
            </motion.div>
          ))}
        </div>

        <div className="flex flex-col items-center gap-3">
          <Button size="lg" onClick={next}>{actionLabel}</Button>
          {isUpdate && onCancel && (
            <Button variant="ghost" size="sm" onClick={onCancel}>Volver sin cambios</Button>
          )}
        </div>
      </div>
    );
  };

  const renderSchedule = () => {
    /* fase A: cuáles jornadas existen */
    if (schedPhase === 'pick') return (
      <>
        <StepHead kicker={`Paso ${realIdx} de ${totalReal}`} title="¿Qué jornadas tiene el colegio?" lede="Marca todas las que apliquen." />
        <Work spotlight>
          <h2 className="text-[15px] font-[620]">Jornadas</h2>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {SHIFTS.map((s) => (
              <PickChip key={s} on={pickedShifts.includes(s)} onClick={() => {
                setPickedShifts((p) => p.includes(s) ? p.filter((x) => x !== s) : [...p, s]);
              }}>{SHIFT_LABEL[s]}</PickChip>
            ))}
          </div>
          <p className="text-[13px] text-[var(--nx-text-muted)]">Después configuramos cada una por separado.</p>
        </Work>
        <div className="flex justify-end">
          <Button size="lg" disabled={!pickedShifts.length} onClick={() => {
            setJornadas(pickedShifts.map((s) => ({
              shift: s, entry: '07:00', exit: '12:30', recess: '', recessEnd: '',
              rotates: false, numBlocks: 4, blocks: makeBlocks('07:00', '12:30', 4),
            })));
            setJIdx(0); setSchedPhase('config');
          }}>Continuar</Button>
        </div>
      </>
    );

    /* fase B: configurar cada jornada por separado */
    const j = jornadas[jIdx];
    const upd = (patch) => setJornadas((arr) => arr.map((x, i) => i === jIdx ? { ...x, ...patch } : x));
    const isLastJ = jIdx === jornadas.length - 1;
    return (
      <>
        <StepHead kicker={`Paso ${realIdx} de ${totalReal}`} title={`Jornada ${SHIFT_LABEL[j.shift]}`}
          lede={jornadas.length > 1 ? `Jornada ${jIdx + 1} de ${jornadas.length}.` : 'Horario de esta jornada.'} />
        <Work spotlight>
          <div className="grid gap-6 sm:grid-cols-2">
            <Input label="Entrada" type="time" value={j.entry} onChange={(e) => upd({ entry: e.target.value })} />
            <Input label="Salida" type="time" value={j.exit} onChange={(e) => upd({ exit: e.target.value })} />
          </div>
          <div className="grid gap-6 sm:grid-cols-2">
            <Input label="Inicio del descanso" hint="Opcional" type="time" value={j.recess} onChange={(e) => upd({ recess: e.target.value })} />
            <Input label="Fin del descanso" hint="Opcional" type="time" value={j.recessEnd} onChange={(e) => upd({ recessEnd: e.target.value })} />
          </div>
          <Switch checked={j.rotates} onChange={(v) => {
            upd({ rotates: v, blocks: v ? makeBlocks(j.entry, j.exit, j.numBlocks, j.recess || null, j.recessEnd || null) : [] });
          }} title="Los estudiantes rotan de aula por bloques"
            help="Nexus usa los bloques para saber dónde debería estar cada grupo" />
          {j.rotates && (
            <div className="flex flex-col gap-5 rounded-surface border border-[var(--nx-border)] bg-[var(--nx-canvas)] p-4">
              <div className="flex items-center justify-between gap-4">
                <span className="text-[14px] font-[600]">Bloques de la jornada</span>
                <Stepper label="bloques" value={j.numBlocks} min={1} max={10} onChange={(n) =>
                  upd({ numBlocks: n, blocks: makeBlocks(j.entry, j.exit, n, j.recess || null, j.recessEnd || null) })} />
              </div>
              <div className="flex flex-col gap-4">
                {j.blocks.map((b, bi) => (
                  <div key={bi} className="grid grid-cols-[auto_1fr_1fr] items-center gap-4">
                    <span className="w-16 text-[13px] font-semibold text-[var(--nx-text-muted)]">Bloque {bi + 1}</span>
                    <Input type="time" value={b.start} aria-label={`Inicio bloque ${bi + 1}`}
                      onChange={(e) => upd({ blocks: j.blocks.map((x, xi) => xi === bi ? { ...x, start: e.target.value } : x) })} />
                    <Input type="time" value={b.end} aria-label={`Fin bloque ${bi + 1}`}
                      onChange={(e) => upd({ blocks: j.blocks.map((x, xi) => xi === bi ? { ...x, end: e.target.value } : x) })} />
                  </div>
                ))}
              </div>
            </div>
          )}
        </Work>
        {error && <p role="alert" className="text-[14px] text-[var(--nx-danger)]">{error}</p>}
        <div className="flex items-center justify-between">
          {jIdx > 0
            ? <Button variant="ghost" onClick={() => setJIdx(jIdx - 1)}>← Jornada anterior</Button>
            : <span />}
          <Button size="lg" loading={saving} onClick={() => isLastJ ? saveSchedule() : setJIdx(jIdx + 1)}>
            {isLastJ ? 'Guardar jornadas' : 'Siguiente jornada →'}
          </Button>
        </div>
      </>
    );
  };

  const renderGroups = () => {
    const names = groupNames(grades, perGrade, nomenclature, nomenSep);
    const teacherOpts = teachers.map((t) => ({ value: t.user_id, label: `${t.first_name} ${t.last_name}`.trim() }));
    return (
      <>
        <StepHead kicker={`Paso ${realIdx} de ${totalReal}`} title="Grados y grupos del año"
          lede="Qué grados hay, cuántos grupos por grado, su jornada y su docente." />
        <Work spotlight>
          <h2 className="text-[15px] font-[620]">Grados</h2>
          <div className="grid grid-cols-4 gap-3 sm:grid-cols-6">
            {GRADES.map((g) => (
              <PickChip key={g} on={grades.includes(g)} onClick={() => {
                setGrades((p) => p.includes(g) ? p.filter((x) => x !== g) : [...p, g].sort((a, b) => +a - +b));
                if (!grades.includes(g)) setPerGrade((p) => ({ ...p, [g]: p[g] || 1 }));
              }}>{g}</PickChip>
            ))}
          </div>

          {grades.length > 0 && (
            <>
              <h2 className="text-[15px] font-[620]">Grupos por grado</h2>
              <div className="flex flex-col gap-4">
                {grades.map((g) => (
                  <div key={g} className="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-surface border border-[var(--nx-border)] bg-[var(--nx-canvas)] px-4 py-3.5">
                    <span className="w-16 text-[14.5px] font-[620]">Grado {g}</span>
                    <Stepper label={`grupos de ${g}`} value={perGrade[g] || 1} min={1} max={9}
                      onChange={(n) => setPerGrade((p) => ({ ...p, [g]: n }))} />
                    <Select aria-label={`Jornada del grado ${g}`} className="min-w-[150px]"
                      value={gradeShifts[g] || ''}
                      onChange={(e) => setGradeShifts((p) => ({ ...p, [g]: e.target.value }))}
                      options={SHIFTS.map((s) => ({ value: s, label: SHIFT_LABEL[s] }))}
                      placeholder="Jornada…" />
                  </div>
                ))}
              </div>
            </>
          )}

          <h2 className="text-[15px] font-[620]">Nomenclatura</h2>
          <div className="grid gap-3 sm:grid-cols-3">
            <PickChip on={nomenclature === 'alphabetic'} onClick={() => setNomenclature('alphabetic')}>7A · 7B</PickChip>
            <PickChip on={nomenclature === 'numeric'} onClick={() => setNomenclature('numeric')}>7-1 · 7-2</PickChip>
            <PickChip on={nomenclature === 'other'} onClick={() => setNomenclature('other')}>Otra</PickChip>
          </div>
          {nomenclature === 'other' && (
            <Input label="Separador" value={nomenSep} onChange={(e) => setNomenSep(e.target.value.slice(0, 3))} help={`Se verá como 7${nomenSep || '-'}1`} className="max-w-[200px]" />
          )}

          {names.length > 0 && (
            <>
              <h2 className="text-[15px] font-[620]">Docente por grupo</h2>
              <div className="flex flex-col gap-4">
                {names.map((n) => (
                  <div key={n} className="grid items-center gap-3 rounded-surface border border-[var(--nx-border)] bg-[var(--nx-canvas)] px-4 py-3.5 sm:grid-cols-[70px_1fr]">
                    <span className="text-[14.5px] font-[620]">{n}</span>
                    <SearchableSelect multiple options={teacherOpts} value={assignments[n] || []}
                      onChange={(v) => setAssignments((p) => ({ ...p, [n]: v }))}
                      placeholder="Asignar docente…" emptyText="Sin docentes registrados"
                      aria-label={`Docentes de ${n}`} />
                  </div>
                ))}
              </div>
              <div>
                <h2 className="mb-3 text-[15px] font-[620]">Vista previa</h2>
                <div className="flex flex-wrap gap-2">
                  {names.map((n) => (
                    <span key={n} className={clsx('rounded-full border px-3 py-1.5 text-[13px] font-semibold',
                      (assignments[n] || []).length
                        ? 'border-[var(--nx-success)] bg-[var(--nx-subtle-bg-success)] text-[var(--nx-success)]'
                        : 'border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] text-[var(--nx-text)]')}>{n}</span>
                  ))}
                </div>
              </div>
            </>
          )}
        </Work>
        {error && <p role="alert" className="text-[14px] text-[var(--nx-danger)]">{error}</p>}
        <div className="flex justify-end">
          <Button size="lg" loading={saving} onClick={saveGroups}>Guardar y continuar</Button>
        </div>
      </>
    );
  };

  const renderGroupsPending = () => (
    <>
      <StepHead kicker={`Paso ${realIdx} de ${totalReal} · rectoría`} title="Grados y grupos"
        lede="La estructura de grupos la define rectoría. El sistema se activa cuando ambos terminen su parte." />
      <Work>
        <div className="rounded-surface border border-[var(--nx-warning)] bg-[var(--nx-subtle-bg-warning)] px-5 py-4">
          <p className="text-[13.5px] font-[550] text-[var(--nx-text)]">Pendiente de rectoría</p>
          <p className="mt-1 text-[13.5px] text-[var(--nx-text-muted)]">Cuando rectoría complete los grupos y docentes, el sistema quedará listo. Tu parte no se bloquea.</p>
        </div>
      </Work>
      <div className="flex justify-end"><Button size="lg" onClick={next}>Continuar</Button></div>
    </>
  );

  const renderRisk = () => (
    <>
      <StepHead kicker={`Paso ${realIdx} de ${totalReal}`} title="Cuándo quieres que te avise"
        lede="Umbrales del motor de riesgo: a partir de cuántas repeticiones Nexus genera una alerta." />
      <Work spotlight>
        <h2 className="text-[15px] font-[620]">Niveles de alerta</h2>
        <div className="flex flex-col gap-5">
          {LEVELS.map((lvl) => (
            <div key={lvl} className="flex flex-wrap items-center gap-x-8 gap-y-4 rounded-surface border border-[var(--nx-border)] border-l-[3px] bg-[var(--nx-canvas)] px-5 py-4"
              style={{ borderLeftColor: LEVEL_EDGE[lvl] }}>
              <div className="min-w-[110px]">
                <p className="text-[14.5px] font-[620]">{LEVEL_LABEL[lvl]}</p>
                <p className="text-[12.5px] text-[var(--nx-text-muted)]">
                  {lvl === 'MUY_ALTA' ? 'una sola vez basta' : 'repeticiones acumuladas'}
                </p>
              </div>
              <div className="flex items-center gap-4">
                <Stepper label={`repeticiones ${LEVEL_LABEL[lvl]}`} value={thresholds[lvl].n} min={1} max={20}
                  onChange={(n) => setThresholds((p) => ({ ...p, [lvl]: { ...p[lvl], n } }))} />
                <span className="text-[13px] text-[var(--nx-text-muted)]">veces</span>
              </div>
              <div className="flex items-center gap-4">
                <Stepper label={`días ${LEVEL_LABEL[lvl]}`} value={thresholds[lvl].d} min={1} max={90} step={5}
                  onChange={(d) => setThresholds((p) => ({ ...p, [lvl]: { ...p[lvl], d } }))} />
                <span className="text-[13px] text-[var(--nx-text-muted)]">días</span>
              </div>
            </div>
          ))}
        </div>
      </Work>
      {error && <p role="alert" className="text-[14px] text-[var(--nx-danger)]">{error}</p>}
      <div className="flex justify-end">
        <Button size="lg" loading={saving} disabled={!riskCfg} onClick={saveRisk}>Finalizar configuración</Button>
      </div>
    </>
  );

  const saveChatPol = async () => {
    setError(''); setSaving(true);
    try { await chatApi.savePolicies(chatPol); next(); }
    catch (e) { setError(humanizeError(e, 'No se pudieron guardar las políticas.')); }
    finally { setSaving(false); }
  };

  const CHAT_POLICIES_UI = [
    { key: 'chat.teacher.risk_students',   t: 'Docentes ven estudiantes en riesgo',        d: 'Siempre acotado a sus propios grupos — nunca los de otros docentes' },
    { key: 'chat.teacher.student_fields',  t: 'Docentes consultan datos de estudiante',    d: 'Documento, contacto del acudiente, edad — solo de sus grupos' },
    { key: 'chat.teacher.aggregates',      t: 'Docentes ven resúmenes y agregados',        d: 'Resumen de jornada, faltas del día, seguimientos — con su scope' },
    { key: 'chat.teacher.derive_actions',  t: 'Docentes reciben acciones derivadas',       d: 'Chips «Derivar a seguimiento» / «Citar acudiente» cuando un dato cruza umbral' },
    { key: 'chat.smalltalk.enabled',       t: 'Conversación cotidiana habilitada',         d: 'Chistes, saludos, charla — si se apaga, el bot solo responde datos' },
  ];

  const renderChatPol = () => (
    <>
      <StepHead kicker={`Paso ${realIdx} de ${totalReal}`} title="Qué puede hacer el asistente"
        lede="Interruptores del chatbot por rol. El docente siempre queda acotado a sus grupos — estos controles deciden qué capacidades ve." />
      <Work spotlight>
        <h2 className="text-[15px] font-[620]">Permisos del asistente</h2>
        <div className="flex flex-col divide-y divide-[var(--nx-border)]">
          {CHAT_POLICIES_UI.map((p) => (
            <div key={p.key} className="py-3.5 first:pt-0 last:pb-0">
              <Switch
                checked={!!chatPol[p.key]}
                onChange={(v) => setChatPol((prev) => ({ ...prev, [p.key]: v }))}
                title={p.t}
                help={p.d}
              />
            </div>
          ))}
        </div>
        <div className="rounded-control border border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)] px-4 py-3">
          <p className="text-[12.5px] leading-relaxed text-[var(--nx-text-muted)]">
            La seguridad base no se toca: cada rol solo ve lo que su alcance permite y el bot nunca ejecuta operaciones sin confirmación. Estos controles ajustan qué capacidades se ofrecen.
          </p>
        </div>
      </Work>
      {error && <p role="alert" className="text-[14px] text-[var(--nx-danger)]">{error}</p>}
      <div className="flex justify-end">
        <Button size="lg" loading={saving} onClick={saveChatPol}>Guardar políticas</Button>
      </div>
    </>
  );

  const renderRules = () => (
    <>
      <StepHead kicker="Configuración opcional" title="Tus criterios de aviso"
        lede="Todos los casos vienen listos — ajusta los números y desactiva lo que no quieras en tus clases." />
      <Work spotlight>
        <h2 className="text-[15px] font-[620]">Tus reglas</h2>
        <div className="flex flex-col gap-4">
          {rules.map((r, i) => (
            <div key={r.kind} className={clsx(
              'flex flex-wrap items-center gap-x-6 gap-y-4 rounded-surface border px-4 py-4 transition-opacity',
              r.on ? 'border-[var(--nx-border)] bg-[var(--nx-canvas)]' : 'border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] opacity-55'
            )}>
              <div className="min-w-[190px] flex-1">
                <p className="text-[14.5px] font-[620]">{RULE_KINDS.find((k) => k.v === r.kind)?.l}</p>
                <p className="text-[12.5px] text-[var(--nx-text-muted)]">en mis clases</p>
              </div>
              <div className="flex items-center gap-4">
                <Stepper label="repeticiones" value={r.n} min={1} max={60}
                  onChange={(n) => setRules((p) => p.map((x, xi) => xi === i ? { ...x, n } : x))} />
                <span className="text-[13px] text-[var(--nx-text-muted)]">veces</span>
              </div>
              <div className="flex items-center gap-4">
                <Stepper label="días" value={r.d} min={1} max={90}
                  onChange={(d) => setRules((p) => p.map((x, xi) => xi === i ? { ...x, d } : x))} />
                <span className="text-[13px] text-[var(--nx-text-muted)]">días</span>
              </div>
              <Switch checked={r.on} title={r.on ? 'Aplicar en mis clases' : 'No aplicar'}
                onChange={(v) => setRules((p) => p.map((x, xi) => xi === i ? { ...x, on: v } : x))} />
            </div>
          ))}
          <p className="text-[13px] text-[var(--nx-text-muted)]">
            {isUpdate
              ? 'Las desactivadas quedan pausadas — vuelves a activarlas cuando quieras.'
              : 'Las reglas desactivadas no se guardan — puedes activarlas después desde tu panel.'}
          </p>
        </div>
      </Work>
      {error && <p role="alert" className="text-[14px] text-[var(--nx-danger)]">{error}</p>}
      <div className="flex items-center justify-between">
        <Button variant="ghost" loading={saving} onClick={skipTeacherRules}>Omitir por ahora</Button>
        <Button size="lg" loading={saving} onClick={saveTeacherRules}>Guardar y entrar</Button>
      </div>
    </>
  );

  const renderDone = () => (
    <div className="flex flex-col items-center gap-5 py-10 text-center">
      <img src="/imagenbot.png" alt="Nexus" className="h-28 w-28" style={{ animation: 'nx-float 3.2s ease-in-out infinite' }} />
      <span className="text-[13px] font-semibold tracking-wide text-[var(--nx-accent)]">
        {isUpdate ? 'Cambios guardados' : isTeacher ? 'Listo' : 'Configuración completa'}
      </span>
      <h1 className="max-w-[18ch] text-[20px] font-[650] leading-snug tracking-[-.01em] text-[var(--nx-text)]">
        {isUpdate ? 'Tu institución quedó actualizada' : isTeacher ? 'Tu panel ya te espera' : 'Tu institución ya está operando con Nexus'}
      </h1>
      <p className="max-w-[46ch] text-[15px] text-[var(--nx-text-muted)]">
        {isTeacher
          ? 'Cuando algo se repita en tus clases, Nexus te avisa con el motivo y qué puedes hacer.'
          : 'Nexus interpreta la jornada y avisa solo cuando algo necesita atención.'}
      </p>
      <Button size="lg" onClick={done}>{isUpdate ? 'Volver a Configuración' : isTeacher ? 'Entrar a Inicio' : 'Entrar a mi jornada'}</Button>
    </div>
  );

  const RENDER = {
    welcome: renderWelcome, schedule: renderSchedule, groups: renderGroups,
    groupsPending: renderGroupsPending, risk: renderRisk, rules: renderRules, chatpol: renderChatPol, done: renderDone,
  };

  return (
    <div className="min-h-screen bg-[var(--nx-canvas)] text-[var(--nx-text)]">
      {/* Topbar: marca Nexus + paso actual con dots de progreso */}
      <header className="sticky top-0 z-20 border-b border-[var(--nx-border)] bg-[var(--nx-surface)]/85 backdrop-blur">
        <div className="mx-auto flex h-[56px] max-w-[680px] items-center justify-between px-5 sm:px-6">
          <span className="flex items-center gap-2 text-[14px] font-[650] text-[var(--nx-text)]">
            <NexoAvatar size={26} />
            Nexus
          </span>
          <span className="flex items-center gap-3">
            <span className="text-[12.5px] font-[600] tabular-nums text-[var(--nx-text-muted)]">
              {step === 'welcome' ? (isUpdate ? 'Actualizar' : 'Configuración inicial') : step === 'done' ? 'Listo' : `Paso ${realIdx} de ${totalReal}`}
            </span>
            <span className="flex gap-1.5" aria-hidden>
              {steps.map((_, i) => (
                <span key={i} className={clsx(
                  'h-[7px] rounded-full transition-all duration-200',
                  i === stepIdx ? 'w-[18px] bg-[var(--nx-accent)]'
                    : i < stepIdx ? 'w-[7px] bg-[var(--nx-accent)]/40'
                    : 'w-[7px] bg-[var(--nx-border)]'
                )} />
              ))}
            </span>
            {/* X de salida: en edición cancela; en primer uso cierra sesión */}
            <button
              type="button"
              aria-label={onCancel ? 'Salir sin cambios' : 'Salir'}
              onClick={() => (onCancel ? onCancel() : logout())}
              className="grid h-8 w-8 place-items-center rounded-full text-[var(--nx-text-muted)] transition-colors hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]"
            >
              <X size={17} />
            </button>
          </span>
        </div>
      </header>

      <main className="mx-auto flex max-w-[680px] flex-col gap-7 px-5 pb-56 pt-10 sm:px-6">
        <AnimatePresence mode="wait" initial={false}>
          <motion.div key={`${step}-${schedPhase}`} className="flex flex-col gap-7"
            initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0 }}
            transition={{ duration: 0.2, ease: EASE }}>
            {RENDER[step]()}
          </motion.div>
        </AnimatePresence>
      </main>

      {/* Nexus habla desde la esquina — solo después de "Comenzar" */}
      <NexusGuide script={script} active={botActive} celebrate={step === 'done'} />
    </div>
  );
}
