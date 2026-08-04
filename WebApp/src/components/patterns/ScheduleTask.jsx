/**
 * ScheduleTask / NEXO
 * Tarea diaria obligatoria del coordinador: asignar cambios de horario del día.
 * El coordinador marca qué grupos tienen horario distinto, optionally "sin clases",
 * y define horas de entrada/salida modificadas. Si deja vacío, se entiende horario normal.
 */
import { useState, useEffect, useCallback } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { Clock, Check, AlertCircle, X, ChevronRight } from 'lucide-react';
import { studentsApi } from '../../api/students';
import { operationsApi } from '../../api/operations';
import { useAuth } from '../../hooks/useAuth';
import { Surface } from '../ui/Surface';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import { Skeleton } from '../ui/Skeleton';
import { OperationResult } from './OperationResult';
import { formatGroupName } from '../../utils/groupFormat';
import { GRADO_OPTIONS } from '../../config/grados';
import { SearchableSelect } from '../ui/SearchableSelect';

const STORAGE_KEY = 'nexo:schedule-task';

const getShiftFromUser = (user) => {
  const shift = user?.shift || user?.jornada || '';
  const shiftStr = String(shift).toLowerCase();
  if (shiftStr.includes('mañana') || shiftStr.includes('manana') || shiftStr.includes('morning') || shiftStr === 'am') return 'morning';
  if (shiftStr.includes('tarde') || shiftStr.includes('afternoon') || shiftStr === 'pm') return 'afternoon';
  return 'morning';
};

const getActivationTime = (shift) => shift === 'morning' ? '1:00pm' : '6:00pm';

const isTaskActive = (user) => {
  const shift = getShiftFromUser(user);
  const now = new Date();
  const hour = now.getHours();
  if (shift === 'morning') return hour >= 13;
  return hour >= 18;
};

const todayKey = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const isTaskDoneToday = () => {
  try {
    const data = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    return data[todayKey()] === true;
  } catch { return false; }
};

const markTaskDone = () => {
  try {
    const data = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
    data[todayKey()] = true;
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  } catch { /* ignore */ }
};

const ScheduleTask = ({ onDismiss }) => {
  const { user } = useAuth();
  const [groups, setGroups] = useState([]);
  const [selectedGrade, setSelectedGrade] = useState('');
  const [loading, setLoading] = useState(true);
  const [selections, setSelections] = useState({});
  const [reminder, setReminder] = useState(null);
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState(null);
  const [done, setDone] = useState(isTaskDoneToday());

  const shift = getShiftFromUser(user);
  const activationTime = getActivationTime(shift);

  useEffect(() => {
    studentsApi.getGroups(false)
      .then((data) => setGroups(Array.isArray(data) ? data : []))
      .catch(() => setGroups([]))
      .finally(() => setLoading(false));
  }, []);

  const toggleGroup = (groupName) => {
    setSelections((prev) => {
      const next = { ...prev };
      if (next[groupName]) {
        delete next[groupName];
      } else {
        next[groupName] = { noClasses: false, entryTime: '', exitTime: '' };
      }
      return next;
    });
    setReminder(null);
  };

  const updateField = (groupName, field, value) => {
    setSelections((prev) => ({
      ...prev,
      [groupName]: { ...prev[groupName], [field]: value },
    }));
  };

  const handleFieldBlur = (groupName, currentField) => {
    const sel = selections[groupName];
    if (!sel || sel.noClasses) return;
    const hasAnyTime = sel.entryTime || sel.exitTime;
    const currentHasValue = sel[currentField];
    if (hasAnyTime && !currentHasValue && currentField === 'exitTime' && sel.entryTime) {
      setReminder(`Dejar la hora de salida vacía significa horario regular para el grupo ${groupName}.`);
    } else if (hasAnyTime && !currentHasValue && currentField === 'entryTime' && sel.exitTime) {
      setReminder(`Dejar la hora de entrada vacía significa horario regular para el grupo ${groupName}.`);
    }
  };

  const handleFinalize = async () => {
    setSubmitting(true);
    try {
      const changes = Object.entries(selections).map(([groupName, sel]) => ({
        group: groupName,
        no_classes: sel.noClasses,
        entry_time: sel.entryTime || null,
        exit_time: sel.exitTime || null,
      }));

      if (changes.length > 0) {
        await operationsApi.execute('horario', {
          changes,
          date: todayKey(),
        }, '/operations/horario');
      }

      markTaskDone();
      setDone(true);
      setResult({ variant: 'success', message: 'Tarea completada' });
    } catch (err) {
      setResult({ variant: 'danger', message: 'No se pudo completar la tarea. Intenta de nuevo.' });
    } finally {
      setSubmitting(false);
    }
  };

  if (done && !result) {
    return null;
  }

  if (result) {
    return (
      <OperationResult
        variant={result.variant}
        title={result.variant === 'success' ? 'Tarea completada' : 'No se pudo completar'}
        message={result.message}
        onPrimary={() => { setResult(null); onDismiss?.(); }}
        primaryLabel="Volver"
      />
    );
  }

  if (loading) {
    return (
      <Surface className="p-5 space-y-4">
        <div className="flex items-center gap-2 border-b border-[var(--nx-border)] pb-3">
          <div className="h-6 w-0.5 rounded-full bg-[var(--nx-warning)]" />
          <p className="text-label text-[var(--nx-text)]">Tarea pendiente</p>
        </div>
        <Skeleton className="h-6 w-64" />
        <Skeleton className="h-12 w-full" />
        <Skeleton className="h-12 w-full" />
      </Surface>
    );
  }

  const selectedCount = Object.keys(selections).length;

  return (
    <Surface className="p-5 space-y-4 border-[var(--nx-border-warning)]">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2 border-b border-[var(--nx-border)] pb-3 w-full">
          <div className="h-6 w-0.5 rounded-full bg-[var(--nx-warning)]" />
          <div className="flex-1">
            <p className="text-label text-[var(--nx-text)]">Tarea obligatoria</p>
            <p className="text-h3 text-[var(--nx-text)] mt-1">Asignar cambios de horario del día</p>
            <p className="text-body-sm text-[var(--nx-text-muted)] mt-1">
              Marca los grupos que tienen horario distinto hoy. Las casillas vacías significan horario regular.
              Esta tarea se activa a partir de las {activationTime} para el día siguiente.
            </p>
          </div>
          <button onClick={onDismiss} className="p-1 text-[var(--nx-text-muted)] hover:text-[var(--nx-text)] shrink-0">
            <X size={18} />
          </button>
        </div>
      </div>

      <div className="space-y-3">
        <SearchableSelect
          options={GRADO_OPTIONS}
          value={selectedGrade}
          onChange={(v) => setSelectedGrade(v || '')}
          placeholder="Todos los grados"
          searchPlaceholder="Buscar grado…"
          clearable
        />
      </div>

      <div className="space-y-2 max-h-[400px] overflow-y-auto">
        {groups.filter((g) => {
          if (!selectedGrade) return true;
          const gName = g.name || g.group_name || g;
          return String(gName).startsWith(selectedGrade) || g.grade_level === selectedGrade;
        }).map((g) => {
          const groupName = g.name || g.group_name || g;
          const sel = selections[groupName];
          const isSelected = !!sel;
          return (
            <div key={groupName} className="rounded-control border border-[var(--nx-border)] overflow-hidden">
              <div className="flex items-center gap-3 p-3">
                <button
                  onClick={() => toggleGroup(groupName)}
                  className={`flex h-5 w-5 shrink-0 items-center justify-center rounded border transition-all ${
                    isSelected
                      ? 'bg-[var(--nx-warning)] border-[var(--nx-warning)] text-white'
                      : 'border-[var(--nx-border)] hover:border-[var(--nx-warning)]'
                  }`}
                >
                  {isSelected && <Check size={14} />}
                </button>
                <span className="text-body text-[var(--nx-text)] flex-1">{formatGroupName(groupName)}</span>
                {isSelected && (
                  <label className="flex items-center gap-2 text-body-sm text-[var(--nx-text-muted)] cursor-pointer">
                    <input
                      type="checkbox"
                      checked={sel.noClasses}
                      onChange={(e) => updateField(groupName, 'noClasses', e.target.checked)}
                      className="h-4 w-4 rounded border-[var(--nx-border)] accent-[var(--nx-danger)]"
                    />
                    Sin clases
                  </label>
                )}
              </div>
              <AnimatePresence>
                {isSelected && !sel.noClasses && (
                  <motion.div
                    initial={{ height: 0, opacity: 0 }}
                    animate={{ height: 'auto', opacity: 1 }}
                    exit={{ height: 0, opacity: 0 }}
                    transition={{ duration: 0.15 }}
                    className="border-t border-[var(--nx-border)] p-3 space-y-3"
                  >
                    <div className="grid grid-cols-2 gap-3">
                      <Input
                        type="time"
                        label="Hora de entrada"
                        value={sel.entryTime}
                        onChange={(e) => updateField(groupName, 'entryTime', e.target.value)}
                        onBlur={() => handleFieldBlur(groupName, 'entryTime')}
                        placeholder="Horario regular"
                      />
                      <Input
                        type="time"
                        label="Hora de salida"
                        value={sel.exitTime}
                        onChange={(e) => updateField(groupName, 'exitTime', e.target.value)}
                        onBlur={() => handleFieldBlur(groupName, 'exitTime')}
                        placeholder="Horario regular"
                      />
                    </div>
                    <p className="text-caption text-[var(--nx-text-muted)]">
                      Dejar vacío significa horario regular.
                    </p>
                  </motion.div>
                )}
                {isSelected && sel.noClasses && (
                  <motion.div
                    initial={{ height: 0, opacity: 0 }}
                    animate={{ height: 'auto', opacity: 1 }}
                    exit={{ height: 0, opacity: 0 }}
                    className="border-t border-[var(--nx-border)] p-3"
                  >
                    <p className="text-body-sm text-[var(--nx-danger)] flex items-center gap-2">
                      <AlertCircle size={14} /> Este grupo no tendrá clases hoy.
                    </p>
                  </motion.div>
                )}
              </AnimatePresence>
            </div>
          );
        })}
      </div>

      <AnimatePresence>
        {reminder && (
          <motion.div
            initial={{ opacity: 0, y: -8 }}
            animate={{ opacity: 1, y: 0 }}
            exit={{ opacity: 0, y: -8 }}
            className="rounded-control bg-[var(--nx-subtle-bg-warning)] px-4 py-3 text-body-sm text-[var(--nx-warning)] flex items-center gap-2"
          >
            <AlertCircle size={16} className="shrink-0" />
            {reminder}
            <button onClick={() => setReminder(null)} className="ml-auto p-0.5">
              <X size={14} />
            </button>
          </motion.div>
        )}
      </AnimatePresence>

      <div className="flex items-center justify-between pt-2 border-t border-[var(--nx-border)]">
        <p className="text-caption text-[var(--nx-text-muted)]">
          {selectedCount} grupo{selectedCount !== 1 ? 's' : ''} con cambios
        </p>
        <Button onClick={handleFinalize} loading={submitting} leftIcon={<Check size={16} />}>
          Finalizar
        </Button>
      </div>
    </Surface>
  );
};

export { ScheduleTask, isTaskActive, isTaskDoneToday };
export default ScheduleTask;
