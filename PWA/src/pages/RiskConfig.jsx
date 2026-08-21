/**
 * SCR-RISK-01 RiskConfig
 * Configuración del Motor de Análisis de Riesgo Pedagógico v3.0
 * Solo RECTOR/COORDINATOR. Wizard de 4 pasos.
 *
 * Estética canonizada con OnboardingGroupsModal y OnboardingScheduleModal:
 * --nx-* tokens, Stepper, Badge, Button, Dialog, motion transitions.
 * Contenedor tipo tarjeta centrada (no overlay), mismo lenguaje visual.
 *
 * Flujo de pasos:
 *   Paso 1: Información — qué hace el motor + niveles de gravedad
 *   Paso 2: Umbrales — reincidencias y plazo de días por nivel
 *   Paso 3: Clasificar eventos — asignar nivel a cada tipo de evento
 *   Paso 4: Revisión — verificar y guardar con motivo de cambio (auditoría)
 */
import { useState, useMemo, useEffect, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { motion, AnimatePresence } from 'framer-motion';
import {
  Shield, AlertTriangle, Clock, TrendingUp, Info,
  ChevronLeft, ChevronRight, ChevronDown, Minus, Plus,
  History, XCircle, Loader2, ArrowLeft, Save,
} from 'lucide-react';
import { riskApi } from '../api/risk';
import { useAuth } from '../hooks/useAuth';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { Stepper } from '../components/ui/Stepper';
import { Dialog } from '../components/ui/Overlay';
import { humanizeError } from '../utils/messages';

const EASE = [0.22, 1, 0.36, 1];
const STEPS = ['Información', 'Umbrales', 'Eventos', 'Revisión'];

const LEVELS = [
  { value: 'SIN_IMPORTANCIA', label: 'Sin importancia', scheme: 'neutral', desc: 'El evento se registra pero no activa alertas.' },
  { value: 'LEVE', label: 'Leve', scheme: 'warning', desc: 'Se detecta reincidencia en un plazo de días antes de alertar a coordinación.' },
  { value: 'MODERADA', label: 'Moderada', scheme: 'danger', desc: 'Se detecta reincidencia en un plazo de días antes de alertar a coordinación.' },
  { value: 'ALTA', label: 'Alta', scheme: 'danger', desc: 'Se detecta reincidencia. Requiere revisión humana inmediata.' },
  { value: 'MUY_ALTA', label: 'Muy Alta', scheme: 'danger', desc: 'Activa automáticamente una alerta a coordinación al instante.' },
];

const CATEGORIES = {
  asistencia: { label: 'Asistencia', icon: Clock },
  evasion: { label: 'Evasión', icon: AlertTriangle },
  comportamiento: { label: 'Comportamiento', icon: TrendingUp },
  sistema: { label: 'Sistema', icon: Shield },
  administrativo: { label: 'Administrativo', icon: Info },
};

const DEFAULT_LEVEL_BY_CATEGORY = {
  asistencia: 'LEVE',
  evasion: 'MODERADA',
  comportamiento: 'LEVE',
  sistema: 'SIN_IMPORTANCIA',
  administrativo: 'SIN_IMPORTANCIA',
};

// Umbrales por defecto: Leve 10/5d, Moderada 5/5d, Alta 3/5d, MUY_ALTA 1/1d.
const DEFAULT_THRESHOLDS = {
  LEVE: { recurrence: 10, window: 5, minRec: 3, maxRec: 20, minWin: 3, maxWin: 14 },
  MODERADA: { recurrence: 5, window: 5, minRec: 2, maxRec: 15, minWin: 3, maxWin: 14 },
  ALTA: { recurrence: 3, window: 5, minRec: 1, maxRec: 10, minWin: 3, maxWin: 14 },
  MUY_ALTA: { recurrence: 1, window: 1, minRec: 1, maxRec: 1, minWin: 1, maxWin: 1 },
};

export default function RiskConfig() {
  useAuth();
  const navigate = useNavigate();

  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [policy, setPolicy] = useState(null);
  const [config, setConfig] = useState({ rules: [], mapping: [] });
  const [eventTypes, setEventTypes] = useState([]);
  const [overrides, setOverrides] = useState({});
  const [thresholds, setThresholds] = useState(DEFAULT_THRESHOLDS);
  const [expandedCategory, setExpandedCategory] = useState(null);
  const [error, setError] = useState(null);
  const [showSaveDialog, setShowSaveDialog] = useState(false);
  const [changeReason, setChangeReason] = useState('');
  const [showHistory, setShowHistory] = useState(false);
  const [history, setHistory] = useState([]);

  // ── Carga de datos ──────────────────────────────────────────────────
  const loadData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [policyRes, typesRes] = await Promise.all([
        riskApi.getPolicy(),
        riskApi.getEventTypes(),
      ]);
      setPolicy(policyRes.data?.policy ?? null);
      setConfig(policyRes.data?.config ?? { rules: [], mapping: [] });
      setEventTypes(typesRes.data ?? []);

      // Si ya existe una política con rules, cargar sus umbrales
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
      setError(humanizeError(e, 'Error al cargar configuración de riesgo'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadData(); }, [loadData]);

  // ── Derivados ───────────────────────────────────────────────────────
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

  const eventsByLevel = useMemo(() => {
    const counts = {};
    for (const evt of (eventTypes || [])) {
      const lvl = getLevelForType(evt.type_code, evt.category);
      if (!counts[lvl]) counts[lvl] = [];
      counts[lvl].push(evt);
    }
    return counts;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eventTypes, overrides, config.mapping]);

  const hasOverrides = Object.keys(overrides).length > 0;

  // ── Acciones ────────────────────────────────────────────────────────
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

  const handleSave = async () => {
    if (changeReason.trim().length < 10) return;
    setSaving(true);
    setError(null);
    try {
      const newMapping = (eventTypes || []).map((evt) => ({
        type_code: evt.type_code,
        risk_level: getLevelForType(evt.type_code, evt.category),
      }));

      const ruleLevels = ['LEVE', 'MODERADA', 'ALTA', 'MUY_ALTA'];
      const newRules = ruleLevels.map((lvl) => {
        const th = thresholds[lvl];
        const existing = (config.rules || []).find((r) => r.risk_level === lvl) || {};
        return {
          risk_level: lvl,
          weight_base: existing.weight_base ?? { LEVE: 1.0, MODERADA: 3.0, ALTA: 6.0, MUY_ALTA: 10.0 }[lvl],
          half_life_days: existing.half_life_days ?? 5,
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
          max_weight: existing.max_weight ?? 15.0,
          min_half_life: existing.min_half_life ?? 3,
          max_half_life: existing.max_half_life ?? 14,
          min_threshold: existing.min_threshold ?? 1.0,
          max_threshold: existing.max_threshold ?? 20.0,
        };
      });

      await riskApi.createPolicyVersion(
        { rules: newRules, mapping: newMapping },
        changeReason,
      );
      setShowSaveDialog(false);
      setChangeReason('');
      setOverrides({});
      await loadData();
      setStep(1);
    } catch (e) {
      const status = e?.response?.status;
      const payload = e?.response?.data;
      const rawMsg = payload?.message || payload?.error || payload?.detail || e?.message || String(e);
      const debug = payload?.debug ? ` | debug: ${payload.debug}` : '';
      console.error('[RiskConfig] Error al guardar:', { status, payload, e });
      setError(`[HTTP ${status ?? '?'}] ${rawMsg}${debug}`);
    } finally {
      setSaving(false);
    }
  };

  const loadHistory = async () => {
    try {
      const res = await riskApi.getPolicyHistory();
      setHistory(res.data ?? []);
      setShowHistory(true);
    } catch (e) {
      setError(humanizeError(e, 'Error al cargar historial'));
    }
  };

  const canNext = step >= 1 && step <= 3;

  // ── Loading ─────────────────────────────────────────────────────────
  if (loading) {
    return (
      <div className="mx-auto max-w-2xl py-8 px-4">
        <div className="rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-large p-6">
          <div className="flex items-center justify-center py-12 text-[var(--nx-text-muted)]">
            <Loader2 size={20} className="animate-spin" />
            <span className="ml-2 text-body-sm">Cargando configuración...</span>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl py-8 px-4">
      <motion.div
        initial={{ opacity: 0, y: 12 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3, ease: EASE }}
        className="rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-large overflow-hidden"
      >
        {/* ── Header ─────────────────────────────────────────────────── */}
        <div className="relative">
          <div className="absolute inset-x-0 top-0 h-[3px] bg-gradient-to-r from-[var(--nx-accent)] via-[oklch(52%_0.125_245)] to-[var(--nx-accent)]" />
          <div className="p-6 pb-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]">
                  <Shield size={22} />
                </span>
                <div>
                  <h1 className="text-h2 text-[var(--nx-text)]">Análisis de Riesgo Pedagógico</h1>
                  <p className="text-body-sm text-[var(--nx-text-muted)]">
                    {policy
                      ? `Política v${policy.version} · Activa desde ${new Date(policy.activated_at).toLocaleDateString()}`
                      : 'Sin política configurada'}
                  </p>
                </div>
              </div>
              <Button variant="ghost" size="sm" onClick={loadHistory}>
                <History className="w-4 h-4 mr-1" /> Historial
              </Button>
            </div>
          </div>
          <div className="px-6 pb-4">
            <Stepper steps={STEPS} current={step - 1} />
          </div>
        </div>

        {/* ── Error ──────────────────────────────────────────────────── */}
        {error && (
          <div className="mx-6 mb-4 rounded-control border border-[var(--nx-border-danger)] bg-[var(--nx-subtle-bg-danger)] px-4 py-3" role="alert">
            <div className="flex items-start gap-2">
              <XCircle className="w-4 h-4 shrink-0 mt-0.5 text-[var(--nx-danger)]" />
              <div className="flex-1 min-w-0">
                <p className="text-body-sm text-[var(--nx-danger)] font-medium">No se pudo completar la operación:</p>
                <pre className="mt-1 text-caption text-[var(--nx-danger)] whitespace-pre-wrap break-words font-mono">{error}</pre>
              </div>
            </div>
          </div>
        )}

        {/* ── Content (scrollable) ───────────────────────────────────── */}
        <div className="max-h-[55vh] overflow-y-auto p-6">
          <AnimatePresence mode="wait">
            <motion.div
              key={step}
              initial={{ opacity: 0, x: 10 }}
              animate={{ opacity: 1, x: 0 }}
              exit={{ opacity: 0, x: -10 }}
              transition={{ duration: 0.16 }}
            >
              {/* ════════════════════════════════════════════════════════════
                  PASO 1: Información
                  ════════════════════════════════════════════════════════════ */}
              {step === 1 && (
                <div className="space-y-5">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">¿Cómo funciona?</p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">Lee esto antes de continuar</p>
                  </div>

                  <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4 space-y-3">
                    <p className="text-body-sm text-[var(--nx-text)] leading-relaxed">
                      El motor evalúa los eventos de cada estudiante de forma individual.
                      Tú clasificas cada tipo de evento en un nivel de gravedad, y defines
                      cuántas reincidencias dentro de cuántos días activan una alerta
                      a coordinación.
                    </p>
                    <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
                      Por ejemplo: si configuras <strong>Llegada tarde</strong> como Leve con
                      10 reincidencias en 5 días, el sistema activará una alerta cuando un
                      estudiante llegue tarde 10 veces dentro de cualquier ventana de 5 días.
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
                          <Badge scheme={lvl.scheme} dot>{lvl.label}</Badge>
                          <p className="text-caption text-[var(--nx-text-muted)] leading-snug flex-1">{lvl.desc}</p>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              )}

              {/* ════════════════════════════════════════════════════════════
                  PASO 2: Umbrales
                  ════════════════════════════════════════════════════════════ */}
              {step === 2 && (
                <div className="space-y-5">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">Define los umbrales de activación</p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
                      Cuántas reincidencias y en cuántos días activan una alerta para cada nivel.
                    </p>
                  </div>

                  <div className="rounded-control bg-[var(--nx-subtle-bg-accent)] px-4 py-3 text-body-sm text-[var(--nx-accent)] flex items-start gap-2">
                    <Info size={16} className="shrink-0 mt-0.5" />
                    <span>Se recomienda dejar los valores predeterminados. Puedes cambiarlos después.</span>
                  </div>

                  <div className="space-y-3">
                    {LEVELS.filter((l) => l.value !== 'SIN_IMPORTANCIA').map((lvl) => {
                      const th = thresholds[lvl.value];
                      if (!th) return null;
                      const isLocked = lvl.value === 'MUY_ALTA';
                      return (
                        <div key={lvl.value} className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4">
                          <div className="flex items-center gap-2 mb-3">
                            <Badge scheme={lvl.scheme} dot>{lvl.label}</Badge>
                            {isLocked && (
                              <span className="text-caption text-[var(--nx-text-muted)] ml-auto">Activación automática</span>
                            )}
                          </div>

                          <div className="grid grid-cols-2 gap-4">
                            {/* Reincidencias */}
                            <div>
                              <p className="text-caption text-[var(--nx-text-muted)] mb-1.5">Reincidencias</p>
                              <div className="flex items-center gap-2">
                                <button
                                  type="button"
                                  disabled={isLocked || th.recurrence <= th.minRec}
                                  onClick={() => adjustThreshold(lvl.value, 'recurrence', -1)}
                                  className="grid h-9 w-9 shrink-0 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                >
                                  <Minus size={14} />
                                </button>
                                <span className="flex-1 text-center text-body font-semibold tabular-nums text-[var(--nx-text)]">
                                  {th.recurrence}
                                </span>
                                <button
                                  type="button"
                                  disabled={isLocked || th.recurrence >= th.maxRec}
                                  onClick={() => adjustThreshold(lvl.value, 'recurrence', 1)}
                                  className="grid h-9 w-9 shrink-0 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                >
                                  <Plus size={14} />
                                </button>
                              </div>
                            </div>

                            {/* Plazo de días */}
                            <div>
                              <p className="text-caption text-[var(--nx-text-muted)] mb-1.5">Plazo (días)</p>
                              <div className="flex items-center gap-2">
                                <button
                                  type="button"
                                  disabled={isLocked || th.window <= th.minWin}
                                  onClick={() => adjustThreshold(lvl.value, 'window', -1)}
                                  className="grid h-9 w-9 shrink-0 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                >
                                  <Minus size={14} />
                                </button>
                                <span className="flex-1 text-center text-body font-semibold tabular-nums text-[var(--nx-text)]">
                                  {th.window}
                                </span>
                                <button
                                  type="button"
                                  disabled={isLocked || th.window >= th.maxWin}
                                  onClick={() => adjustThreshold(lvl.value, 'window', 1)}
                                  className="grid h-9 w-9 shrink-0 place-items-center rounded-control border border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                                >
                                  <Plus size={14} />
                                </button>
                              </div>
                            </div>
                          </div>

                          <p className="text-caption text-[var(--nx-text-muted)] mt-3 leading-snug">
                            {th.recurrence} evento{th.recurrence !== 1 ? 's' : ''} en {th.window} día{th.window !== 1 ? 's' : ''} activa alerta a coordinación
                          </p>
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}

              {/* ════════════════════════════════════════════════════════════
                  PASO 3: Clasificar eventos
                  ════════════════════════════════════════════════════════════ */}
              {step === 3 && (
                <div className="space-y-4">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">Clasifica cada evento por nivel de gravedad</p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
                      Valores sugeridos asignados. Ajusta según el contexto de tu institución.
                    </p>
                  </div>

                  {Object.entries(eventsByCategory).map(([catKey, events]) => {
                    const cat = CATEGORIES[catKey] || { label: catKey, icon: Info };
                    const Icon = cat.icon;
                    const isExpanded = expandedCategory === catKey || hasOverrides;

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
                          <ChevronDown
                            size={16}
                            className={`text-[var(--nx-text-muted)] transition-transform ${isExpanded ? 'rotate-180' : ''}`}
                          />
                        </button>

                        {isExpanded && (
                          <div className="border-t border-[var(--nx-border)] divide-y divide-[var(--nx-border)]">
                            {events.map((evt) => {
                              const currentLevel = getLevelForType(evt.type_code, evt.category);
                              const hasOverride = overrides[evt.type_code] !== undefined;
                              return (
                                <div key={evt.type_code} className="p-4">
                                  <div className="mb-2">
                                    <p className="text-body-sm text-[var(--nx-text)] font-medium flex items-center gap-1.5">
                                      {hasOverride && <span className="w-1.5 h-1.5 rounded-full bg-[var(--nx-accent)] shrink-0" />}
                                      {evt.display_name}
                                    </p>
                                    <p className="text-caption text-[var(--nx-text-muted)] leading-snug">{evt.description}</p>
                                  </div>
                                  <div className="flex flex-wrap items-center gap-1.5">
                                    {LEVELS.map((lvl) => {
                                      const isActive = currentLevel === lvl.value;
                                      return (
                                        <button
                                          key={lvl.value}
                                          type="button"
                                          onClick={() => handleLevelChange(evt.type_code, lvl.value)}
                                          className={`rounded-control border px-3 py-1.5 text-caption font-medium transition-all ${
                                            isActive
                                              ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] shadow-low'
                                              : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)] hover:bg-[var(--nx-surface-subtle)]'
                                          }`}
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

              {/* ════════════════════════════════════════════════════════════
                  PASO 4: Revisión
                  ════════════════════════════════════════════════════════════ */}
              {step === 4 && (
                <div className="space-y-5">
                  <div className="border-l-2 border-[var(--nx-accent)] pl-3">
                    <p className="text-label text-[var(--nx-text)]">Revisión final</p>
                    <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
                      Verifica la configuración antes de guardar. Se creará una nueva versión de la política.
                    </p>
                  </div>

                  {/* Umbrales configurados */}
                  <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4">
                    <p className="text-label text-[var(--nx-text)] mb-3">Umbrales de activación</p>
                    <div className="space-y-2">
                      {LEVELS.filter((l) => l.value !== 'SIN_IMPORTANCIA').map((lvl) => {
                        const th = thresholds[lvl.value];
                        if (!th) return null;
                        return (
                          <div key={lvl.value} className="flex items-center gap-3">
                            <Badge scheme={lvl.scheme} dot>{lvl.label}</Badge>
                            <span className="text-body-sm text-[var(--nx-text-muted)]">
                              {th.recurrence} en {th.window} día{th.window !== 1 ? 's' : ''}
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  </div>

                  {/* Eventos clasificados */}
                  <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4">
                    <p className="text-label text-[var(--nx-text)] mb-3">Eventos clasificados</p>
                    <div className="space-y-3">
                      {LEVELS.filter((l) => l.value !== 'SIN_IMPORTANCIA').map((lvl) => {
                        const evts = eventsByLevel[lvl.value] || [];
                        if (evts.length === 0) return null;
                        return (
                          <div key={lvl.value} className="flex items-start gap-3">
                            <Badge scheme={lvl.scheme} dot>{lvl.label}</Badge>
                            <p className="text-caption text-[var(--nx-text-muted)] leading-snug flex-1">
                              {evts.map((e) => e.display_name).join(', ')}
                            </p>
                          </div>
                        );
                      })}
                      {(eventsByLevel['SIN_IMPORTANCIA'] || []).length > 0 && (
                        <div className="flex items-start gap-3 pt-2 border-t border-[var(--nx-border)]">
                          <Badge scheme="neutral" dot>Sin importancia</Badge>
                          <p className="text-caption text-[var(--nx-text-muted)] leading-snug flex-1">
                            {(eventsByLevel['SIN_IMPORTANCIA'] || []).map((e) => e.display_name).join(', ')}
                          </p>
                        </div>
                      )}
                    </div>
                  </div>

                  <div className="rounded-control bg-[var(--nx-subtle-bg-accent)] px-4 py-3 text-body-sm text-[var(--nx-accent)] flex items-start gap-2">
                    <Info size={16} className="shrink-0 mt-0.5" />
                    <span>
                      Al guardar se creará una nueva versión de la política. La versión anterior
                      se desactiva pero se conserva para auditoría. Las alertas ya emitidas no cambian.
                    </span>
                  </div>
                </div>
              )}
            </motion.div>
          </AnimatePresence>
        </div>

        {/* ── Footer ─────────────────────────────────────────────────── */}
        <div className="flex items-center justify-between gap-3 border-t border-[var(--nx-border)] p-6">
          <Button variant="ghost" size="sm" onClick={() => navigate('/perfil')}>
            <ArrowLeft size={16} /> Volver
          </Button>
          <div className="flex gap-3">
            {step > 1 && (
              <Button variant="secondary" onClick={() => setStep((s) => s - 1)} leftIcon={<ChevronLeft size={16} />}>
                Atrás
              </Button>
            )}
            {step < 4 ? (
              <Button onClick={() => canNext && setStep((s) => s + 1)} disabled={!canNext} rightIcon={<ChevronRight size={16} />}>
                Siguiente
              </Button>
            ) : (
              <Button onClick={() => setShowSaveDialog(true)} leftIcon={<Save size={16} />}>
                Guardar cambios
              </Button>
            )}
          </div>
        </div>
      </motion.div>

      {/* ── Dialog: Guardar nueva versión ─────────────────────────────── */}
      <AnimatePresence>
        {showSaveDialog && (
          <Dialog
            title="Guardar nueva versión de política"
            description="Los cambios crean una nueva versión de la política. La versión anterior se desactiva pero se conserva para auditoría. Las alertas ya emitidas no cambian."
            onClose={() => setShowSaveDialog(false)}
            footer={
              <>
                <Button variant="secondary" onClick={() => setShowSaveDialog(false)}>Cancelar</Button>
                <Button
                  onClick={handleSave}
                  disabled={saving || changeReason.trim().length < 10}
                  loading={saving}
                >
                  {saving ? null : <Save className="w-4 h-4 mr-1" />}
                  Guardar v{(policy?.version || 0) + 1}
                </Button>
              </>
            }
          >
            <textarea
              className="w-full rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] p-3 text-body-sm resize-none focus:ring-2 focus:ring-[var(--nx-accent)] focus:border-transparent outline-none transition-[border-color,box-shadow]"
              rows={3}
              placeholder="Describe el motivo del cambio (mín. 10 caracteres)..."
              value={changeReason}
              onChange={(e) => setChangeReason(e.target.value)}
            />
          </Dialog>
        )}
      </AnimatePresence>

      {/* ── Dialog: Historial de versiones ────────────────────────────── */}
      <AnimatePresence>
        {showHistory && (
          <Dialog
            title="Historial de versiones"
            size="md"
            onClose={() => setShowHistory(false)}
          >
            {history.length === 0 ? (
              <p className="text-body-sm text-[var(--nx-text-muted)]">No hay versiones registradas.</p>
            ) : (
              <div className="space-y-3 max-h-[50vh] overflow-y-auto">
                {history.map((h) => (
                  <div key={h.policy_id} className="flex items-start gap-3 p-3 border border-[var(--nx-border)] rounded-control bg-[var(--nx-surface)]">
                    <div className={`w-2 h-2 rounded-full mt-1.5 ${h.is_active ? 'bg-[var(--nx-success)]' : 'bg-[var(--nx-text-muted)]'}`} />
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <span className="text-body-sm font-semibold text-[var(--nx-text)]">v{h.version}</span>
                        {h.is_active && <Badge scheme="success">Activa</Badge>}
                      </div>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">{h.change_reason}</p>
                      <p className="text-caption text-[var(--nx-text-muted)] mt-1">
                        {new Date(h.created_at).toLocaleString()}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </Dialog>
        )}
      </AnimatePresence>
    </div>
  );
}
