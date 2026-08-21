/**
 * SCR-RISK-01 RiskConfig
 * Configuración del Motor de Análisis de Riesgo Pedagógico v3.0
 * Solo RECTOR/COORDINATOR. Permite mapear eventos a niveles de gravedad
 * y ajustar parámetros dentro de rangos protegidos.
 */
import { useState, useEffect, useCallback } from 'react';
import { riskApi } from '../api/risk';
import { useAuth } from '../hooks/useAuth';
import {
  Shield, AlertTriangle, Loader2, Save, ChevronDown, ChevronRight,
  Info, Clock, TrendingUp, History, XCircle,
} from 'lucide-react';
import { Card } from '../components/ui/Card';
import { Button } from '../components/ui/Button';
import { Skeleton } from '../components/ui/Skeleton';
import { Dialog } from '../components/ui/Overlay';
import { humanizeError } from '../utils/messages';

const LEVELS = [
  { value: 'SIN_IMPORTANCIA', label: 'Sin importancia', scheme: 'neutral', desc: 'El evento se registra pero no activa alertas.' },
  { value: 'LEVE', label: 'Leve', scheme: 'warning', desc: 'Se detecta reincidencia en un plazo de dias antes de alertar a coordinacion.' },
  { value: 'MODERADA', label: 'Moderada', scheme: 'danger', desc: 'Se detecta reincidencia en un plazo de dias antes de alertar a coordinacion.' },
  { value: 'ALTA', label: 'Alta', scheme: 'danger', desc: 'Se detecta reincidencia. Requiere revision humana inmediata.' },
  { value: 'MUY_ALTA', label: 'Muy Alta', scheme: 'danger', desc: 'Activa automaticamente una alerta a coordinacion al instante.' },
];



const CATEGORIES = {
  asistencia: { label: 'Asistencia', icon: Clock },
  evasion: { label: 'Evasión', icon: AlertTriangle },
  comportamiento: { label: 'Comportamiento', icon: TrendingUp },
  sistema: { label: 'Sistema', icon: Shield },
  administrativo: { label: 'Administrativo', icon: Info },
};

// Helpers de esquema de color del design system (pastel/glass, nunca solido)
function schemeBorder(scheme) {
  const map = {
    neutral: 'border-[var(--nx-border)]',
    accent: 'border-[var(--nx-border-accent)]',
    success: 'border-[var(--nx-border-success)]',
    warning: 'border-[var(--nx-border-warning)]',
    danger: 'border-[var(--nx-border-danger)]',
  };
  return map[scheme] ?? map.neutral;
}

function schemeBg(scheme) {
  const map = {
    neutral: 'bg-[var(--nx-surface-subtle)]',
    accent: 'bg-[var(--nx-surface-accent)]',
    success: 'bg-[var(--nx-surface-success)]',
    warning: 'bg-[var(--nx-surface-warning)]',
    danger: 'bg-[var(--nx-surface-danger)]',
  };
  return map[scheme] ?? map.neutral;
}

function schemeText(scheme) {
  const map = {
    neutral: 'text-[var(--nx-text-muted)]',
    accent: 'text-[var(--nx-accent)]',
    success: 'text-[var(--nx-success)]',
    warning: 'text-[var(--nx-warning)]',
    danger: 'text-[var(--nx-danger)]',
  };
  return map[scheme] ?? map.neutral;
}

function schemeDot(scheme) {
  const map = {
    neutral: 'bg-[var(--nx-text-muted)]',
    accent: 'bg-[var(--nx-accent)]',
    success: 'bg-[var(--nx-success)]',
    warning: 'bg-[var(--nx-warning)]',
    danger: 'bg-[var(--nx-danger)]',
  };
  return map[scheme] ?? map.neutral;
}

export default function RiskConfig() {
  useAuth();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [policy, setPolicy] = useState(null);
  const [config, setConfig] = useState({ rules: [], mapping: [] });
  const [eventTypes, setEventTypes] = useState([]);
  const [showHistory, setShowHistory] = useState(false);
  const [history, setHistory] = useState([]);
  const [showSaveDialog, setShowSaveDialog] = useState(false);
  const [changeReason, setChangeReason] = useState('');
  const [error, setError] = useState(null);
  const [expandedCategory, setExpandedCategory] = useState(null);

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
    } catch (e) {
      setError(humanizeError(e, 'Error al cargar configuración de riesgo'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadData(); }, [loadData]);

  // Agrupar tipos de evento por categoría
  const eventsByCategory = (eventTypes || []).reduce((acc, evt) => {
    if (!acc[evt.category]) acc[evt.category] = [];
    acc[evt.category].push(evt);
    return acc;
  }, {});

  // Encontrar el nivel actual de un tipo de evento
  const getLevelForType = (typeCode) => {
    const map = (config.mapping || []).find((m) => m.type_code === typeCode);
    return map?.risk_level || overrides[typeCode] || 'SIN_IMPORTANCIA';
  };

  // Estado temporal de overrides antes de guardar
  const [overrides, setOverrides] = useState({});
  const handleLevelChange = (typeCode, newLevel) => {
    setOverrides((prev) => ({ ...prev, [typeCode]: newLevel }));
  };

  const hasChanges = Object.keys(overrides).length > 0;

  const handleSave = async () => {
    if (changeReason.trim().length < 10) return;
    setSaving(true);
    setError(null);
    try {
      // Construir nueva configuración con los overrides aplicados
      const newMapping = (eventTypes || []).map((evt) => ({
        type_code: evt.type_code,
        risk_level: overrides[evt.type_code] ?? getLevelForType(evt.type_code),
      }));

      // Mantener reglas y combos existentes
      const newConfig = {
        rules: config.rules || [],
        mapping: newMapping,
      };

      await riskApi.createPolicyVersion(newConfig, changeReason);
      setShowSaveDialog(false);
      setChangeReason('');
      setOverrides({});
      await loadData();
    } catch (e) {
      setError(humanizeError(e, 'Error al guardar la configuración'));
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

  if (loading) {
    return (
      <div className="p-6 max-w-5xl mx-auto">
        <Skeleton className="h-8 w-64 mb-6" />
        <Skeleton className="h-32 mb-4" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Shield className="w-7 h-7 text-blue-600" />
          <div>
            <h1 className="text-xl font-bold text-neutral-900">Análisis de Riesgo Pedagógico</h1>
            <p className="text-sm text-neutral-500">
              {policy ? `Política v${policy.version} · Activa desde ${new Date(policy.activated_at).toLocaleDateString()}` : 'Sin política configurada'}
            </p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="ghost" size="sm" onClick={loadHistory}>
            <History className="w-4 h-4 mr-1" /> Historial
          </Button>
          {hasChanges && (
            <Button size="sm" onClick={() => setShowSaveDialog(true)}>
              <Save className="w-4 h-4 mr-1" /> Guardar cambios
            </Button>
          )}
        </div>
      </div>

      {error && (
        <div className="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700 flex items-center gap-2">
          <XCircle className="w-4 h-4 shrink-0" /> {error}
        </div>
      )}

      {/* Info banner */}
      <Card className="p-4 bg-blue-50 border-blue-200">
        <div className="flex gap-3">
          <Info className="w-5 h-5 text-blue-600 shrink-0 mt-0.5" />
          <div className="text-sm text-blue-900">
            <p className="font-semibold mb-1">¿Cómo funciona?</p>
            <p>
              Cada evento se clasifica en un nivel de gravedad y se evalua de forma individual.
              Cuando un evento supera el umbral de reincidencias dentro del plazo de dias
              configurado, se genera una alerta a coordinacion.
              Los niveles <strong>Sin importancia</strong> no activan alertas.
            </p>
          </div>
        </div>
      </Card>

      {/* Niveles de gravedad — referencia */}
      <Card className="p-4">
        <h2 className="text-sm font-semibold text-[var(--nx-text)] mb-3">Niveles de gravedad</h2>
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
          {LEVELS.map((lvl) => (
            <div
              key={lvl.value}
              className={`rounded-control border p-2.5 ${schemeBorder(lvl.scheme)} ${schemeBg(lvl.scheme)}`}
            >
              <div className="flex items-center gap-1.5 mb-1">
                <div className={`w-2.5 h-2.5 rounded-full shrink-0 ${schemeDot(lvl.scheme)}`} />
                <span className="font-semibold text-xs text-[var(--nx-text)] truncate">{lvl.label}</span>
              </div>
              <p className="text-[11px] text-[var(--nx-text-muted)] leading-tight">{lvl.desc}</p>
            </div>
          ))}
        </div>
      </Card>

      {/* Configuración de eventos por categoría */}
      <div className="space-y-3">
        <h2 className="text-sm font-semibold text-neutral-700">Configuración de eventos</h2>
        <p className="text-xs text-neutral-500">
          Selecciona qué nivel de gravedad tiene cada tipo de evento para tu institución.
        </p>

        {Object.entries(eventsByCategory).map(([catKey, events]) => {
          const cat = CATEGORIES[catKey] || { label: catKey, icon: Info };
          const Icon = cat.icon;
          const isExpanded = expandedCategory === catKey || Object.keys(overrides).length > 0;

          return (
            <Card key={catKey} className="overflow-hidden">
              <button
                className="w-full flex items-center justify-between p-4 hover:bg-neutral-50 transition-colors"
                onClick={() => setExpandedCategory(isExpanded ? null : catKey)}
              >
                <div className="flex items-center gap-2">
                  <Icon className="w-4 h-4 text-neutral-600" />
                  <span className="font-semibold text-sm">{cat.label}</span>
                  <span className="text-xs text-neutral-400">({events.length} eventos)</span>
                </div>
                {isExpanded ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
              </button>

              {isExpanded && (
                <div className="border-t border-neutral-100 divide-y divide-neutral-50">
                  {events.map((evt) => {
                    const currentLevel = getLevelForType(evt.type_code);
                    const hasOverride = overrides[evt.type_code] !== undefined;
                    return (
                      <div key={evt.type_code} className="flex items-center justify-between p-3 pl-6 gap-4">
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium text-neutral-800">{evt.display_name}</p>
                          <p className="text-xs text-neutral-500 truncate">{evt.description}</p>
                        </div>
                        <div className={`flex items-center gap-2 ${hasOverride ? 'ring-2 ring-blue-200 rounded-md px-2 py-1' : ''}`}>
                          {LEVELS.map((lvl) => {
                            const isActive = currentLevel === lvl.value;
                            return (
                              <button
                                key={lvl.value}
                                onClick={() => handleLevelChange(evt.type_code, lvl.value)}
                                className={`px-2.5 py-1 rounded-full border text-xs font-semibold transition-all ${
                                  isActive
                                    ? `${schemeBg(lvl.scheme)} ${schemeBorder(lvl.scheme)} ${schemeText(lvl.scheme)} shadow-low`
                                    : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)]'
                                }`}
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
            </Card>
          );
        })}
      </div>

      {/* Combos desactivados: solo deteccion individual de eventos */}

      {/* Dialog: Guardar nueva versión */}
      <Dialog open={showSaveDialog} onOpenChange={setShowSaveDialog}>
        <div className="p-6 max-w-md">
          <h3 className="text-lg font-semibold mb-2">Guardar nueva versión de política</h3>
          <p className="text-sm text-neutral-600 mb-4">
            Los cambios crean una nueva versión de la política. La versión anterior se
            desactiva pero se conserva para auditoría. Las alertas ya emitidas no cambian.
          </p>
          <textarea
            className="w-full border border-neutral-300 rounded-lg p-3 text-sm resize-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
            rows={3}
            placeholder="Describe el motivo del cambio (mín. 10 caracteres)..."
            value={changeReason}
            onChange={(e) => setChangeReason(e.target.value)}
          />
          <div className="flex justify-end gap-2 mt-4">
            <Button variant="ghost" onClick={() => setShowSaveDialog(false)}>Cancelar</Button>
            <Button
              onClick={handleSave}
              disabled={saving || changeReason.trim().length < 10}
            >
              {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4 mr-1" />}
              Guardar v{(policy?.version || 0) + 1}
            </Button>
          </div>
        </div>
      </Dialog>

      {/* Dialog: Historial de versiones */}
      <Dialog open={showHistory} onOpenChange={setShowHistory}>
        <div className="p-6 max-w-lg max-h-[70vh] overflow-y-auto">
          <h3 className="text-lg font-semibold mb-4">Historial de versiones</h3>
          {history.length === 0 ? (
            <p className="text-sm text-neutral-500">No hay versiones registradas.</p>
          ) : (
            <div className="space-y-3">
              {history.map((h) => (
                <div key={h.policy_id} className="flex items-start gap-3 p-3 border border-neutral-200 rounded-lg">
                  <div className={`w-2 h-2 rounded-full mt-1.5 ${h.is_active ? 'bg-green-500' : 'bg-neutral-300'}`} />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <span className="font-semibold text-sm">v{h.version}</span>
                      {h.is_active && <span className="text-xs text-green-600 font-medium">Activa</span>}
                    </div>
                    <p className="text-xs text-neutral-600 mt-0.5">{h.change_reason}</p>
                    <p className="text-xs text-neutral-400 mt-1">
                      {new Date(h.created_at).toLocaleString()}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </Dialog>
    </div>
  );
}
