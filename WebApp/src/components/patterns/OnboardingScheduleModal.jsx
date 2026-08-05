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
 */
import { useState, useEffect, useMemo } from 'react';
import { Clock, AlertCircle, Calendar, Coffee, Check, Sun, Moon, Sunset } from 'lucide-react';
import { motion } from 'framer-motion';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import { schoolApi } from '../../api/school';

const EASE = [0.22, 1, 0.36, 1];

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
  numBlocks: 6,
  blocks: [],
});

export const OnboardingScheduleModal = ({ schoolId, userId, role, onCompleted }) => {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // Fase: 'multi' → 'select' → 'jornada' → 'review'
  const [phase, setPhase] = useState('multi');

  // Fase 'multi': ¿hay más de una jornada?
  const [hasMultipleShifts, setHasMultipleShifts] = useState(null);

  // Fase 'select': qué jornadas existen
  const [selectedShifts, setSelectedShifts] = useState([]);

  // Fase 'jornada': configuración por jornada
  const [jornadas, setJornadas] = useState([]);
  const [currentJornadaIdx, setCurrentJornadaIdx] = useState(0);
  const [jornadaSubStep, setJornadaSubStep] = useState(0); // 0=config general, 1=bloques (si rota)

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
    let total = 1; // fase 'multi'
    if (hasMultipleShifts === true) total += 1; // fase 'select'
    // fase 'jornada': 1 paso por jornada (no rota) o 2 pasos (rota)
    const jornadaSteps = jornadas.reduce((acc, j) => acc + (j.rotates_classrooms ? 2 : 1), 0);
    total += jornadaSteps;
    if (jornadas.length > 0) total += 1; // fase 'review'

    let current = 1;
    if (phase === 'multi') current = 1;
    else if (phase === 'select') current = 2;
    else if (phase === 'jornada') {
      current = 2;
      for (let i = 0; i < currentJornadaIdx; i++) {
        current += jornadas[i].rotates_classrooms ? 2 : 1;
      }
      current += jornadaSubStep + 1;
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

  // Validar paso actual
  const canProceed = () => {
    if (phase === 'multi') return hasMultipleShifts !== null;
    if (phase === 'select') return selectedShifts.length > 0;
    if (phase === 'jornada') {
      const j = jornadas[currentJornadaIdx];
      if (!j) return false;
      if (jornadaSubStep === 1) {
        // Validar bloques
        return j.blocks.length > 0 && j.blocks.every(b => b.start_time && b.end_time);
      }
      // Sub-step 0: config general
      if (!j.entry_time || !j.exit_time) return false;
      if (j.recess_start_time && !j.recess_end_time) return false;
      if (j.recess_end_time && !j.recess_start_time) return false;
      return true;
    }
    if (phase === 'review') return true;
    return false;
  };

  const handleNext = () => {
    setError('');
    if (phase === 'multi') {
      if (hasMultipleShifts === false) {
        // Una sola jornada: preseleccionar 'mañana' y ir directo a configurar
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
        // Ir a sub-paso de bloques
        setJornadaSubStep(1);
        return;
      }
      // Avanzar a la siguiente jornada o a revisión
      if (currentJornadaIdx < jornadas.length - 1) {
        setCurrentJornadaIdx(currentJornadaIdx + 1);
        setJornadaSubStep(0);
      } else {
        setPhase('review');
      }
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
      // Volver a la última jornada
      const lastIdx = jornadas.length - 1;
      setCurrentJornadaIdx(lastIdx);
      setJornadaSubStep(jornadas[lastIdx].rotates_classrooms ? 1 : 0);
      setPhase('jornada');
      return;
    }
    if (phase === 'jornada') {
      if (jornadaSubStep === 1) {
        // Volver al sub-paso de config general de la misma jornada
        setJornadaSubStep(0);
        return;
      }
      // Sub-step 0: volver a la jornada anterior o a fase anterior
      if (currentJornadaIdx > 0) {
        const prevIdx = currentJornadaIdx - 1;
        setCurrentJornadaIdx(prevIdx);
        setJornadaSubStep(jornadas[prevIdx].rotates_classrooms ? 1 : 0);
      } else {
        // Volver a 'select' o 'multi'
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
        jornadas: jornadas.map(j => ({
          work_shift: j.work_shift,
          rotates_classrooms: j.rotates_classrooms,
          entry_time: j.entry_time,
          exit_time: j.exit_time,
          recess_start_time: j.recess_start_time || null,
          recess_end_time: j.recess_end_time || null,
          time_blocks: j.rotates_classrooms ? j.blocks.map(b => ({
            block_number: b.block_number,
            block_name: b.block_name,
            start_time: b.start_time,
            end_time: b.end_time,
          })) : [],
        })),
      };
      const result = await schoolApi.completeOnboarding(payload);
      if (result.status === 'ok') {
        onCompleted?.(result);
      } else {
        setError(result.message || 'Error al guardar configuración');
      }
    } catch (e) {
      const msg = e?.response?.data?.message || e.message || 'Error de conexión';
      setError(msg);
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
        className="relative z-10 w-full max-w-[640px] rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-dialog max-h-[90vh] overflow-y-auto"
      >
        {/* Header — sin botón de cerrar (onboarding obligatorio) */}
        <div className="border-b border-[var(--nx-border)] px-6 py-5">
          <div className="flex items-center gap-3">
            <div className="grid h-10 w-10 place-items-center rounded-surface bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]">
              <Calendar size={20} strokeWidth={1.75} />
            </div>
            <div>
              <h2 className="text-h3 text-[var(--nx-text)]">Configuración de Horarios</h2>
              <p className="text-body-sm text-[var(--nx-text-muted)]">
                Paso {currentStep} de {totalSteps} — {phaseTitle[phase]}
              </p>
            </div>
          </div>
          {/* Progress bar */}
          <div className="mt-4 flex gap-1.5">
            {Array.from({ length: totalSteps }).map((_, i) => (
              <div
                key={i}
                className={`h-1 flex-1 rounded-full transition-colors duration-fast ${
                  i + 1 <= currentStep ? 'bg-[var(--nx-accent)]' : 'bg-[var(--nx-border)]'
                }`}
              />
            ))}
          </div>
        </div>

        {/* Body */}
        <div className="px-6 py-5 space-y-5">
          {error && (
            <div className="flex items-start gap-2 rounded-control border border-[var(--nx-border-danger)] bg-[var(--nx-subtle-bg-danger)] px-4 py-3">
              <AlertCircle size={16} className="mt-0.5 shrink-0 text-[var(--nx-danger)]" />
              <p className="text-body-sm text-[var(--nx-danger)]">{error}</p>
            </div>
          )}

          {/* FASE 1: ¿Hay más de una jornada? */}
          {phase === 'multi' && (
            <div className="space-y-5">
              <div>
                <p className="text-body text-[var(--nx-text)] mb-1">
                  Bienvenido. Antes de usar el sistema, debe configurar los horarios de la institución.
                </p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">
                  Esta configuración se usa para la detección automática de inasistencias y evasión.
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
                      <div className={`grid h-6 w-6 place-items-center rounded-full ${hasMultipleShifts === false ? 'bg-[var(--nx-accent)] text-[var(--nx-accent-text)]' : 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]'}`}>
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
                      <div className={`grid h-6 w-6 place-items-center rounded-full ${hasMultipleShifts === true ? 'bg-[var(--nx-accent)] text-[var(--nx-accent-text)]' : 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]'}`}>
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
            <div className="space-y-5">
              <div>
                <p className="text-body text-[var(--nx-text)] mb-1">¿Qué jornadas tiene la institución?</p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">
                  Seleccione todas las jornadas que operan en la institución. Cada una se configurará por separado.
                </p>
              </div>

              <div className="space-y-2">
                {SHIFT_OPTIONS.map((opt) => {
                  const Icon = opt.icon;
                  const isSelected = selectedShifts.includes(opt.value);
                  return (
                    <button
                      key={opt.value}
                      type="button"
                      onClick={() => {
                        if (isSelected) {
                          setSelectedShifts(selectedShifts.filter(s => s !== opt.value));
                        } else {
                          setSelectedShifts([...selectedShifts, opt.value]);
                        }
                      }}
                      className={`w-full rounded-control border px-4 py-3 flex items-center gap-3 transition-all duration-fast ${
                        isSelected
                          ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)]'
                          : 'border-[var(--nx-border)] hover:border-[var(--nx-border-accent)]'
                      }`}
                    >
                      <div className={`grid h-8 w-8 place-items-center rounded-surface ${isSelected ? 'bg-[var(--nx-accent)] text-[var(--nx-accent-text)]' : 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]'}`}>
                        <Icon size={16} />
                      </div>
                      <span className="flex-1 text-left text-body font-medium text-[var(--nx-text)]">{opt.label}</span>
                      <div className={`grid h-5 w-5 place-items-center rounded-full border ${isSelected ? 'border-[var(--nx-accent)] bg-[var(--nx-accent)] text-[var(--nx-accent-text)]' : 'border-[var(--nx-border)]'}`}>
                        {isSelected && <Check size={12} />}
                      </div>
                    </button>
                  );
                })}
              </div>
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
                    <Input
                      label="Hora de entrada"
                      type="time"
                      required
                      value={jornadas[currentJornadaIdx].entry_time}
                      onChange={(e) => updateJornada(currentJornadaIdx, 'entry_time', e.target.value)}
                      leftIcon={<Clock size={16} />}
                    />
                    <Input
                      label="Hora de salida"
                      type="time"
                      required
                      value={jornadas[currentJornadaIdx].exit_time}
                      onChange={(e) => updateJornada(currentJornadaIdx, 'exit_time', e.target.value)}
                      leftIcon={<Clock size={16} />}
                    />
                  </div>

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
                    <div className="flex items-center gap-2 text-[var(--nx-text-muted)]">
                      <Coffee size={16} />
                      <p className="text-caption">Receso / Almuerzo (opcional)</p>
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                      <Input
                        label="Inicio del receso"
                        type="time"
                        value={jornadas[currentJornadaIdx].recess_start_time}
                        onChange={(e) => updateJornada(currentJornadaIdx, 'recess_start_time', e.target.value)}
                        leftIcon={<Clock size={16} />}
                      />
                      <Input
                        label="Fin del receso"
                        type="time"
                        value={jornadas[currentJornadaIdx].recess_end_time}
                        onChange={(e) => updateJornada(currentJornadaIdx, 'recess_end_time', e.target.value)}
                        leftIcon={<Clock size={16} />}
                      />
                    </div>
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
                            <Input
                              label="Inicio"
                              type="time"
                              value={block.start_time}
                              onChange={(e) => updateBlock(currentJornadaIdx, bIdx, 'start_time', e.target.value)}
                            />
                            <Input
                              label="Fin"
                              type="time"
                              value={block.end_time}
                              onChange={(e) => updateBlock(currentJornadaIdx, bIdx, 'end_time', e.target.value)}
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

          {/* FASE 4: Revisión final */}
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
                      {j.recess_start_time && <li>Receso: <strong>{j.recess_start_time} - {j.recess_end_time}</strong></li>}
                    </ul>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between border-t border-[var(--nx-border)] px-6 py-4">
          <Button
            variant="secondary"
            onClick={handleBack}
            disabled={phase === 'multi' || loading}
          >
            Atrás
          </Button>
          <Button
            variant="primary"
            onClick={handleNext}
            loading={loading}
            disabled={!canProceed()}
          >
            {phase === 'review' ? 'Guardar y finalizar' : 'Continuar'}
          </Button>
        </div>
      </motion.div>
    </div>
  );
};
