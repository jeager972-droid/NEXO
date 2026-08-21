/**
 * OnboardingRiskModal — Wizard bloqueante para configuración inicial del
 * Motor de Análisis de Riesgo Pedagógico v3.0.
 * Solo RECTOR. Se activa cuando risk_config_completed = FALSE.
 *
 * Flujo de pasos:
 *   Paso 1: Qué hace el motor (explicación + niveles de gravedad)
 *   Paso 2: Clasificar eventos por categoría (asistencia, evasión, etc.)
 *   Paso 3: Revisión y guardado
 *
 * Al guardar: crea la primera versión de política vía riskApi.createPolicyVersion
 * y marca risk_config_completed = TRUE vía schoolApi.completeRiskConfig.
 */
import { useState, useMemo, useEffect, useCallback } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  Shield, AlertTriangle, Clock, TrendingUp, Info, Check, ChevronLeft, ChevronRight,
  Loader2, Save, ChevronDown, ChevronRight as ChevronR,
} from 'lucide-react';
import { riskApi } from '../../api/risk';
import { schoolApi } from '../../api/school';
import { Button } from '../ui/Button';
import { Stepper } from '../ui/Stepper';
import { humanizeError } from '../../utils/messages';

const EASE = [0.22, 1, 0.36, 1];
const STEPS = ['Qué hace', 'Clasificar eventos', 'Revisión'];

const LEVELS = [
  { value: 'SIN_IMPORTANCIA', label: 'Sin importancia', color: 'var(--nx-text-muted)', desc: 'No alimenta el cálculo. El evento se registra pero no suma puntos.' },
  { value: 'LEVE', label: 'Leve', color: 'var(--nx-warning)', desc: 'Peso 1.0, vida media 7 días lectivos. Detecta patrones emergentes.' },
  { value: 'MODERADA', label: 'Moderada', color: 'var(--nx-danger)', desc: 'Peso 3.0, vida media 10 días lectivos. Sensible a concentración temporal.' },
  { value: 'ALTA', label: 'Alta', color: 'var(--nx-danger-strong)', desc: 'Peso 6.0, vida media 15 días lectivos. Requiere revisión humana.' },
  { value: 'MUY_ALTA', label: 'Muy Alta', color: 'oklch(30% 0.18 25)', desc: 'Peso 10.0, no decae. 1 ocurrencia dispara alerta inmediata.' },
];

const LEVEL_VALUES = LEVELS.map((l) => l.value);

const CATEGORIES = {
  asistencia: { label: 'Asistencia', icon: Clock },
  evasion: { label: 'Evasión', icon: AlertTriangle },
  comportamiento: { label: 'Comportamiento', icon: TrendingUp },
  sistema: { label: 'Sistema', icon: Shield },
  administrativo: { label: 'Administrativo', icon: Info },
};

// Niveles sugeridos por defecto según la categoría
const DEFAULT_LEVEL_BY_CATEGORY = {
  asistencia: 'LEVE',
  evasion: 'MODERADA',
  comportamiento: 'LEVE',
  sistema: 'SIN_IMPORTANCIA',
  administrativo: 'SIN_IMPORTANCIA',
};

export const OnboardingRiskModal = ({ onCompleted, onCancel }) => {
  const [step, setStep] = useState(1);
  const [eventTypes, setEventTypes] = useState([]);
  const [config, setConfig] = useState({ rules: [], mapping: [], combos: [] });
  const [overrides, setOverrides] = useState({});
  const [expandedCategory, setExpandedCategory] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  // Cargar tipos de evento y política existente al montar
  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [policyRes, typesRes] = await Promise.all([
        riskApi.getPolicy(),
        riskApi.getEventTypes(),
      ]);
      setConfig(policyRes.data?.config ?? { rules: [], mapping: [], combos: [] });
      setEventTypes(typesRes.data ?? []);
    } catch (e) {
      setError(humanizeError(e, 'No se pudieron cargar los datos de riesgo'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadData(); }, [loadData]);

  // Agrupar tipos de evento por categoría
  const eventsByCategory = useMemo(() => {
    return (eventTypes || []).reduce((acc, evt) => {
      if (!acc[evt.category]) acc[evt.category] = [];
      acc[evt.category].push(evt);
      return acc;
    }, {});
  }, [eventTypes]);

  // Nivel actual de un tipo de evento (override > mapping existente > default por categoría)
  const getLevelForType = (typeCode, category) => {
    if (overrides[typeCode]) return overrides[typeCode];
    const map = (config.mapping || []).find((m) => m.type_code === typeCode);
    if (map?.risk_level) return map.risk_level;
    return DEFAULT_LEVEL_BY_CATEGORY[category] || 'SIN_IMPORTANCIA';
  };

  const handleLevelChange = (typeCode, newLevel) => {
    setOverrides((prev) => ({ ...prev, [typeCode]: newLevel }));
  };

  // Validación por paso
  const canNext = step === 1 ? true
    : step === 2 ? true // los defaults ya están asignados
    : false;

  const handleSave = async () => {
    setSaving(true);
    setError('');
    try {
      // Construir mapping final con overrides + defaults
      const newMapping = (eventTypes || []).map((evt) => ({
        type_code: evt.type_code,
        risk_level: getLevelForType(evt.type_code, evt.category),
      }));

      const newConfig = {
        rules: config.rules || [],
        mapping: newMapping,
        combos: (config.combos || []).map((c) => ({
          rule_name: c.rule_name,
          condition: typeof c.condition_json === 'string' ? JSON.parse(c.condition_json) : c.condition_json,
          result_level: c.result_level,
          result_reason: c.result_reason,
        })),
      };

      // 1. Crear la primera versión de política
      await riskApi.createPolicyVersion(newConfig, 'Configuración inicial del Motor de Análisis de Riesgo Pedagógico');

      // 2. Marcar risk_config_completed = TRUE
      await schoolApi.completeRiskConfig();

      onCompleted?.();
    } catch (err) {
      setError(humanizeError(err, 'No se pudo guardar la configuración de riesgo'));
    } finally {
      setSaving(false);
    }
  };

  // Contar eventos configurados por nivel para el resumen
  const summaryByLevel = useMemo(() => {
    const counts = {};
    for (const evt of (eventTypes || [])) {
      const lvl = getLevelForType(evt.type_code, evt.category);
      counts[lvl] = (counts[lvl] || 0) + 1;
    }
    return counts;
  }, [eventTypes, overrides, config.mapping]);

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
                <Shield size={22} />
              </span>
              <div>
                <h2 className="text-h2 text-[var(--nx-text)]">Análisis de Riesgo Pedagógico</h2>
                <p className="text-body-sm text-[var(--nx-text-muted)]">Configuración inicial obligatoria</p>
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
          {loading ? (
            <div className="flex items-center justify-center py-12 text-[var(--nx-text-muted)]">
              <Loader2 size={20} className="animate-spin" />
              <span className="ml-2 text-body-sm">Cargando eventos…</span>
            </div>
          ) : (
            <AnimatePresence mode="wait">
              <motion.div
                key={step}
                initial={{ opacity: 0, x: 10 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: -10 }}
                transition={{ duration: 0.16 }}
              >
                {/* Paso 1: Qué hace el motor */}
                {step === 1 && (
                  <div className="space-y-5">
                    <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                      <p className="text-label text-[var(--nx-text)]">¿Qué hace este motor?</p>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Lee esta explicación antes de continuar</p>
                    </div>

                    <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4 space-y-3">
                      <p className="text-body-sm text-[var(--nx-text)] leading-relaxed">
                        El <strong>Motor de Análisis de Riesgo Pedagógico</strong> evalúa continuamente
                        los eventos de cada estudiante (asistencia, evasión, comportamiento, etc.) y
                        calcula un nivel de riesgo individual usando <strong>decaimiento exponencial</strong>
                        {' '}—los eventos viejos pesan menos gradualmente— y <strong>detección de patrones</strong>
                        {' '}—concentración temporal de eventos.
                      </p>
                      <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
                        Tu tarea como rector es <strong>clasificar cada tipo de evento</strong> en un nivel
                        de gravedad. Esta clasificación define cómo el sistema calcula el riesgo para
                        todos los estudiantes de tu institución. Sin esta configuración, el sistema no
                        puede operar.
                      </p>
                    </div>

                    <div>
                      <p className="text-label text-[var(--nx-text)] mb-3">Niveles de gravedad</p>
                      <div className="space-y-2">
                        {LEVELS.map((lvl) => (
                          <div
                            key={lvl.value}
                            className="flex items-start gap-3 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-3"
                          >
                            <div
                              className="mt-0.5 h-3 w-3 shrink-0 rounded-full"
                              style={{ background: lvl.color }}
                            />
                            <div className="min-w-0 flex-1">
                              <p className="text-body-sm font-medium text-[var(--nx-text)]">{lvl.label}</p>
                              <p className="text-caption text-[var(--nx-text-muted)] leading-snug">{lvl.desc}</p>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>

                    <div className="rounded-control bg-[var(--nx-subtle-bg-warning)] px-4 py-3 text-body-sm text-[var(--nx-warning)] flex items-start gap-2">
                      <AlertTriangle size={16} className="shrink-0 mt-0.5" />
                      <span>
                        El sistema está inoperativo hasta que completes esta configuración.
                        Todos los usuarios verán una pantalla de bloqueo mientras tanto.
                      </span>
                    </div>
                  </div>
                )}

                {/* Paso 2: Clasificar eventos */}
                {step === 2 && (
                  <div className="space-y-4">
                    <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                      <p className="text-label text-[var(--nx-text)]">Clasifica cada evento por nivel de gravedad</p>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
                        Hemos asignado valores sugeridos. Ajusta según el contexto de tu institución.
                      </p>
                    </div>

                    {Object.entries(eventsByCategory).map(([catKey, events]) => {
                      const cat = CATEGORIES[catKey] || { label: catKey, icon: Info };
                      const Icon = cat.icon;
                      const isExpanded = expandedCategory === catKey || Object.keys(overrides).length > 0;

                      return (
                        <div
                          key={catKey}
                          className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] overflow-hidden"
                        >
                          <button
                            type="button"
                            className="w-full flex items-center justify-between p-4 hover:bg-[var(--nx-surface-subtle)] transition-colors"
                            onClick={() => setExpandedCategory(isExpanded ? null : catKey)}
                          >
                            <div className="flex items-center gap-2">
                              <Icon size={16} className="text-[var(--nx-text-muted)]" />
                              <span className="text-body-sm font-medium text-[var(--nx-text)]">{cat.label}</span>
                              <span className="text-caption text-[var(--nx-text-muted)]">({events.length})</span>
                            </div>
                            {isExpanded ? <ChevronDown size={16} className="text-[var(--nx-text-muted)]" /> : <ChevronR size={16} className="text-[var(--nx-text-muted)]" />}
                          </button>

                          {isExpanded && (
                            <div className="border-t border-[var(--nx-border)] divide-y divide-[var(--nx-border)]">
                              {events.map((evt) => {
                                const currentLevel = getLevelForType(evt.type_code, evt.category);
                                const hasOverride = overrides[evt.type_code] !== undefined;
                                return (
                                  <div key={evt.type_code} className="flex items-center justify-between p-3 pl-4 gap-3">
                                    <div className="min-w-0 flex-1">
                                      <p className="text-body-sm text-[var(--nx-text)] font-medium">{evt.display_name}</p>
                                      <p className="text-caption text-[var(--nx-text-muted)] truncate">{evt.description}</p>
                                    </div>
                                    <div className={`flex items-center gap-1 shrink-0 ${hasOverride ? 'ring-2 ring-[var(--nx-border-accent)] rounded-control px-1.5 py-1' : ''}`}>
                                      {LEVELS.map((lvl) => {
                                        const isActive = currentLevel === lvl.value;
                                        return (
                                          <button
                                            key={lvl.value}
                                            type="button"
                                            onClick={() => handleLevelChange(evt.type_code, lvl.value)}
                                            className={`rounded-control px-2 py-1 text-caption font-medium transition-all ${
                                              isActive
                                                ? 'text-[var(--nx-on-solid)] shadow-low'
                                                : 'text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)]'
                                            }`}
                                            style={isActive ? { background: lvl.color } : {}}
                                            title={lvl.desc}
                                          >
                                            {lvl.label}
                                          </button>
                                        );
                                      })}
                                    </div>
                                  </div>
                                );
                              })}
                            </div>
                          )}
                        </div>
                      );
                    })}
                  </div>
                )}

                {/* Paso 3: Revisión */}
                {step === 3 && (
                  <div className="space-y-5">
                    <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                      <p className="text-label text-[var(--nx-text)]">Revisa tu configuración</p>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
                        Esta será la primera versión de la política de riesgo. Podrás ajustarla después desde Ajustes.
                      </p>
                    </div>

                    <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4 space-y-3">
                      <p className="text-label text-[var(--nx-text)]">Distribución de eventos por nivel</p>
                      <div className="space-y-2">
                        {LEVELS.map((lvl) => {
                          const count = summaryByLevel[lvl.value] || 0;
                          const total = (eventTypes || []).length;
                          const pct = total > 0 ? Math.round((count / total) * 100) : 0;
                          return (
                            <div key={lvl.value} className="flex items-center gap-3">
                              <div className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: lvl.color }} />
                              <span className="text-body-sm text-[var(--nx-text)] w-28 shrink-0">{lvl.label}</span>
                              <div className="flex-1 h-2 rounded-full bg-[var(--nx-surface-subtle)] overflow-hidden">
                                <div
                                  className="h-full rounded-full transition-all"
                                  style={{ width: `${pct}%`, background: lvl.color }}
                                />
                              </div>
                              <span className="text-caption text-[var(--nx-text-muted)] w-16 text-right shrink-0">{count} eventos</span>
                            </div>
                          );
                        })}
                      </div>
                    </div>

                    {(config.combos || []).length > 0 && (
                      <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4">
                        <p className="text-label text-[var(--nx-text)] mb-2">Reglas de combinación automáticas</p>
                        <p className="text-caption text-[var(--nx-text-muted)] mb-3">
                          El sistema escala el riesgo cuando múltiples categorías superan un nivel simultáneamente.
                        </p>
                        <div className="space-y-2">
                          {(config.combos || []).map((combo, i) => (
                            <div key={i} className="flex items-start gap-2">
                              <AlertTriangle size={14} className="text-[var(--nx-warning)] shrink-0 mt-0.5" />
                              <div className="min-w-0">
                                <p className="text-body-sm text-[var(--nx-text)] font-medium">{combo.rule_name}</p>
                                <p className="text-caption text-[var(--nx-text-muted)]">{combo.result_reason}</p>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    )}

                    <div className="rounded-control bg-[var(--nx-subtle-bg-accent)] px-4 py-3 text-body-sm text-[var(--nx-accent)] flex items-start gap-2">
                      <Info size={16} className="shrink-0 mt-0.5" />
                      <span>
                        Al guardar, se creará la <strong>versión 1</strong> de la política de riesgo.
                        Los cambios futuros generarán nuevas versiones conservando el historial.
                      </span>
                    </div>
                  </div>
                )}
              </motion.div>
            </AnimatePresence>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between gap-3 border-t border-[var(--nx-border)] p-6">
          <div className="flex items-center gap-1.5 text-caption text-[var(--nx-text-muted)]">
            <Shield size={13} />
            <span>El sistema está bloqueado hasta completar esto</span>
          </div>
          <div className="flex gap-3">
            {onCancel && step === 1 && (
              <Button variant="secondary" onClick={onCancel}>
                Cancelar
              </Button>
            )}
            {step > 1 && (
              <Button variant="secondary" onClick={() => setStep((s) => s - 1)} leftIcon={<ChevronLeft size={16} />}>
                Atrás
              </Button>
            )}
            {step < 3 ? (
              <Button onClick={() => canNext && setStep((s) => s + 1)} disabled={!canNext || loading} rightIcon={<ChevronRight size={16} />}>
                Siguiente
              </Button>
            ) : (
              <Button onClick={handleSave} loading={saving} disabled={loading} leftIcon={<Check size={16} />}>
                Guardar y activar
              </Button>
            )}
          </div>
        </div>
      </motion.div>
    </div>
  );
};

export default OnboardingRiskModal;
