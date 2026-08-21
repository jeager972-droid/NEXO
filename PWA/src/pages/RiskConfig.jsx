/**
 * SCR-RISK-01 RiskConfig
 * Configuración del Motor de Análisis de Riesgo Pedagógico v3.0
 * Solo RECTOR/COORDINATOR. Permite mapear eventos a niveles de gravedad
 * y ajustar parámetros dentro de rangos protegidos.
 *
 * Estética canonizada con el design system NEXO (--nx-* tokens, Badge, Card,
 * Button, Dialog) — consistente con OnboardingGroupsModal y OnboardingScheduleModal.
 */
import { useState, useEffect, useCallback } from 'react';
import { AnimatePresence } from 'framer-motion';
import { riskApi } from '../api/risk';
import { useAuth } from '../hooks/useAuth';
import {
  Shield, AlertTriangle, Save, ChevronDown, ChevronRight,
  Info, Clock, TrendingUp, History, XCircle,
} from 'lucide-react';
import { Card } from '../components/ui/Card';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { Skeleton } from '../components/ui/Skeleton';
import { Dialog } from '../components/ui/Overlay';
import { humanizeError } from '../utils/messages';

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
  const [overrides, setOverrides] = useState({});

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

  const handleLevelChange = (typeCode, newLevel) => {
    setOverrides((prev) => ({ ...prev, [typeCode]: newLevel }));
  };

  const hasChanges = Object.keys(overrides).length > 0;

  const handleSave = async () => {
    if (changeReason.trim().length < 10) return;
    setSaving(true);
    setError(null);
    try {
      const newMapping = (eventTypes || []).map((evt) => ({
        type_code: evt.type_code,
        risk_level: overrides[evt.type_code] ?? getLevelForType(evt.type_code),
      }));

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
      // DEBUG (temporal): mostrar el error crudo del backend para diagnosticar.
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

  if (loading) {
    return (
      <div className="p-6 max-w-4xl mx-auto">
        <Skeleton className="h-8 w-64 mb-6" />
        <Skeleton className="h-32 mb-4" />
        <Skeleton className="h-64" />
      </div>
    );
  }

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]">
            <Shield size={22} />
          </span>
          <div>
            <h1 className="text-h2 text-[var(--nx-text)]">Análisis de Riesgo Pedagógico</h1>
            <p className="text-body-sm text-[var(--nx-text-muted)]">
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

      {/* Error */}
      {error && (
        <div className="rounded-control border border-[var(--nx-border-danger)] bg-[var(--nx-subtle-bg-danger)] px-4 py-3" role="alert">
          <div className="flex items-start gap-2">
            <XCircle className="w-4 h-4 shrink-0 mt-0.5 text-[var(--nx-danger)]" />
            <div className="flex-1 min-w-0">
              <p className="text-body-sm text-[var(--nx-danger)] font-medium">No se pudo completar la operación:</p>
              <pre className="mt-1 text-caption text-[var(--nx-danger)] whitespace-pre-wrap break-words font-mono">{error}</pre>
            </div>
          </div>
        </div>
      )}

      {/* Info banner */}
      <div className="rounded-control border border-[var(--nx-border-accent)] bg-[var(--nx-subtle-bg-accent)] p-4">
        <div className="flex gap-3">
          <Info className="w-5 h-5 shrink-0 mt-0.5 text-[var(--nx-accent)]" />
          <div className="text-body-sm text-[var(--nx-text)]">
            <p className="font-semibold mb-1">¿Cómo funciona?</p>
            <p className="text-[var(--nx-text-muted)]">
              Cada evento se clasifica en un nivel de gravedad y se evalúa de forma individual.
              Cuando un evento supera el umbral de reincidencias dentro del plazo de días
              configurado, se genera una alerta a coordinación.
              Los niveles <strong>Sin importancia</strong> no activan alertas.
            </p>
          </div>
        </div>
      </div>

      {/* Niveles de gravedad — referencia */}
      <Card className="p-4">
        <h2 className="text-label text-[var(--nx-text)] mb-3">Niveles de gravedad</h2>
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
          {LEVELS.map((lvl) => (
            <div
              key={lvl.value}
              className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-2.5"
            >
              <div className="flex items-center gap-1.5 mb-1">
                <Badge scheme={lvl.scheme} dot>{lvl.label}</Badge>
              </div>
              <p className="text-caption text-[var(--nx-text-muted)] leading-tight">{lvl.desc}</p>
            </div>
          ))}
        </div>
      </Card>

      {/* Configuración de eventos por categoría */}
      <div className="space-y-3">
        <div className="border-l-2 border-[var(--nx-accent)] pl-3">
          <h2 className="text-label text-[var(--nx-text)]">Configuración de eventos</h2>
          <p className="text-caption text-[var(--nx-text-muted)] mt-0.5">
            Selecciona qué nivel de gravedad tiene cada tipo de evento para tu institución.
          </p>
        </div>

        {Object.entries(eventsByCategory).map(([catKey, events]) => {
          const cat = CATEGORIES[catKey] || { label: catKey, icon: Info };
          const Icon = cat.icon;
          const isExpanded = expandedCategory === catKey || Object.keys(overrides).length > 0;

          return (
            <Card key={catKey} className="overflow-hidden p-0">
              <button
                className="w-full flex items-center justify-between p-4 hover:bg-[var(--nx-surface-subtle)] transition-colors"
                onClick={() => setExpandedCategory(isExpanded ? null : catKey)}
              >
                <div className="flex items-center gap-2">
                  <Icon className="w-4 h-4 text-[var(--nx-text-muted)]" />
                  <span className="text-body-sm font-medium text-[var(--nx-text)]">{cat.label}</span>
                  <span className="text-caption text-[var(--nx-text-muted)]">({events.length} eventos)</span>
                </div>
                {isExpanded ? <ChevronDown className="w-4 h-4 text-[var(--nx-text-muted)]" /> : <ChevronRight className="w-4 h-4 text-[var(--nx-text-muted)]" />}
              </button>

              {isExpanded && (
                <div className="border-t border-[var(--nx-border)] divide-y divide-[var(--nx-border)]">
                  {events.map((evt) => {
                    const currentLevel = getLevelForType(evt.type_code);
                    const hasOverride = overrides[evt.type_code] !== undefined;
                    return (
                      <div key={evt.type_code} className="flex items-center justify-between p-3 pl-6 gap-4">
                        <div className="flex-1 min-w-0">
                          <p className="text-body-sm font-medium text-[var(--nx-text)]">{evt.display_name}</p>
                          <p className="text-caption text-[var(--nx-text-muted)] truncate">{evt.description}</p>
                        </div>
                        <div className={`flex items-center gap-1.5 ${hasOverride ? 'ring-2 ring-[var(--nx-border-accent)] rounded-control px-2 py-1' : ''}`}>
                          {LEVELS.map((lvl) => {
                            const isActive = currentLevel === lvl.value;
                            return (
                              <button
                                key={lvl.value}
                                onClick={() => handleLevelChange(evt.type_code, lvl.value)}
                                className={`rounded-control border px-2.5 py-1 text-caption font-medium transition-all ${
                                  isActive
                                    ? 'border-[var(--nx-accent)] bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] shadow-low'
                                    : 'border-[var(--nx-border)] text-[var(--nx-text-muted)] hover:border-[var(--nx-border-accent)] hover:bg-[var(--nx-surface-subtle)]'
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

      {/* Dialog: Guardar nueva versión */}
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

      {/* Dialog: Historial de versiones */}
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
