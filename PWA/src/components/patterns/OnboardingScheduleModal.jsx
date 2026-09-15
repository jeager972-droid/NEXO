/**
 * OnboardingScheduleModal — Modal bloqueante para onboarding de horarios.
 * Se muestra cuando onboarding_completed=FALSE para RECTOR/COORDINATOR.
 * No se puede cerrar hasta completar el formulario.
 *
 * Flujo multi-jornada:
 *   Fase 1: ¿Hay más de una jornada? (Sí/No)
 *   Fase 2: Seleccionar cuáles jornadas existen
 *   Fase 3: Para CADA jornada: entrada/salida, ¿rota salones?, receso
 *           Si rota: sub-paso para bloques horarios
 *   Fase 4: Revisión y guardado
 *
 * Persistencia: el estado se guarda en localStorage para que al refrescar
 * no se pierda el progreso del formulario.
 */
import { useState, useEffect, useMemo, useId } from 'react';
import { Clock, AlertCircle, Calendar, Coffee, Check, Sun, Moon, Sunset, X, Plus, Trash2 } from 'lucide-react';
import { motion } from 'framer-motion';
import { clsx } from 'clsx';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import { SearchableSelect } from '../ui/SearchableSelect';
import { schoolApi } from '../../api/school';
import { humanizeError } from '../../utils/messages';

const EASE = [0.22, 1, 0.36, 1];
const STORAGE_KEY = 'nexo:onboarding-schedule';

/** Campo de hora nativo */
const TimeField = ({ label, value, onChange, required, leftIcon: Icon }) => {
  const auto = useId();
  const id = `nx-ti-${auto}`;
  
  const fieldClass = clsx(
    'w-full h-12 flex items-center rounded-control border bg-[var(--nx-surface)] text-body text-[var(--nx-text)]',
    'outline-none transition-[border-color,box-shadow] duration-fast ease-out',
    'border-[var(--nx-border)] hover:border-[color-mix(in_oklch,var(--nx-text)_var(--nx-subtle-mix-w),var(--nx-tint-base))] focus:border-[var(--nx-accent)] focus:shadow-[var(--nx-ring)]',
    Icon ? 'pl-11' : 'pl-4', 'pr-4',
    '[&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100',
    !value && 'text-[var(--nx-text-muted)]'
  );

  return (
    <div className="space-y-2">
      {label && (
        <div className="flex items-baseline justify-between gap-3">
          <label htmlFor={id} className="block text-label text-[var(--nx-text)]">
            {label}
            {required && <span className="ml-0.5 text-[var(--nx-text-muted)]" aria-hidden>*</span>}
          </label>
        </div>
      )}
      <div className="relative">
        {Icon && (
          <span className="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[var(--nx-text-muted)] z-10">
            <Icon size={16} />
          </span>
        )}
        <input
          id={id}
          type="time"
          required={required}
          value={value}
          onChange={(e) => onChange?.(e.target.value)}
          className={fieldClass}
        />
      </div>
    </div>
  );
};

const SHIFT_OPTIONS = [
  { value: 'mañana', label: 'Mañana', icon: Sun },
  { value: 'tarde', label: 'Tarde', icon: Sunset },
  { value: 'noche', label: 'Noche', icon: Moon },
  { value: 'completa', label: 'Completa (mañana y tarde)', icon: Calendar },
];

const DEFAULT_JORNADA = () => ({
  work_shift: '',
  rotates_classrooms: false,
  entry_time: '',
  exit_time: '',
  recess_start_time: '',
  recess_end_time: '',
  recesses: [{ start: '', end: '' }],
  numBlocks: 6,
  blocks: [],
});

/** Carga el estado desde localStorage o retorna null */
function loadSavedState() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const data = JSON.parse(raw);
    if (!data || typeof data !== 'object') return null;
    return data;
  } catch { return null; }
}

/** Guarda el estado en localStorage */
function saveState(state) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  } catch { /* ignore quota errors */ }
}

/** Limpia el estado de localStorage */
function clearSavedState() {
  try { localStorage.removeItem(STORAGE_KEY); } catch { /* ignore */ }
}

export const OnboardingScheduleModal = ({ schoolId, userId, role, onCompleted, onCancel, mode = 'onboarding' }) => {
  const isEditMode = mode === 'edit';
  // Restaurar estado desde localStorage
  const saved = useMemo(() => loadSavedState(), []);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [validationError, setValidationError] = useState('');

  // Fase: 'multi' → 'select' → 'jornada' → 'technical' → 'review'
  const [phase, setPhase] = useState(saved?.phase || 'multi');

  // Fase 'technical': modalidad técnica
  const [hasTechModality, setHasTechModality] = useState(saved?.hasTechModality ?? null);
  const [techGrades, setTechGrades] = useState(saved?.techGrades || []); // ['10','11']
  const [techConfigs, setTechConfigs] = useState(saved?.techConfigs || {}); // { '10-mañana': { uses_blocks, days, entry, exit } }

  // Fase 'multi': ¿hay más de una jornada?
  const [hasMultipleShifts, setHasMultipleShifts] = useState(saved?.hasMultipleShifts ?? null);

  // Fase 'select': qué jornadas existen
  const [selectedShifts, setSelectedShifts] = useState(saved?.selectedShifts || []);

  // Fase 'jornada': configuración por jornada
  const [jornadas, setJornadas] = useState(saved?.jornadas || []);
  const [currentJornadaIdx, setCurrentJornadaIdx] = useState(saved?.currentJornadaIdx || 0);
  const [jornadaSubStep, setJornadaSubStep] = useState(saved?.jornadaSubStep || 0);

  // Persistir estado en localStorage cada vez que cambie
  useEffect(() => {
    saveState({ phase, hasMultipleShifts, selectedShifts, jornadas, currentJornadaIdx, jornadaSubStep, hasTechModality, techGrades, techConfigs });
  }, [phase, hasMultipleShifts, selectedShifts, jornadas, currentJornadaIdx, jornadaSubStep, hasTechModality, techGrades, techConfigs]);

  // Inicializar bloques cuando cambia numBlocks o rotates de la jornada actual
  useEffect(() => {
    if (phase !== 'jornada') return;
    setJornadas(prev => prev.map((j, idx) => {
      if (idx !== currentJornadaIdx || !j.rotates_classrooms) return j;
      const newBlocks = [];
      for (let i = 0; i < j.numBlocks; i++) {
        newBlocks.push({
          block_number: i + 1,
          block_name: `Clase ${i + 1}`,
          start_time: j.blocks[i]?.start_time || '',
          end_time: j.blocks[i]?.end_time || '',
        });
      }
      return { ...j, blocks: newBlocks };
    }));
  }, [jornadas[currentJornadaIdx]?.numBlocks, jornadas[currentJornadaIdx]?.rotates_classrooms, currentJornadaIdx, phase]); // eslint-disable-line react-hooks/exhaustive-deps

  // Calcular total de pasos y paso actual para la barra de progreso
  const { totalSteps, currentStep } = useMemo(() => {
    let total = 1; // multi
    if (hasMultipleShifts === true) total += 1; // select
    const jornadaSteps = jornadas.reduce((acc, j) => acc + (j.rotates_classrooms ? 2 : 1), 0);
    total += jornadaSteps; // jornada steps
    total += 1; // technical
    if (jornadas.length > 0) total += 1; // review

    let current = 1;
    if (phase === 'multi') current = 1;
    else if (phase === 'select') current = 2;
    else if (phase === 'jornada') {
      current = 2;
      for (let i = 0; i < currentJornadaIdx; i++) {
        current += jornadas[i].rotates_classrooms ? 2 : 1;
      }
      current += jornadaSubStep + 1;
    } else if (phase === 'technical') {
      current = 2 + jornadaSteps + 1;
    } else if (phase === 'review') {
      current = total;
    }

    return { totalSteps: total, currentStep: current };
  }, [hasMultipleShifts, jornadas, phase, currentJornadaIdx, jornadaSubStep]);

  const updateJornada = (idx, field, value) => {
    setJornadas(prev => prev.map((j, i) => i === idx ? { ...j, [field]: value } : j));
  };

  const updateBlock = (jIdx, bIdx, field, value) => {
    setJornadas(prev => prev.map((j, i) => {
      if (i !== jIdx) return j;
      return { ...j, blocks: j.blocks.map((b, bi) => bi === bIdx ? { ...b, [field]: value } : b) };
    }));
  };

  const shiftLabel = (s) => SHIFT_OPTIONS.find(o => o.value === s)?.label || s;

  // Validar paso actual con mensajes de error específicos
  const canProceed = () => {
    setValidationError('');
    if (phase === 'multi') return hasMultipleShifts !== null;
    if (phase === 'select') return selectedShifts.length > 0;
    if (phase === 'jornada') {
      const j = jornadas[currentJornadaIdx];
      if (!j) return false;
      if (jornadaSubStep === 1) {
        if (j.blocks.length === 0) {
          setValidationError('Debes configurar al menos un bloque horario');
          return false;
        }
        if (!j.blocks.every(b => b.start_time && b.end_time)) {
          setValidationError('Todos los bloques deben tener hora de inicio y fin');
          return false;
        }
        // Validar que los bloques estén en orden cronológico
        for (let i = 0; i < j.blocks.length - 1; i++) {
          if (j.blocks[i].end_time > j.blocks[i + 1].start_time) {
            setValidationError('Los bloques horarios deben estar en orden cronológico');
            return false;
          }
        }
        return true;
      }
      if (!j.entry_time || !j.exit_time) {
        setValidationError('Debes ingresar hora de entrada y salida');
        return false;
      }
      // Validar que entrada < salida
      if (j.entry_time >= j.exit_time) {
        setValidationError('La hora de entrada debe ser anterior a la hora de salida');
        return false;
      }
      if (j.recess_start_time && !j.recess_end_time) {
        setValidationError('Debes ingresar hora de fin del receso');
        return false;
      }
      if (j.recess_end_time && !j.recess_start_time) {
        setValidationError('Debes ingresar hora de inicio del receso');
        return false;
      }
      // Validar que receso esté dentro del horario escolar
      if (j.recess_start_time && j.recess_end_time) {
        if (j.recess_start_time < j.entry_time || j.recess_end_time > j.exit_time) {
          setValidationError('El receso debe estar dentro del horario escolar');
          return false;
        }
      }
      return true;
    }
    if (phase === 'technical') return hasTechModality !== null;
    if (phase === 'review') return true;
    return false;
  };

  const handleNext = () => {
    setError('');
    if (phase === 'multi') {
      if (hasMultipleShifts === false) {
        const shifts = ['mañana'];
        setSelectedShifts(shifts);
        setJornadas(shifts.map(s => ({ ...DEFAULT_JORNADA(), work_shift: s })));
        setCurrentJornadaIdx(0);
        setJornadaSubStep(0);
        setPhase('jornada');
      } else {
        setPhase('select');
      }
      return;
    }
    if (phase === 'select') {
      if (selectedShifts.length === 0) {
        setError('Seleccione al menos una jornada');
        return;
      }
      setJornadas(selectedShifts.map(s => ({ ...DEFAULT_JORNADA(), work_shift: s })));
      setCurrentJornadaIdx(0);
      setJornadaSubStep(0);
      setPhase('jornada');
      return;
    }
    if (phase === 'jornada') {
      const j = jornadas[currentJornadaIdx];
      if (jornadaSubStep === 0 && j.rotates_classrooms) {
        setJornadaSubStep(1);
        return;
      }
      if (currentJornadaIdx < jornadas.length - 1) {
        setCurrentJornadaIdx(currentJornadaIdx + 1);
        setJornadaSubStep(0);
      } else {
        setPhase('technical');
      }
      return;
    }
    if (phase === 'technical') {
      if (hasTechModality === false) {
        setPhase('review');
        return;
      }
      if (hasTechModality === true && techGrades.length === 0) {
        setError('Seleccione al menos un grado con modalidad técnica');
        return;
      }
      setPhase('review');
      return;
    }
    if (phase === 'review') {
      handleSubmit();
    }
  };

  const handleBack = () => {
    setError('');
    if (phase === 'multi') return;
    if (phase === 'select') {
      setPhase('multi');
      return;
    }
    if (phase === 'review') {
      setPhase('technical');
      return;
    }
    if (phase === 'technical') {
      const lastIdx = jornadas.length - 1;
      setCurrentJornadaIdx(lastIdx);
      setJornadaSubStep(jornadas[lastIdx].rotates_classrooms ? 1 : 0);
      setPhase('jornada');
      return;
    }
    if (phase === 'jornada') {
      if (jornadaSubStep === 1) {
        setJornadaSubStep(0);
        return;
      }
      if (currentJornadaIdx > 0) {
        const prevIdx = currentJornadaIdx - 1;
        setCurrentJornadaIdx(prevIdx);
        setJornadaSubStep(jornadas[prevIdx].rotates_classrooms ? 1 : 0);
      } else {
        if (hasMultipleShifts === true) {
          setPhase('select');
        } else {
          setPhase('multi');
        }
      }
    }
  };

  const handleSubmit = async () => {
    setLoading(true);
    setError('');
    try {
      const payload = {
        jornadas: jornadas.map(j => {
          const recesses = (j.recesses || []).filter(r => r.start && r.end);
          const firstRecess = recesses[0];
          return {
            work_shift: j.work_shift,
            rotates_classrooms: j.rotates_classrooms,
            entry_time: j.entry_time,
            exit_time: j.exit_time,
            recess_start_time: firstRecess?.start || j.recess_start_time || null,
            recess_end_time: firstRecess?.end || j.recess_end_time || null,
            recesses: recesses.length > 0 ? recesses : undefined,
            time_blocks: j.rotates_classrooms ? j.blocks.map(b => ({
              block_number: b.block_number,
              block_name: b.block_name,
              start_time: b.start_time,
              end_time: b.end_time,
            })) : [],
          };
        }),
      };
      const result = await schoolApi.completeOnboarding(payload);
      if (result.status === 'ok') {
        // Guardar modalidad técnica si el usuario la configuró
        if (hasTechModality === true && techGrades.length > 0) {
          const techPayload = techGrades.flatMap((grade) => {
            return jornadas.map((j) => {
              const key = `${grade}-${j.work_shift}`;
              const cfg = techConfigs[key] || {};
              return {
                grade_level: grade,
                work_shift: j.work_shift,
                uses_blocks: cfg.uses_blocks ?? false,
                days_of_week: cfg.days || [],
                entry_time: cfg.entry || null,
                exit_time: cfg.exit || null,
              };
            });
          });
          try {
            await schoolApi.saveTechnicalModality(techPayload);
          } catch (techErr) {
            console.error('[Onboarding] Error guardando modalidad técnica:', techErr);
            // No bloquear el onboarding si falla la modalidad técnica
          }
        }
        clearSavedState();
        onCompleted?.(result);
      } else {
        setError(humanizeError(result, 'No se pudo guardar la configuración.'));
      }
    } catch (e) {
      console.error('[Onboarding] Error al guardar:', e);
      const raw = e?.response?.data?.message || e?.response?.data?.error || e?.message || String(e);
      setError(`Error al guardar: ${raw}`);
    } finally {
      setLoading(false);
    }
  };

  const phaseTitle = {
    multi: 'Jornadas de la institución',
    select: 'Seleccionar jornadas',
    jornada: `Jornada: ${jornadas[currentJornadaIdx] ? shiftLabel(jornadas[currentJornadaIdx].work_shift) : ''}`,
    review: 'Revisión final',
  };

  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4">
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.2, ease: EASE }}
        className="absolute inset-0 bg-[color-mix(in_oklch,var(--nx-text)_55%,transparent)] backdrop-blur-sm"
      />
      <motion.div
        initial={{ opacity: 0, scale: 0.97, y: 8 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        transition={{ duration: 0.2, ease: EASE }}
        className={clsx(
          'relative z-10 w-full max-w-[640px] rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-dialog',
          // En fase 'select' el dropdown del SearchableSelect necesita espacio
          // sin overflow para no cliparse. En las demás fases, scroll interno.
          phase === 'select' ? '' : 'max-h-[90vh] overflow-y-auto'
        )}
      >
        {/* Header — con botón de cerrar en modo edición */}
        <div className="border-b border-[var(--nx-border)] px-6 py-5">
          <div className="flex items-center gap-3">
            <div className="grid h-10 w-10 place-items-center rounded-surface bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]">
              <Calendar size={20} strokeWidth={1.75} />
            </div>
            <div className="flex-1">
              <h2 className="text-h3 text-[var(--nx-text)]">
                {isEditMode ? 'Cambiar calendario escolar' : 'Calendario escolar'}
              </h2>
              <p className="text-body-sm text-[var(--nx-text-muted)]">
                {isEditMode
                  ? `Modifique los horarios — Paso ${currentStep} de ${totalSteps} — ${phaseTitle[phase]}`
                  : `Paso ${currentStep} de ${totalSteps} — ${phaseTitle[phase]}`
                }
              </p>
            </div>
            {isEditMode && (
              <button
                type="button"
                onClick={onCancel}
                className="grid h-9 w-9 place-items-center rounded-control text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)] transition-colors"
                aria-label="Cancelar"
              >
                <X size={20} />
              </button>
            )}
          </div>
          {/* Progress bar */}
          <div className="mt-4 flex gap-1.5">
            {Array.from({ length: totalSteps }).map((_, i) => (
              <div
                key={i}
                className={`h-1 flex-1 rounded-full transition-colors duration-fast ${
                  i + 1 <= currentStep ? 'bg-[var(--nx-border-accent)]' : 'bg-[var(--nx-border)]'
                }`}
              />
            ))}
          </div>
        </div>

        {/* Body — scrollable, crece con el contenido */}
        <div className="px-6 py-5 space-y-5">
          {error && (
            <div className="flex items-start gap-2 rounded-control border border-[var(--nx-border-danger)] bg-[var(--nx-subtle-bg-danger)] px-4 py-3">
              <AlertCircle size={16} className="mt-0.5 shrink-0 text-[var(--nx-danger)]" />
              <p className="text-body-sm text-[var(--nx-danger)]">{error}</p>
            </div>
          )}
          {validationError && (
            <div className="flex items-start gap-2 rounded-control border border-[var(--nx-border-warning)] bg-[var(--nx-subtle-bg-warning)] px-4 py-3">
              <AlertCircle size={16} className="mt-0.5 shrink-0 text-[var(--nx-warning)]" />
              <p className="text-body-sm text-[var(--nx-warning)]">{validationError}</p>
            </div>
          )}

          {/* FASE 1: ¿Hay más de una jornada? */}
          {phase === 'multi' && (
            <div className="space-y-5">
              <div>
                <p className="text-body text-[var(--nx-text)] mb-1">
                  {isEditMode
                    ? 'Modifique el calendario escolar de la institución según sea necesario.'
                    : 'Bienvenido. Antes de usar el sistema, debe configurar el calendario escolar de la institución.'}
                </p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">
                  {isEditMode
                    ? 'Los cambios se aplicarán inmediatamente al guardar.'
                    : 'Esta configuración es la base para el funcionamiento del sistema según el calendario escolar de la institución.'}
                </p>
              </div>

              <div>
                <p className="text-body text-[var(--nx-text)] mb-3">
                  ¿La institución tiene más de una jornada?
                </p>
                <div className="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => setHasMultipleShifts(false)}
                    className={`rounded-control border px-4 py-4 text-left transition-all duration-fast ${
                      hasMultipleShifts === false
                        ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)]'
                        : 'border-[var(--nx-border)] hover:border-[var(--nx-border-accent)]'
                    }`}
                  >
                    <div className="flex items-center gap-2">
                      <div className={`grid h-6 w-6 place-items-center rounded-full border ${hasMultipleShifts === false ? 'bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] border-[var(--nx-border-accent)]' : 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] border-[var(--nx-border)]'}`}>
                        {hasMultipleShifts === false && <Check size={14} />}
                      </div>
                      <span className="text-body font-medium text-[var(--nx-text)]">No, una sola</span>
                    </div>
                    <p className="mt-2 text-caption text-[var(--nx-text-muted)]">La institución opera en una única jornada</p>
                  </button>
                  <button
                    type="button"
                    onClick={() => setHasMultipleShifts(true)}
                    className={`rounded-control border px-4 py-4 text-left transition-all duration-fast ${
                      hasMultipleShifts === true
                        ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)]'
                        : 'border-[var(--nx-border)] hover:border-[var(--nx-border-accent)]'
                    }`}
                  >
                    <div className="flex items-center gap-2">
                      <div className={`grid h-6 w-6 place-items-center rounded-full border ${hasMultipleShifts === true ? 'bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] border-[var(--nx-border-accent)]' : 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] border-[var(--nx-border)]'}`}>
                        {hasMultipleShifts === true && <Check size={14} />}
                      </div>
                      <span className="text-body font-medium text-[var(--nx-text)]">Sí, varias</span>
                    </div>
                    <p className="mt-2 text-caption text-[var(--nx-text-muted)]">Ej: mañana y tarde, o mañana, tarde y noche</p>
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* FASE 2: Seleccionar qué jornadas existen */}
          {phase === 'select' && (
            <div className="space-y-5 min-h-[420px]">
              <div>
                <p className="text-body text-[var(--nx-text)] mb-1">¿Qué jornadas tiene la institución?</p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">
                  Seleccione todas las jornadas que operan en la institución. Cada una se configurará por separado.
                </p>
              </div>

              <SearchableSelect
                label="Jornadas de la institución"
                required
                multiple
                options={SHIFT_OPTIONS}
                value={selectedShifts}
                onChange={(vals) => setSelectedShifts(vals || [])}
                placeholder="Seleccionar jornadas…"
                searchPlaceholder="Buscar jornada…"
                emptyText="Sin resultados"
              />
            </div>
          )}

          {/* FASE 3: Configuración de cada jornada */}
          {phase === 'jornada' && jornadas[currentJornadaIdx] && (
            <div className="space-y-5">
              <div className="rounded-control border border-[var(--nx-border-accent)] bg-[var(--nx-subtle-bg-accent)] px-4 py-2">
                <p className="text-caption text-[var(--nx-accent)] font-semibold">
                  Jornada {currentJornadaIdx + 1} de {jornadas.length}: {shiftLabel(jornadas[currentJornadaIdx].work_shift)}
                  {jornadaSubStep === 1 && ' — Bloques horarios'}
                </p>
              </div>

              {/* Sub-step 0: Config general */}
              {jornadaSubStep === 0 && (
                <>
                  <div className="grid grid-cols-2 gap-4">
                    <TimeField
                      label="Hora de entrada"
                      required
                      value={jornadas[currentJornadaIdx].entry_time}
                      onChange={(v) => updateJornada(currentJornadaIdx, 'entry_time', v)}
                      leftIcon={Clock}
                    />
                    <TimeField
                      label="Hora de salida"
                      required
                      value={jornadas[currentJornadaIdx].exit_time}
                      onChange={(v) => updateJornada(currentJornadaIdx, 'exit_time', v)}
                      leftIcon={Clock}
                    />
                  </div>
                  {jornadas[currentJornadaIdx].entry_time && jornadas[currentJornadaIdx].exit_time && (
                    <div className="flex items-center gap-2 text-caption text-[var(--nx-text-muted)]">
                      <Clock size={14} />
                      <span>Jornada: {jornadas[currentJornadaIdx].entry_time} - {jornadas[currentJornadaIdx].exit_time}</span>
                    </div>
                  )}

                  <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-4 space-y-3">
                    <p className="text-label text-[var(--nx-text)]">
                      ¿Esta jornada rota de salones entre clases?
                    </p>
                    <p className="text-caption text-[var(--nx-text-muted)]">
                      Si los estudiantes cambian de aula entre materias, active esta opción para configurar los bloques horarios.
                    </p>
                    <div className="flex gap-3">
                      <button
                        type="button"
                        onClick={() => updateJornada(currentJornadaIdx, 'rotates_classrooms', true)}
                        className={`flex-1 rounded-control border px-4 py-2.5 text-body font-medium transition-all duration-fast ${
                          jornadas[currentJornadaIdx].rotates_classrooms
                            ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]'
                            : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)]'
                        }`}
                      >
                        Sí, rota
                      </button>
                      <button
                        type="button"
                        onClick={() => updateJornada(currentJornadaIdx, 'rotates_classrooms', false)}
                        className={`flex-1 rounded-control border px-4 py-2.5 text-body font-medium transition-all duration-fast ${
                          !jornadas[currentJornadaIdx].rotates_classrooms
                            ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]'
                            : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)]'
                        }`}
                      >
                        No, misma aula
                      </button>
                    </div>
                  </div>

                  <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-4 space-y-3">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2 text-[var(--nx-text-muted)]">
                        <Coffee size={16} />
                        <p className="text-caption">Recesos</p>
                      </div>
                      <button
                        type="button"
                        onClick={() => {
                          const j = jornadas[currentJornadaIdx];
                          const recesses = [...(j.recesses || []), { start: '', end: '' }];
                          updateJornada(currentJornadaIdx, 'recesses', recesses);
                        }}
                        className="flex items-center gap-1 rounded-control bg-[var(--nx-subtle-bg-accent)] px-2.5 py-1.5 text-caption font-medium text-[var(--nx-accent)] hover:bg-[color-mix(in_oklch,var(--nx-accent)_15%,var(--nx-surface-subtle))] transition-colors"
                      >
                        <Plus size={14} /> Agregar receso
                      </button>
                    </div>
                    {(jornadas[currentJornadaIdx].recesses || []).map((recess, rIdx) => (
                      <div key={rIdx} className="flex items-end gap-3">
                        <div className="grid grid-cols-2 gap-4 flex-1">
                          <TimeField
                            label={rIdx === 0 ? 'Inicio' : `Receso ${rIdx + 1} — Inicio`}
                            value={recess.start}
                            onChange={(v) => {
                              const recesses = [...(jornadas[currentJornadaIdx].recesses || [])];
                              recesses[rIdx] = { ...recesses[rIdx], start: v };
                              updateJornada(currentJornadaIdx, 'recesses', recesses);
                            }}
                            leftIcon={Clock}
                          />
                          <TimeField
                            label="Fin"
                            value={recess.end}
                            onChange={(v) => {
                              const recesses = [...(jornadas[currentJornadaIdx].recesses || [])];
                              recesses[rIdx] = { ...recesses[rIdx], end: v };
                              updateJornada(currentJornadaIdx, 'recesses', recesses);
                            }}
                            leftIcon={Clock}
                          />
                        </div>
                        {(jornadas[currentJornadaIdx].recesses || []).length > 1 && (
                          <button
                            type="button"
                            onClick={() => {
                              const recesses = (jornadas[currentJornadaIdx].recesses || []).filter((_, i) => i !== rIdx);
                              updateJornada(currentJornadaIdx, 'recesses', recesses);
                            }}
                            className="shrink-0 pb-2.5 text-[var(--nx-text-muted)] hover:text-[var(--nx-danger)] transition-colors"
                            aria-label="Eliminar receso"
                          >
                            <Trash2 size={16} />
                          </button>
                        )}
                      </div>
                    ))}
                  </div>
                </>
              )}

              {/* Sub-step 1: Bloques horarios (solo si rota) */}
              {jornadaSubStep === 1 && (
                <>
                  <div>
                    <p className="text-body text-[var(--nx-text)] mb-1">Bloques horarios</p>
                    <p className="text-body-sm text-[var(--nx-text-muted)]">
                      Defina las horas de cada clase. Entre clase y clase hay un margen de 10 minutos para que los estudiantes cambien de aula.
                    </p>
                  </div>

                  <Input
                    label="¿Cuántas clases/horas hay cada día?"
                    type="number"
                    min="1"
                    max="12"
                    value={jornadas[currentJornadaIdx].numBlocks}
                    onChange={(e) => updateJornada(currentJornadaIdx, 'numBlocks', Math.max(1, Math.min(12, parseInt(e.target.value) || 1)))}
                  />

                  <div className="space-y-3">
                    {jornadas[currentJornadaIdx].blocks.map((block, bIdx) => (
                      <div key={bIdx} className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 py-3">
                        <div className="flex items-center gap-3">
                          <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[var(--nx-subtle-bg-accent)] text-caption font-semibold text-[var(--nx-accent)]">
                            {bIdx + 1}
                          </span>
                          <div className="grid flex-1 grid-cols-2 gap-3">
                            <TimeField
                              label="Inicio"
                              value={block.start_time}
                              onChange={(v) => updateBlock(currentJornadaIdx, bIdx, 'start_time', v)}
                            />
                            <TimeField
                              label="Fin"
                              value={block.end_time}
                              onChange={(v) => updateBlock(currentJornadaIdx, bIdx, 'end_time', v)}
                            />
                          </div>
                        </div>
                      </div>
                    ))}
                  </div>
                </>
              )}
            </div>
          )}

          {/* FASE: Modalidad técnica */}
          {phase === 'technical' && (
            <div className="space-y-5">
              <div>
                <p className="text-body text-[var(--nx-text)] mb-1">¿El colegio tiene modalidad técnica?</p>
                <p className="text-caption text-[var(--nx-text-muted)]">La modalidad técnica tiene horarios y días diferentes al horario regular.</p>
              </div>

              <div className="flex gap-3">
                <button
                  type="button"
                  onClick={() => { setHasTechModality(true); setTechGrades([]); setTechConfigs({}); }}
                  className={`flex-1 rounded-control border p-4 text-left transition-all ${hasTechModality === true ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)]' : 'border-[var(--nx-border)] hover:border-[var(--nx-border-accent)]'}`}
                >
                  <p className="text-body-sm font-medium text-[var(--nx-text)]">Sí, tiene modalidad técnica</p>
                  <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Algunos grados tienen clases técnicas con horarios especiales</p>
                </button>
                <button
                  type="button"
                  onClick={() => { setHasTechModality(false); setTechGrades([]); setTechConfigs({}); }}
                  className={`flex-1 rounded-control border p-4 text-left transition-all ${hasTechModality === false ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)]' : 'border-[var(--nx-border)] hover:border-[var(--nx-border-accent)]'}`}
                >
                  <p className="text-body-sm font-medium text-[var(--nx-text)]">No, solo horario regular</p>
                  <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Todos los grados siguen el mismo horario</p>
                </button>
              </div>

              {hasTechModality === true && (
                <div className="space-y-4">
                  {/* Selección de grados con modalidad técnica */}
                  <div>
                    <p className="text-label text-[var(--nx-text)] mb-2">¿Qué grados tienen modalidad técnica?</p>
                    <div className="flex flex-wrap gap-2">
                      {['9', '10', '11'].map((grade) => {
                        const selected = techGrades.includes(grade);
                        const labels = { '9': 'Noveno', '10': 'Décimo', '11': 'Once' };
                        return (
                          <button
                            key={grade}
                            type="button"
                            onClick={() => {
                              setTechGrades(prev => selected ? prev.filter(g => g !== grade) : [...prev, grade]);
                            }}
                            className={`flex items-center gap-1.5 rounded-control border px-3 py-2 text-caption transition-all ${selected ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)] text-[var(--nx-accent)]' : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)]'}`}
                          >
                            {selected && <Check size={12} strokeWidth={3} />}
                            {labels[grade]}
                          </button>
                        );
                      })}
                    </div>
                  </div>

                  {/* Configuración por grado */}
                  {techGrades.map((grade) => {
                    const labels = { '9': 'Noveno', '10': 'Décimo', '11': 'Once' };
                    return jornadas.map((j) => {
                      const key = `${grade}-${j.work_shift}`;
                      const cfg = techConfigs[key] || {};
                      const shiftLabels = { 'mañana': 'Mañana', 'tarde': 'Tarde', 'noche': 'Noche', 'completa': 'Completa' };
                      const days = cfg.days || [];
                      const dayLabels = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa', 'Do'];
                      return (
                        <div key={key} className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4 space-y-3">
                          <div className="flex items-center justify-between">
                            <p className="text-body-sm font-medium text-[var(--nx-text)]">
                              {labels[grade]} · {shiftLabels[j.work_shift]}
                            </p>
                            <label className="flex items-center gap-2 text-caption text-[var(--nx-text-muted)]">
                              <input
                                type="checkbox"
                                checked={cfg.uses_blocks ?? false}
                                onChange={(e) => setTechConfigs(prev => ({ ...prev, [key]: { ...cfg, uses_blocks: e.target.checked } }))}
                                className="accent-[var(--nx-accent)]"
                              />
                              Usar bloques
                            </label>
                          </div>

                          {/* Días de la semana */}
                          <div>
                            <p className="text-caption text-[var(--nx-text-muted)] mb-1.5">Días con clases técnicas</p>
                            <div className="flex gap-1.5">
                              {dayLabels.map((dl, idx) => {
                                const dayNum = idx + 1;
                                const isSelected = days.includes(dayNum);
                                return (
                                  <button
                                    key={dayNum}
                                    type="button"
                                    onClick={() => {
                                      setTechConfigs(prev => ({
                                        ...prev,
                                        [key]: { ...cfg, days: isSelected ? days.filter(d => d !== dayNum) : [...days, dayNum] }
                                      }));
                                    }}
                                    className={`grid h-9 w-9 place-items-center rounded-control border text-caption transition-all ${isSelected ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] font-semibold' : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)]'}`}
                                  >
                                    {dl}
                                  </button>
                                );
                              })}
                            </div>
                          </div>

                          {/* Horario de entrada y salida */}
                          <div className="grid grid-cols-2 gap-3">
                            <Input
                              label="Entrada"
                              type="time"
                              value={cfg.entry || ''}
                              onChange={(e) => setTechConfigs(prev => ({ ...prev, [key]: { ...cfg, entry: e.target.value } }))}
                            />
                            <Input
                              label="Salida"
                              type="time"
                              value={cfg.exit || ''}
                              onChange={(e) => setTechConfigs(prev => ({ ...prev, [key]: { ...cfg, exit: e.target.value } }))}
                            />
                          </div>
                        </div>
                      );
                    });
                  })}
                </div>
              )}
            </div>
          )}

          {/* FASE: Revisión final */}
          {phase === 'review' && (
            <div className="space-y-5">
              <div>
                <p className="text-body text-[var(--nx-text)] mb-1">Revisión final</p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">
                  Verifique la configuración de cada jornada antes de guardar.
                </p>
              </div>

              <div className="space-y-3">
                {jornadas.map((j, idx) => (
                  <div key={idx} className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 py-3">
                    <div className="flex items-center gap-2 mb-2">
                      <div className="grid h-7 w-7 place-items-center rounded-surface bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]">
                        <Calendar size={14} />
                      </div>
                      <p className="text-body font-semibold text-[var(--nx-text)]">{shiftLabel(j.work_shift)}</p>
                    </div>
                    <ul className="space-y-1 text-body-sm text-[var(--nx-text)] pl-9">
                      <li>Entrada: <strong>{j.entry_time}</strong> — Salida: <strong>{j.exit_time}</strong></li>
                      <li>Rota salones: <strong>{j.rotates_classrooms ? 'Sí' : 'No'}</strong></li>
                      {j.rotates_classrooms && <li>Bloques: <strong>{j.blocks.length}</strong></li>}
                      {(j.recesses || []).filter(r => r.start && r.end).length > 0 ? (
                        (j.recesses || []).filter(r => r.start && r.end).map((r, ri) => (
                          <li key={ri}>Receso {ri + 1}: <strong>{r.start} - {r.end}</strong></li>
                        ))
                      ) : j.recess_start_time ? (
                        <li>Receso: <strong>{j.recess_start_time} - {j.recess_end_time}</strong></li>
                      ) : null}
                    </ul>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Footer — fijo abajo */}
        <div className="flex items-center justify-between border-t border-[var(--nx-border)] px-6 py-4">
          <div className="flex gap-2">
            {isEditMode && (
              <Button
                variant="ghost"
                onClick={onCancel}
                disabled={loading}
              >
                Cancelar
              </Button>
            )}
            <Button
              variant="secondary"
              onClick={handleBack}
              disabled={phase === 'multi' || loading}
            >
              Atrás
            </Button>
          </div>
          <Button
            variant="primary"
            onClick={handleNext}
            loading={loading}
            disabled={!canProceed()}
          >
            {phase === 'review' ? (isEditMode ? 'Guardar cambios' : 'Guardar y finalizar') : 'Continuar'}
          </Button>
        </div>
      </motion.div>
    </div>
  );
};
