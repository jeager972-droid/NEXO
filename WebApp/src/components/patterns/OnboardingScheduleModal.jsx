/**
 * OnboardingScheduleModal — Modal bloqueante para onboarding de horarios.
 * Se muestra cuando onboarding_completed=FALSE para RECTOR/COORDINATOR.
 * No se puede cerrar hasta completar el formulario.
 */
import { useState, useEffect } from 'react';
import { Clock, Plus, Trash2, AlertCircle, Calendar, Coffee } from 'lucide-react';
import { motion } from 'framer-motion';
import { Dialog } from '../ui/Overlay';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import { Select } from '../ui/Select';
import { schoolApi } from '../../api/school';

const EASE = [0.22, 1, 0.36, 1];

export const OnboardingScheduleModal = ({ schoolId, userId, role, onCompleted }) => {
  const [step, setStep] = useState(1); // 1=jornada, 2=bloques (si rota), 3=receso
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  // Config
  const [rotatesClassrooms, setRotatesClassrooms] = useState(false);
  const [workShift, setWorkShift] = useState('mañana');
  const [entryTime, setEntryTime] = useState('');
  const [exitTime, setExitTime] = useState('');
  const [recessStartTime, setRecessStartTime] = useState('');
  const [recessEndTime, setRecessEndTime] = useState('');
  const [numBlocks, setNumBlocks] = useState(6);
  const [blocks, setBlocks] = useState([]);

  // Inicializar bloques cuando cambia numBlocks
  useEffect(() => {
    if (rotatesClassrooms) {
      const newBlocks = [];
      for (let i = 0; i < numBlocks; i++) {
        newBlocks.push({
          block_number: i + 1,
          block_name: `Clase ${i + 1}`,
          start_time: blocks[i]?.start_time || '',
          end_time: blocks[i]?.end_time || '',
        });
      }
      setBlocks(newBlocks);
    }
  }, [numBlocks, rotatesClassrooms]); // eslint-disable-line react-hooks/exhaustive-deps

  const totalSteps = rotatesClassrooms ? 3 : 2;
  const canProceed = () => {
    if (step === 1) {
      return workShift && entryTime && exitTime;
    }
    if (step === 2 && rotatesClassrooms) {
      return blocks.every(b => b.start_time && b.end_time);
    }
    if (step === (rotatesClassrooms ? 3 : 2)) {
      // Receso opcional, pero si viene uno debe venir el otro
      if (recessStartTime && !recessEndTime) return false;
      if (recessEndTime && !recessStartTime) return false;
      return true;
    }
    return true;
  };

  const handleNext = () => {
    setError('');
    if (step < totalSteps) {
      setStep(step + 1);
    } else {
      handleSubmit();
    }
  };

  const handleBack = () => {
    setError('');
    if (step > 1) setStep(step - 1);
  };

  const handleSubmit = async () => {
    setLoading(true);
    setError('');
    try {
      const payload = {
        rotates_classrooms: rotatesClassrooms,
        work_shift: workShift,
        entry_time: entryTime,
        exit_time: exitTime,
        recess_start_time: recessStartTime || null,
        recess_end_time: recessEndTime || null,
      };
      if (rotatesClassrooms) {
        payload.time_blocks = blocks.map(b => ({
          block_number: b.block_number,
          block_name: b.block_name,
          start_time: b.start_time,
          end_time: b.end_time,
        }));
      }
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

  const updateBlock = (idx, field, value) => {
    setBlocks(prev => prev.map((b, i) => i === idx ? { ...b, [field]: value } : b));
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
                Paso {step} de {totalSteps} — Configuración obligatoria inicial
              </p>
            </div>
          </div>
          {/* Progress bar */}
          <div className="mt-4 flex gap-1.5">
            {Array.from({ length: totalSteps }).map((_, i) => (
              <div
                key={i}
                className={`h-1 flex-1 rounded-full transition-colors duration-fast ${
                  i + 1 <= step ? 'bg-[var(--nx-accent)]' : 'bg-[var(--nx-border)]'
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

          {/* STEP 1: Jornada y horas principales */}
          {step === 1 && (
            <div className="space-y-5">
              <div>
                <p className="text-body text-[var(--nx-text)] mb-1">
                  Bienvenido. Antes de usar el sistema, debe configurar los horarios de la institución.
                </p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">
                  Esta configuración se usa para la detección automática de inasistencias y evasión.
                </p>
              </div>

              <Select
                label="Jornada de la institución"
                required
                value={workShift}
                onChange={(e) => setWorkShift(e.target.value)}
                options={[
                  { value: 'mañana', label: 'Mañana' },
                  { value: 'tarde', label: 'Tarde' },
                  { value: 'completa', label: 'Completa (mañana y tarde)' },
                ]}
              />

              <div className="grid grid-cols-2 gap-4">
                <Input
                  label="Hora de entrada"
                  type="time"
                  required
                  value={entryTime}
                  onChange={(e) => setEntryTime(e.target.value)}
                  leftIcon={<Clock size={16} />}
                />
                <Input
                  label="Hora de salida"
                  type="time"
                  required
                  value={exitTime}
                  onChange={(e) => setExitTime(e.target.value)}
                  leftIcon={<Clock size={16} />}
                />
              </div>

              <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-4 space-y-3">
                <p className="text-label text-[var(--nx-text)]">
                  ¿La institución rota de salones entre clases?
                </p>
                <p className="text-caption text-[var(--nx-text-muted)]">
                  Si los estudiantes cambian de aula entre materias, active esta opción para configurar los bloques horarios.
                </p>
                <div className="flex gap-3">
                  <button
                    type="button"
                    onClick={() => setRotatesClassrooms(false)}
                    className={`flex-1 rounded-control border px-4 py-3 text-body-sm transition-colors ${
                      !rotatesClassrooms
                        ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]'
                        : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)]'
                    }`}
                  >
                    No rota
                  </button>
                  <button
                    type="button"
                    onClick={() => setRotatesClassrooms(true)}
                    className={`flex-1 rounded-control border px-4 py-3 text-body-sm transition-colors ${
                      rotatesClassrooms
                        ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]'
                        : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)]'
                    }`}
                  >
                    Sí rota
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* STEP 2: Bloques horarios (solo si rota) */}
          {step === 2 && rotatesClassrooms && (
            <div className="space-y-5">
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
                value={numBlocks}
                onChange={(e) => setNumBlocks(Math.max(1, Math.min(12, parseInt(e.target.value) || 1)))}
              />

              <div className="space-y-3">
                {blocks.map((block, idx) => (
                  <div key={idx} className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 py-3">
                    <div className="flex items-center gap-3">
                      <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-[var(--nx-subtle-bg-accent)] text-caption font-semibold text-[var(--nx-accent)]">
                        {idx + 1}
                      </span>
                      <div className="grid flex-1 grid-cols-2 gap-3">
                        <Input
                          label="Inicio"
                          type="time"
                          value={block.start_time}
                          onChange={(e) => updateBlock(idx, 'start_time', e.target.value)}
                        />
                        <Input
                          label="Fin"
                          type="time"
                          value={block.end_time}
                          onChange={(e) => updateBlock(idx, 'end_time', e.target.value)}
                        />
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {/* STEP 2 (no rota) o STEP 3 (rota): Receso */}
          {step === (rotatesClassrooms ? 3 : 2) && (
            <div className="space-y-5">
              <div>
                <p className="text-body text-[var(--nx-text)] mb-1">Receso / Almuerzo</p>
                <p className="text-body-sm text-[var(--nx-text-muted)]">
                  Defina el horario del receso. 10 minutos después del fin del receso, si un estudiante no ha regresado y no tiene permiso, se activará una alerta de evasión.
                </p>
              </div>

              <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-4 space-y-3">
                <div className="flex items-center gap-2 text-[var(--nx-text-muted)]">
                  <Coffee size={16} />
                  <p className="text-caption">Opcional — pero recomendado</p>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <Input
                    label="Inicio del receso"
                    type="time"
                    value={recessStartTime}
                    onChange={(e) => setRecessStartTime(e.target.value)}
                    leftIcon={<Clock size={16} />}
                  />
                  <Input
                    label="Fin del receso"
                    type="time"
                    value={recessEndTime}
                    onChange={(e) => setRecessEndTime(e.target.value)}
                    leftIcon={<Clock size={16} />}
                  />
                </div>
              </div>

              <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 py-3">
                <p className="text-caption text-[var(--nx-text-muted)]">
                  Resumen de configuración:
                </p>
                <ul className="mt-2 space-y-1 text-body-sm text-[var(--nx-text)]">
                  <li>Jornada: <strong>{workShift}</strong></li>
                  <li>Entrada: <strong>{entryTime}</strong> — Salida: <strong>{exitTime}</strong></li>
                  <li>Rota salones: <strong>{rotatesClassrooms ? 'Sí' : 'No'}</strong></li>
                  {rotatesClassrooms && <li>Bloques: <strong>{numBlocks}</strong></li>}
                  {recessStartTime && <li>Receso: <strong>{recessStartTime} - {recessEndTime}</strong></li>}
                </ul>
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between border-t border-[var(--nx-border)] px-6 py-4">
          <Button
            variant="secondary"
            onClick={handleBack}
            disabled={step === 1 || loading}
          >
            Atrás
          </Button>
          <Button
            variant="primary"
            onClick={handleNext}
            loading={loading}
            disabled={!canProceed()}
          >
            {step === totalSteps ? 'Guardar y finalizar' : 'Continuar'}
          </Button>
        </div>
      </motion.div>
    </div>
  );
};
