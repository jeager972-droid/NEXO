/**
 * OnboardingGroupsModal — Wizard bloqueante para configuración de grupos académicos.
 * Solo RECTOR. Se activa cada 1 de enero o cuando falta configuración.
 *
 * Flujo de pasos:
 *   Paso 1: Seleccionar grados (Primero a Once)
 *   Paso 2: Elegir nomenclatura (Alfabética, Numérica, Otra)
 *   Paso 3: Configurar cuántos grupos por grado + jornada por grado
 *   Paso 4: Asignar docentes a cada grupo (obligatorio, ≥1 por grupo)
 *
 * Al guardar: borra grupos del año actual, crea los nuevos con work_shift,
 * reasigna estudiantes existentes por grade_level, asigna docentes via
 * teacher_group_access, y auto-crea sensores.
 */
import { useState, useMemo, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  Check, ChevronLeft, ChevronRight,
  GraduationCap, Hash, Type, Edit3, Calendar, Sun, Moon, Clock,
} from 'lucide-react';
import { schoolApi } from '../../api/school';
import { Button } from '../ui/Button';
import { Input } from '../ui/Input';
import { Stepper } from '../ui/Stepper';
import { SearchableSelect } from '../ui/SearchableSelect';
import { humanizeError } from '../../utils/messages';

const EASE = [0.22, 1, 0.36, 1];
const STEPS = ['Grados', 'Nomenclatura', 'Grupos y jornadas', 'Docentes'];

const ALL_GRADES = [
  { value: '1', label: 'Primero' },
  { value: '2', label: 'Segundo' },
  { value: '3', label: 'Tercero' },
  { value: '4', label: 'Cuarto' },
  { value: '5', label: 'Quinto' },
  { value: '6', label: 'Sexto' },
  { value: '7', label: 'Séptimo' },
  { value: '8', label: 'Octavo' },
  { value: '9', label: 'Noveno' },
  { value: '10', label: 'Décimo' },
  { value: '11', label: 'Once' },
];

const NOMENCLATURES = [
  { value: 'alphabetic', label: 'Alfabética', example: '7A, 7B, 7C', icon: Type, desc: 'Letras después del grado' },
  { value: 'numeric', label: 'Numérica', example: '7-1, 7-2, 7-3', icon: Hash, desc: 'Números con guion' },
  { value: 'other', label: 'Otra', example: '7-1, 7-2 (personalizado)', icon: Edit3, desc: 'Separador personalizado' },
];

const SHIFTS = [
  { value: 'mañana', label: 'Mañana', icon: Sun },
  { value: 'tarde', label: 'Tarde', icon: Moon },
  { value: 'noche', label: 'Noche', icon: Clock },
  { value: 'completa', label: 'Completa', icon: GraduationCap },
];

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'];

const generateGroupName = (grade, nomenclature, index, separator) => {
  if (nomenclature === 'alphabetic') {
    return `${grade}${LETTERS[index] || (index + 1)}`;
  }
  if (nomenclature === 'numeric') {
    return `${grade}-${index + 1}`;
  }
  const sep = separator || '-';
  return `${grade}${sep}${index + 1}`;
};

export const OnboardingGroupsModal = ({ onCompleted, onCancel, isEdit = false }) => {
  const [step, setStep] = useState(1);
  const [selectedGrades, setSelectedGrades] = useState([]);
  const [nomenclature, setNomenclature] = useState('alphabetic');
  const [separator, setSeparator] = useState('-');
  const [groupsPerGrade, setGroupsPerGrade] = useState({});
  const [gradeShifts, setGradeShifts] = useState({});
  const [teacherAssignments, setTeacherAssignments] = useState({});
  const [teachers, setTeachers] = useState([]);
  const [loadingTeachers, setLoadingTeachers] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  // Cargar docentes al llegar al paso 4
  useEffect(() => {
    if (step === 4 && teachers.length === 0 && !loadingTeachers) {
      setLoadingTeachers(true);
      schoolApi.getTeachers()
        .then((res) => {
          if (res.status === 'ok') setTeachers(res.data || []);
        })
        .catch(() => {})
        .finally(() => setLoadingTeachers(false));
    }
  }, [step, teachers.length, loadingTeachers]);

  // Lista de grupos generados (computada)
  const generatedGroups = useMemo(() => {
    const groups = [];
    for (const grade of selectedGrades) {
      const count = groupsPerGrade[grade] || 1;
      const shift = gradeShifts[grade] || 'mañana';
      for (let i = 0; i < count; i++) {
        const name = generateGroupName(grade, nomenclature, i, separator);
        groups.push({ grade, name, shift });
      }
    }
    return groups;
  }, [selectedGrades, groupsPerGrade, gradeShifts, nomenclature, separator]);

  // Validación por paso
  const canNext = step === 1 ? selectedGrades.length > 0
    : step === 2 ? !!nomenclature
    : step === 3 ? selectedGrades.every((g) => gradeShifts[g])
    : step === 4 ? generatedGroups.every((g) => (teacherAssignments[g.name] || []).length > 0)
    : false;

  const toggleGrade = (value) => {
    setSelectedGrades((prev) => {
      const next = prev.includes(value) ? prev.filter((g) => g !== value) : [...prev, value].sort((a, b) => Number(a) - Number(b));
      const newGpg = {};
      const newShifts = {};
      for (const g of next) {
        newGpg[g] = groupsPerGrade[g] || 1;
        newShifts[g] = gradeShifts[g] || 'mañana';
      }
      setGroupsPerGrade(newGpg);
      setGradeShifts(newShifts);
      return next;
    });
  };

  const setShiftForGrade = (grade, shift) => {
    setGradeShifts((prev) => ({ ...prev, [grade]: shift }));
  };

  const handleSave = async () => {
    setSaving(true);
    setError('');
    try {
      await schoolApi.completeGroupsOnboarding({
        grades: selectedGrades,
        nomenclature,
        nomenclature_separator: separator,
        groups_per_grade: groupsPerGrade,
        grade_shifts: gradeShifts,
        teacher_assignments: teacherAssignments,
      });
      onCompleted?.();
    } catch (err) {
      setError(humanizeError(err, 'No se pudo guardar la configuración.'));
    } finally {
      setSaving(false);
    }
  };

  const previewGroups = useMemo(() => {
    const previews = [];
    for (const grade of selectedGrades.slice(0, 3)) {
      const count = groupsPerGrade[grade] || 1;
      const names = [];
      for (let i = 0; i < Math.min(count, 4); i++) {
        names.push(generateGroupName(grade, nomenclature, i, separator));
      }
      if (count > 4) names.push(`... (+${count - 4})`);
      previews.push({ grade, names });
    }
    return previews;
  }, [selectedGrades, nomenclature, separator, groupsPerGrade]);

  // Filtrar docentes por jornada del grupo (mañana/tarde/noche) + completas + sin jornada
  const teachersForShift = (shift) => {
    if (shift === 'completa') return teachers;
    return teachers.filter((t) => !t.work_shift || t.work_shift === shift || t.work_shift === 'completa');
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-[var(--nx-canvas)] p-4">
      <motion.div
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3, ease: EASE }}
        className="w-full max-w-2xl rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-large"
      >
        {/* Header */}
        <div className="relative overflow-hidden rounded-t-surface">
          <div className="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-[var(--nx-accent)] via-[oklch(52%_0.125_245)] to-[var(--nx-accent)]" />
          <div className="flex items-center justify-between p-6 pb-4">
            <div className="flex items-center gap-3">
              <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]">
                <GraduationCap size={22} />
              </span>
              <div>
                <h2 className="text-h2 text-[var(--nx-text)]">Configuración de grupos</h2>
                <p className="text-body-sm text-[var(--nx-text-muted)]">Año electivo {new Date().getFullYear()}</p>
              </div>
            </div>
          </div>
          <div className="px-6 pb-4">
            <Stepper steps={STEPS} current={step - 1} />
          </div>
        </div>

        {/* Error */}
        {error && (
          <div className="mx-6 mb-4 rounded-control bg-[var(--nx-subtle-bg-danger)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">
            {error}
          </div>
        )}

        {/* Content */}
        <div className="max-h-[55vh] overflow-y-auto p-6">
          <AnimatePresence mode="wait">
            <motion.div
              key={step}
              initial={{ opacity: 0, x: 10 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: -10 }}
              transition={{ duration: 0.16 }}
            >
              {/* Paso 1: Grados */}
              {step === 1 && (
                <div className="space-y-4">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">Selecciona los grados que maneja tu institución</p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Marca las casillas de los grados que existen este año</p>
                  </div>
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    {ALL_GRADES.map((grade) => {
                      const checked = selectedGrades.includes(grade.value);
                      return (
                        <button
                          key={grade.value}
                          type="button"
                          onClick={() => toggleGrade(grade.value)}
                          className={`flex items-center gap-3 rounded-control border p-4 text-left transition-all ${
                            checked
                              ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)] shadow-low'
                              : 'border-[var(--nx-border)] bg-[var(--nx-surface)] hover:border-[var(--nx-border-accent)]'
                          }`}
                        >
                          <span className={`grid h-6 w-6 shrink-0 place-items-center rounded-control border ${
                            checked
                              ? 'border-[var(--nx-border-accent)] bg-[var(--nx-surface-accent)] text-[var(--nx-accent)]'
                              : 'border-[var(--nx-border)] text-transparent'
                          }`}>
                            {checked && <Check size={14} strokeWidth={3} />}
                          </span>
                          <span className="text-body-sm text-[var(--nx-text)] font-medium">{grade.label}</span>
                        </button>
                      );
                    })}
                  </div>
                </div>
              )}

              {/* Paso 2: Nomenclatura */}
              {step === 2 && (
                <div className="space-y-4">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">¿Qué nomenclatura usas para distinguir los grupos?</p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Esto define cómo se nombran los grupos dentro de cada grado</p>
                  </div>
                  <div className="space-y-3">
                    {NOMENCLATURES.map((nom) => {
                      const Icon = nom.icon;
                      const selected = nomenclature === nom.value;
                      return (
                        <button
                          key={nom.value}
                          type="button"
                          onClick={() => setNomenclature(nom.value)}
                          className={`flex w-full items-center gap-4 rounded-control border p-4 text-left transition-all ${
                            selected
                              ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)] shadow-low'
                              : 'border-[var(--nx-border)] bg-[var(--nx-surface)] hover:border-[var(--nx-border-accent)]'
                          }`}
                        >
                          <span className={`grid h-10 w-10 shrink-0 place-items-center rounded-control ${
                            selected ? 'bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]' : 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]'
                          }`}>
                            <Icon size={18} />
                          </span>
                          <div className="min-w-0 flex-1">
                            <p className="text-body-sm text-[var(--nx-text)] font-semibold">{nom.label}</p>
                            <p className="text-caption text-[var(--nx-text-muted)]">{nom.desc}</p>
                          </div>
                          <code className="shrink-0 rounded-control bg-[var(--nx-surface-subtle)] px-2.5 py-1 text-caption text-[var(--nx-text-muted)] font-mono">
                            {nom.example}
                          </code>
                        </button>
                      );
                    })}
                  </div>
                  {nomenclature === 'other' && (
                    <Input
                      label="Separador personalizado"
                      value={separator}
                      onChange={(e) => setSeparator(e.target.value)}
                      placeholder="Ej. - , . /"
                      help="Carácter que separa el grado del número de grupo"
                    />
                  )}
                </div>
              )}

              {/* Paso 3: Grupos por grado + jornada por grado */}
              {step === 3 && (
                <div className="space-y-4">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">¿Cuántos grupos hay por grado y en qué jornada?</p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Para cada grado, indica el número de grupos (salones) y la jornada</p>
                  </div>
                  <div className="space-y-3">
                    {selectedGrades.map((grade) => {
                      const label = ALL_GRADES.find((g) => g.value === grade)?.label || `Grado ${grade}`;
                      const count = groupsPerGrade[grade] || 1;
                      const shift = gradeShifts[grade] || 'mañana';
                      return (
                        <div key={grade} className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4">
                          <div className="flex items-center justify-between gap-3">
                            <div className="min-w-0">
                              <p className="text-body-sm text-[var(--nx-text)] font-medium">{label}</p>
                              <p className="text-caption text-[var(--nx-text-muted)]">
                                {Array.from({ length: Math.min(count, 5) }, (_, i) => generateGroupName(grade, nomenclature, i, separator)).join(', ')}
                                {count > 5 && ` ... (+${count - 5})`}
                              </p>
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                              <button
                                type="button"
                                onClick={() => setGroupsPerGrade((p) => ({ ...p, [grade]: Math.max(1, (p[grade] || 1) - 1) }))}
                                className="grid h-9 w-9 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)]"
                              >
                                –
                              </button>
                              <span className="w-8 text-center text-body font-semibold tabular-nums text-[var(--nx-text)]">{count}</span>
                              <button
                                type="button"
                                onClick={() => setGroupsPerGrade((p) => ({ ...p, [grade]: Math.min(26, (p[grade] || 1) + 1) }))}
                                className="grid h-9 w-9 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)]"
                              >
                                +
                              </button>
                            </div>
                          </div>
                          {/* Jornada por grado */}
                          <div className="mt-3 flex flex-wrap gap-2">
                            {SHIFTS.map((s) => {
                              const Icon = s.icon;
                              const selected = shift === s.value;
                              return (
                                <button
                                  key={s.value}
                                  type="button"
                                  onClick={() => setShiftForGrade(grade, s.value)}
                                  className={`flex items-center gap-1.5 rounded-control border px-3 py-1.5 text-caption transition-all ${
                                    selected
                                      ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)] text-[var(--nx-accent)]'
                                      : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)]'
                                  }`}
                                >
                                  <Icon size={13} />
                                  {s.label}
                                </button>
                              );
                            })}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                  {/* Preview */}
                  <div className="rounded-control border border-[var(--nx-border-accent)] bg-[var(--nx-surface-accent)] p-4">
                    <p className="text-caption font-semibold text-[var(--nx-accent)] mb-2">Vista previa</p>
                    <div className="space-y-1.5">
                      {previewGroups.map(({ grade, names }) => (
                        <p key={grade} className="text-body-sm text-[var(--nx-text)]">
                          <span className="font-medium">{ALL_GRADES.find((g) => g.value === grade)?.label}:</span>{' '}
                          <code className="font-mono text-[var(--nx-text-muted)]">{names.join(', ')}</code>
                          <span className="text-caption text-[var(--nx-text-muted)] ml-2">({SHIFTS.find((s) => s.value === (gradeShifts[grade] || 'mañana'))?.label})</span>
                        </p>
                      ))}
                      {selectedGrades.length > 3 && (
                        <p className="text-caption text-[var(--nx-text-muted)]">... y {selectedGrades.length - 3} grado(s) más</p>
                      )}
                    </div>
                  </div>
                </div>
              )}

              {/* Paso 4: Asignar docentes por grupo */}
              {step === 4 && (
                <div className="space-y-4">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">Asigna al menos un docente a cada grupo</p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Cada grupo necesita ≥1 docente. Es válido dejar docentes sin grupo asignado.</p>
                  </div>
                  {loadingTeachers && (
                    <p className="text-caption text-[var(--nx-text-muted)]">Cargando docentes...</p>
                  )}
                  {!loadingTeachers && teachers.length === 0 && (
                    <div className="rounded-control border border-[var(--nx-border-danger)] bg-[var(--nx-subtle-bg-danger)] p-4 text-body-sm text-[var(--nx-danger)]">
                      No hay docentes registrados en la institución. Crea usuarios con rol Docente antes de continuar.
                    </div>
                  )}
                  <div className="space-y-3">
                    {generatedGroups.map((g) => {
                      const assigned = teacherAssignments[g.name] || [];
                      const available = teachersForShift(g.shift);
                      const gradeLabel = ALL_GRADES.find((gr) => gr.value === g.grade)?.label || g.grade;
                      const shiftLabel = SHIFTS.find((s) => s.value === g.shift)?.label || g.shift;
                      return (
                        <div key={g.name} className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4">
                          <div className="flex items-center justify-between gap-2 mb-2">
                            <div className="min-w-0">
                              <p className="text-body-sm text-[var(--nx-text)] font-medium">
                                {g.name} <span className="text-caption text-[var(--nx-text-muted)] font-normal">· {gradeLabel} · {shiftLabel}</span>
                              </p>
                              <p className="text-caption text-[var(--nx-text-muted)]">
                                {assigned.length} docente(s) asignado(s)
                                {assigned.length === 0 && <span className="text-[var(--nx-danger)] ml-1">— requerido</span>}
                              </p>
                            </div>
                            {assigned.length > 0 && (
                              <span className="grid h-5 w-5 place-items-center rounded-full bg-[var(--nx-icon-bg-success)] text-[var(--nx-success)] shrink-0">
                                <Check size={12} strokeWidth={3} />
                              </span>
                            )}
                          </div>
                          {/* Selección de docentes con búsqueda */}
                          {available.length === 0 ? (
                            <p className="text-caption text-[var(--nx-text-muted)]">No hay docentes para la jornada {shiftLabel}.</p>
                          ) : (
                            <SearchableSelect
                              multiple
                              options={available.map((t) => ({
                                value: t.user_id,
                                label: `${t.first_name} ${t.last_name}`.trim() || t.user_id,
                              }))}
                              value={assigned}
                              onChange={(ids) => setTeacherAssignments((prev) => ({ ...prev, [g.name]: ids || [] }))}
                              placeholder="— Seleccionar docentes —"
                              searchPlaceholder="Buscar docente…"
                              emptyText="Sin docentes disponibles"
                            />
                          )}
                        </div>
                      );
                    })}
                  </div>
                  {/* Resumen de cobertura */}
                  {teachers.length > 0 && (() => {
                    const assignedIds = new Set();
                    Object.values(teacherAssignments).forEach((ids) => ids.forEach((id) => assignedIds.add(id)));
                    const unassignedCount = teachers.length - assignedIds.size;
                    const allGroupsCovered = generatedGroups.every((g) => (teacherAssignments[g.name] || []).length > 0);
                    return (
                      <div className={`rounded-control border p-3 text-caption ${allGroupsCovered ? 'border-[var(--nx-border-success)] bg-[var(--nx-surface-success)] text-[var(--nx-success)]' : 'border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]'}`}>
                        {allGroupsCovered
                          ? `✓ Todos los grupos tienen docente. ${assignedIds.size} docente(s) asignado(s), ${unassignedCount} sin grupo.`
                          : `Falta asignar docentes a uno o más grupos. ${assignedIds.size} docente(s) asignado(s), ${unassignedCount} sin grupo.`
                        }
                      </div>
                    );
                  })()}
                </div>
              )}
            </motion.div>
          </AnimatePresence>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between gap-3 border-t border-[var(--nx-border)] p-6">
          <div className="flex items-center gap-1.5 text-caption text-[var(--nx-text-muted)]">
            <Calendar size={13} />
            <span>Esta configuración se renueva cada 1 de enero</span>
          </div>
          <div className="flex gap-3">
            {onCancel && (
              <Button variant="secondary" onClick={onCancel}>
                Cancelar
              </Button>
            )}
            {step > 1 && (
              <Button variant="secondary" onClick={() => setStep((s) => s - 1)} leftIcon={<ChevronLeft size={16} />}>
                Atrás
              </Button>
            )}
            {isEdit && step < 4 && (
              <Button variant="ghost" onClick={() => setStep((s) => s + 1)} className="text-[var(--nx-text-muted)]">
                Saltar
              </Button>
            )}
            {step < 4 ? (
              <Button onClick={() => canNext && setStep((s) => s + 1)} disabled={!canNext} rightIcon={<ChevronRight size={16} />}>
                Siguiente
              </Button>
            ) : (
              <Button onClick={handleSave} loading={saving} disabled={!canNext} leftIcon={<Check size={16} />}>
                Guardar y finalizar
              </Button>
            )}
          </div>
        </div>
      </motion.div>
    </div>
  );
};

export default OnboardingGroupsModal;
