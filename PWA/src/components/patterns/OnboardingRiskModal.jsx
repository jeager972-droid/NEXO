/**
 * OnboardingRiskModal. Wizard bloqueante para configuracion inicial del
 * Motor de Analisis de Riesgo Pedagogico v3.0.
 * Solo RECTOR. Se activa cuando risk_config_completed = FALSE.
 *
 * Flujo de pasos:
 *   Paso 1: Que hace el motor (explicacion + niveles de gravedad)
 *   Paso 2: Umbrales (recurrencia + plazo de dias por nivel)
 *   Paso 3: Clasificar eventos por categoria
 *   Paso 4: Revision y guardado
 *
 * Al guardar: crea la primera version de politica via riskApi.createPolicyVersion
 * y marca risk_config_completed = TRUE via schoolApi.completeRiskConfig.
 */
import { useState, useMemo, useEffect, useCallback } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import {
  Shield, AlertTriangle, Clock, TrendingUp, Info, Check, ChevronLeft, ChevronRight,
  Loader2, ChevronDown, ChevronRight as ChevronR, Minus, Plus,
} from 'lucide-react';
import { riskApi } from '../../api/risk';
import { schoolApi } from '../../api/school';
import { Button } from '../ui/Button';
import { Stepper } from '../ui/Stepper';
import { humanizeError } from '../../utils/messages';

const EASE = [0.22, 1, 0.36, 1];
const STEPS = ['Que hace', 'Umbrales', 'Clasificar eventos', 'Revision'];

// Niveles con colores exactos del sistema de design NEXO (mismas variables que metricas del rector)
const LEVELS = [
  {
    value: 'SIN_IMPORTANCIA',
    label: 'Sin importancia',
    colorVar: 'var(--nx-text-muted)',
    bgClass: 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] border-[var(--nx-border)]',
    desc: 'El evento se registra pero no activa alertas.',
  },
  {
    value: 'LEVE',
    label: 'Leve',
    colorVar: 'var(--nx-warning)',
    bgClass: 'bg-[var(--nx-subtle-bg-warning)] text-[var(--nx-warning)] border-[var(--nx-border-warning)]',
    desc: 'Durante 7 dias se detecta la reincidencia antes de generar una alerta a coordinacion.',
  },
  {
    value: 'MODERADA',
    label: 'Moderada',
    colorVar: 'var(--nx-danger)',
    bgClass: 'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)] border-[var(--nx-border-danger)]',
    desc: 'Durante 10 dias se detecta la reincidencia antes de generar una alerta a coordinacion.',
  },
  {
    value: 'ALTA',
    label: 'Alta',
    colorVar: 'var(--nx-danger-strong)',
    bgClass: 'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger-strong)] border-[var(--nx-border-danger)]',
    desc: 'Durante 15 dias se detecta la reincidencia. Requiere revision humana.',
  },
  {
    value: 'MUY_ALTA',
    label: 'Muy Alta',
    colorVar: 'var(--nx-danger-strong)',
    bgClass: 'bg-[var(--nx-surface-danger)] text-[var(--nx-on-solid)] border-[var(--nx-danger-strong)]',
    desc: 'Este evento activa automaticamente una alerta a coordinacion al instante.',
  },
];

const CATEGORIES = {
  asistencia: { label: 'Asistencia', icon: Clock },
  evasion: { label: 'Evasion', icon: AlertTriangle },
  comportamiento: { label: 'Comportamiento', icon: TrendingUp },
};

// Defaults sugeridos por nivel para recurrencia y ventana
const DEFAULT_THRESHOLDS = {
  LEVE: { recurrence: 4, window: 7, minRec: 2, maxRec: 10, minWin: 3, maxWin: 14 },
  MODERADA: { recurrence: 3, window: 10, minRec: 2, maxRec: 8, minWin: 5, maxWin: 21 },
  ALTA: { recurrence: 2, window: 15, minRec: 1, maxRec: 6, minWin: 7, maxWin: 30 },
  MUY_ALTA: { recurrence: 1, window: 1, minRec: 1, maxRec: 1, minWin: 1, maxWin: 1 },
};

// Defaults sugeridos por categoria para el mapeo de eventos
const DEFAULT_LEVEL_BY_CATEGORY = {
  asistencia: 'LEVE',
  evasion: 'MODERADA',
  comportamiento: 'LEVE',
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

  // Umbrales editables (recurrencia + plazo por nivel)
  const [thresholds, setThresholds] = useState(DEFAULT_THRESHOLDS);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [policyRes, typesRes] = await Promise.all([
        riskApi.getPolicy(),
        riskApi.getEventTypes(),
      ]);
      setConfig(policyRes.data?.config ?? { rules: [], mapping: [], combos: [] });
      setEventTypes(typesRes.data ?? []);

      // Si ya hay reglas cargadas, usar sus valores
      const existingRules = policyRes.data?.config?.rules ?? [];
      if (existingRules.length > 0) {
        const newThresholds = { ...DEFAULT_THRESHOLDS };
        for (const rule of existingRules) {
          const lvl = rule.risk_level;
          if (newThresholds[lvl]) {
            newThresholds[lvl] = {
              recurrence: rule.recurrence_count ?? newThresholds[lvl].recurrence,
              window: rule.window_days ?? newThresholds[lvl].window,
              minRec: rule.min_recurrence ?? newThresholds[lvl].minRec,
              maxRec: rule.max_recurrence ?? newThresholds[lvl].maxRec,
              minWin: rule.min_window_days ?? newThresholds[lvl].minWin,
              maxWin: rule.max_window_days ?? newThresholds[lvl].maxWin,
            };
          }
        }
        setThresholds(newThresholds);
      }
    } catch (e) {
      setError(humanizeError(e, 'No se pudieron cargar los datos de riesgo'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadData(); }, [loadData]);

  const eventsByCategory = useMemo(() => {
    return (eventTypes || []).reduce((acc, evt) => {
      if (!acc[evt.category]) acc[evt.category] = [];
      acc[evt.category].push(evt);
      return acc;
    }, {});
  }, [eventTypes]);

  const getLevelForType = (typeCode, category) => {
    if (overrides[typeCode]) return overrides[typeCode];
    const map = (config.mapping || []).find((m) => m.type_code === typeCode);
    if (map?.risk_level) return map.risk_level;
    return DEFAULT_LEVEL_BY_CATEGORY[category] || 'SIN_IMPORTANCIA';
  };

  const handleLevelChange = (typeCode, newLevel) => {
    setOverrides((prev) => ({ ...prev, [typeCode]: newLevel }));
  };

  const adjustThreshold = (level, field, delta) => {
    setThresholds((prev) => {
      const cur = prev[level];
      if (!cur) return prev;
      const min = field === 'recurrence' ? cur.minRec : cur.minWin;
      const max = field === 'recurrence' ? cur.maxRec : cur.maxWin;
      const newVal = Math.max(min, Math.min(max, cur[field] + delta));
      return { ...prev, [level]: { ...cur, [field]: newVal } };
    });
  };

  const canNext = step === 1 ? true
    : step === 2 ? true
    : step === 3 ? true
    : false;

  const handleSave = async () => {
    setSaving(true);
    setError('');
    try {
      const newMapping = (eventTypes || []).map((evt) => ({
        type_code: evt.type_code,
        risk_level: getLevelForType(evt.type_code, evt.category),
      }));

      // Construir rules con los umbrales editados
      const ruleLevels = ['LEVE', 'MODERADA', 'ALTA', 'MUY_ALTA'];
      const newRules = ruleLevels.map((lvl) => {
        const th = thresholds[lvl];
        const existing = (config.rules || []).find((r) => r.risk_level === lvl) || {};
        return {
          risk_level: lvl,
          weight_base: existing.weight_base ?? 1.0,
          half_life_days: th.window,
          activation_threshold: existing.activation_threshold ?? th.recurrence,
          cooldown_days: existing.cooldown_days ?? 5,
          single_occurrence: lvl === 'MUY_ALTA',
          requires_human_review: lvl === 'ALTA',
          recurrence_count: th.recurrence,
          window_days: th.window,
          min_recurrence: th.minRec,
          max_recurrence: th.maxRec,
          min_window_days: th.minWin,
          max_window_days: th.maxWin,
          min_weight: existing.min_weight ?? 0.5,
          max_weight: existing.max_weight ?? 2.0,
          min_half_life: existing.min_half_life ?? 3,
          max_half_life: existing.max_half_life ?? 14,
          min_threshold: existing.min_threshold ?? 2.0,
          max_threshold: existing.max_threshold ?? 8.0,
        };
      });

      const newConfig = {
        rules: newRules,
        mapping: newMapping,
        combos: (config.combos || []).map((c) => ({
          rule_name: c.rule_name,
          condition: typeof c.condition_json === 'string' ? JSON.parse(c.condition_json) : c.condition_json,
          result_level: c.result_level,
          result_reason: c.result_reason,
        })),
      };

      await riskApi.createPolicyVersion(newConfig, 'Configuracion inicial del Motor de Analisis de Riesgo Pedagogico');
      await schoolApi.completeRiskConfig();
      onCompleted?.();
    } catch (err) {
      setError(humanizeError(err, 'No se pudo guardar la configuracion de riesgo'));
    } finally {
      setSaving(false);
    }
  };

  // Contar eventos por nivel para el resumen
  const eventsByLevel = useMemo(() => {
    const counts = {};
    for (const evt of (eventTypes || [])) {
      const lvl = getLevelForType(evt.type_code, evt.category);
      if (!counts[lvl]) counts[lvl] = [];
      counts[lvl].push(evt);
    }
    return counts;
  }, [eventTypes, overrides, config.mapping]);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-[var(--nx-canvas)] p-4">
      <motion.div
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3, ease: EASE }}
        className="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-large"
      >
        {/* Header */}
        <div className="relative overflow-hidden rounded-t-surface shrink-0">
          <div className="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-[var(--nx-accent)] via-[oklch(52%_0.125_245)] to-[var(--nx-accent)]" />
          <div className="flex items-center justify-between p-6 pb-4">
            <div className="flex items-center gap-3">
              <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]">
                <Shield size={22} />
              </span>
              <div>
                <h2 className="text-h2 text-[var(--nx-text)]">Analisis de Riesgo Pedagogico</h2>
                <p className="text-body-sm text-[var(--nx-text-muted)]">Configuracion inicial obligatoria</p>
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

        {/* Content. Scrollable area */}
        <div className="min-h-0 flex-1 overflow-y-auto p-6">
          {loading ? (
            <div className="flex items-center justify-center py-12 text-[var(--nx-text-muted)]">
              <Loader2 size={20} className="animate-spin" />
              <span className="ml-2 text-body-sm">Cargando eventos...</span>
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
                {/* Paso 1: Que hace el motor */}
                {step === 1 && (
                  <div className="space-y-4">
                    <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                      <p className="text-label text-[var(--nx-text)]">Que hace este motor</p>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Lee esto antes de continuar</p>
                    </div>

                    <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4 space-y-2">
                      <p className="text-body-sm text-[var(--nx-text)] leading-relaxed">
                        El motor evalua los eventos de cada estudiante y calcula su nivel de riesgo.
                        Clasificas cada evento en un nivel de gravedad, y el sistema detecta cuando
                        hay reincidencias dentro de un plazo de dias para activar alertas a coordinacion.
                      </p>
                      <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
                        Por ejemplo: si configuras "Llegada tarde" como Leve con 4 reincidencias en 7 dias,
                        el sistema activara una alerta a coordinacion cuando un estudiante llegue tarde
                        4 veces dentro de cualquier ventana de 7 dias.
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
                              style={{ background: lvl.colorVar }}
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
                        El sistema esta inoperativo hasta que completes esta configuracion.
                        Todos los usuarios veran una pantalla de bloqueo mientras tanto.
                      </span>
                    </div>
                  </div>
                )}

                {/* Paso 2: Umbrales */}
                {step === 2 && (
                  <div className="space-y-4">
                    <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                      <p className="text-label text-[var(--nx-text)]">Define los umbrales de activacion</p>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
                        Cuantas reincidencias y en cuantos dias activan una alerta para cada nivel.
                      </p>
                    </div>

                    <div className="rounded-control bg-[var(--nx-subtle-bg-accent)] px-4 py-3 text-body-sm text-[var(--nx-accent)] flex items-start gap-2">
                      <Info size={16} className="shrink-0 mt-0.5" />
                      <span>Se recomienda dejar los valores predeterminados. Puedes ajustarlos segun el contexto de tu institucion.</span>
                    </div>

                    <div className="space-y-3">
                      {LEVELS.filter((l) => l.value !== 'SIN_IMPORTANCIA').map((lvl) => {
                        const th = thresholds[lvl.value];
                        if (!th) return null;
                        const isLocked = lvl.value === 'MUY_ALTA';
                        return (
                          <div
                            key={lvl.value}
                            className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4"
                          >
                            <div className="flex items-center gap-2 mb-3">
                              <div className="h-3 w-3 rounded-full" style={{ background: lvl.colorVar }} />
                              <span className="text-body-sm font-medium text-[var(--nx-text)]">{lvl.label}</span>
                              {isLocked && (
                                <span className="text-caption text-[var(--nx-text-muted)] ml-auto">Activacion automatica</span>
                              )}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                              {/* Recurrencia */}
                              <div>
                                <p className="text-caption text-[var(--nx-text-muted)] mb-1.5">Reincidencias</p>
                                <div className="flex items-center gap-2">
                                  <button
                                    type="button"
                                    disabled={isLocked || th.recurrence <= th.minRec}
                                    onClick={() => adjustThreshold(lvl.value, 'recurrence', -1)}
                                    className="grid h-8 w-8 shrink-0 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                  >
                                    <Minus size={14} />
                                  </button>
                                  <span className="nx-tnum flex-1 text-center text-body font-semibold text-[var(--nx-text)]">
                                    {th.recurrence}
                                  </span>
                                  <button
                                    type="button"
                                    disabled={isLocked || th.recurrence >= th.maxRec}
                                    onClick={() => adjustThreshold(lvl.value, 'recurrence', 1)}
                                    className="grid h-8 w-8 shrink-0 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                  >
                                    <Plus size={14} />
                                  </button>
                                </div>
                              </div>

                              {/* Plazo de dias */}
                              <div>
                                <p className="text-caption text-[var(--nx-text-muted)] mb-1.5">Plazo (dias)</p>
                                <div className="flex items-center gap-2">
                                  <button
                                    type="button"
                                    disabled={isLocked || th.window <= th.minWin}
                                    onClick={() => adjustThreshold(lvl.value, 'window', -1)}
                                    className="grid h-8 w-8 shrink-0 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                  >
                                    <Minus size={14} />
                                  </button>
                                  <span className="nx-tnum flex-1 text-center text-body font-semibold text-[var(--nx-text)]">
                                    {th.window}
                                  </span>
                                  <button
                                    type="button"
                                    disabled={isLocked || th.window >= th.maxWin}
                                    onClick={() => adjustThreshold(lvl.value, 'window', 1)}
                                    className="grid h-8 w-8 shrink-0 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                  >
                                    <Plus size={14} />
                                  </button>
                                </div>
                              </div>
                            </div>

                            <p className="text-caption text-[var(--nx-text-muted)] mt-3 leading-snug">
                              {th.recurrence} evento{th.recurrence !== 1 ? 's' : ''} en {th.window} dia{th.window !== 1 ? 's' : ''} activa alerta a coordinacion
                            </p>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                )}

                {/* Paso 3: Clasificar eventos */}
                {step === 3 && (
                  <div className="space-y-4">
                    <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                      <p className="text-label text-[var(--nx-text)]">Clasifica cada evento por nivel de gravedad</p>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
                        Valores sugeridos asignados. Ajusta segun el contexto de tu institucion.
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
                                  <div key={evt.type_code} className="p-3 pl-4">
                                    <div className="mb-2">
                                      <p className="text-body-sm text-[var(--nx-text)] font-medium">{evt.display_name}</p>
                                      <p className="text-caption text-[var(--nx-text-muted)] leading-snug">{evt.description}</p>
                                    </div>
                                    <div className={`flex flex-wrap items-center gap-1 ${hasOverride ? 'ring-2 ring-[var(--nx-border-accent)] rounded-control px-1.5 py-1' : ''}`}>
                                      {LEVELS.map((lvl) => {
                                        const isActive = currentLevel === lvl.value;
                                        return (
                                          <button
                                            key={lvl.value}
                                            type="button"
                                            onClick={() => handleLevelChange(evt.type_code, lvl.value)}
                                            className={`rounded-control border px-2.5 py-1 text-caption font-medium transition-all ${
                                              isActive
                                                ? lvl.bgClass + ' shadow-low'
                                                : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)]'
                                            }`}
                                            title={lvl.desc}
                                          >
                                            <span className="flex items-center gap-1.5">
                                              <span className="h-2 w-2 rounded-full" style={{ background: lvl.colorVar }} />
                                              {lvl.label}
                                            </span>
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

                {/* Paso 4: Revision */}
                {step === 4 && (
                  <div className="space-y-4">
                    <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                      <p className="text-label text-[var(--nx-text)]">Revision final</p>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
                        Verifica la configuracion antes de activar el motor.
                      </p>
                    </div>

                    {/* Umbrales configurados */}
                    <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4">
                      <p className="text-label text-[var(--nx-text)] mb-3">Umbrales de activacion</p>
                      <div className="space-y-2">
                        {LEVELS.filter((l) => l.value !== 'SIN_IMPORTANCIA').map((lvl) => {
                          const th = thresholds[lvl.value];
                          if (!th) return null;
                          return (
                            <div key={lvl.value} className="flex items-center gap-3">
                              <div className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: lvl.colorVar }} />
                              <span className="text-body-sm text-[var(--nx-text)] w-24 shrink-0">{lvl.label}</span>
                              <span className="text-body-sm text-[var(--nx-text-muted)]">
                                {th.recurrence} en {th.window} dias
                              </span>
                            </div>
                          );
                        })}
                      </div>
                    </div>

                    {/* Eventos clasificados */}
                    <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4">
                      <p className="text-label text-[var(--nx-text)] mb-3">Eventos clasificados</p>
                      <div className="space-y-2">
                        {LEVELS.filter((l) => l.value !== 'SIN_IMPORTANCIA').map((lvl) => {
                          const evts = eventsByLevel[lvl.value] || [];
                          if (evts.length === 0) return null;
                          return (
                            <div key={lvl.value} className="flex items-start gap-3">
                              <div className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: lvl.colorVar }} />
                              <div className="min-w-0 flex-1">
                                <p className="text-body-sm font-medium text-[var(--nx-text)]">{lvl.label}</p>
                                <p className="text-caption text-[var(--nx-text-muted)] leading-snug">
                                  {evts.map((e) => e.display_name).join(', ')}
                                </p>
                              </div>
                            </div>
                          );
                        })}
                        {(eventsByLevel['SIN_IMPORTANCIA'] || []).length > 0 && (
                          <div className="flex items-start gap-3 pt-2 border-t border-[var(--nx-border)]">
                            <div className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: 'var(--nx-text-muted)' }} />
                            <div className="min-w-0 flex-1">
                              <p className="text-body-sm font-medium text-[var(--nx-text-muted)]">Sin importancia</p>
                              <p className="text-caption text-[var(--nx-text-muted)] leading-snug">
                                {(eventsByLevel['SIN_IMPORTANCIA'] || []).map((e) => e.display_name).join(', ')}
                              </p>
                            </div>
                          </div>
                        )}
                      </div>
                    </div>

                    {(config.combos || []).length > 0 && (
                      <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4">
                        <p className="text-label text-[var(--nx-text)] mb-2">Reglas de combinacion automaticas</p>
                        <p className="text-caption text-[var(--nx-text-muted)] mb-3">
                          El sistema escala el riesgo cuando multiples categorias superan un nivel simultaneamente.
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
                        Al guardar se activara el motor de riesgo para tu institucion.
                        Podras ajustar la configuracion despues desde Ajustes.
                      </span>
                    </div>
                  </div>
                )}
              </motion.div>
            </AnimatePresence>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between gap-3 border-t border-[var(--nx-border)] p-6 shrink-0">
          <div className="flex items-center gap-1.5 text-caption text-[var(--nx-text-muted)]">
            <Shield size={13} />
            <span>Sistema bloqueado hasta completar</span>
          </div>
          <div className="flex gap-3">
            {onCancel && step === 1 && (
              <Button variant="secondary" onClick={onCancel}>
                Cancelar
              </Button>
            )}
            {step > 1 && (
              <Button variant="secondary" onClick={() => setStep((s) => s - 1)} leftIcon={<ChevronLeft size={16} />}>
                Atras
              </Button>
            )}
            {step < 4 ? (
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
