/**
 * SCR-DEV-01 Dispositivos · Gestión de sensores biométricos
 * Acceso: RECTOR (y COORDINADOR).
 * Filosofía: el rector no es programador. Ve sus lectores de huella,
 * dónde están, si están operativos y puede registrar nuevos o revocarlos.
 * Nada técnico: sin tokens visibles, sin IPs, sin MQTT details.
 */
import { useState, useEffect, useCallback, useMemo } from 'react';
import { useAuth } from '../hooks/useAuth';
import { devicesApi } from '../api/devices';
import { studentsApi } from '../api/students';
import {
  Fingerprint, Plus, Search, MapPin, Wifi, WifiOff,
  Cpu, Trash2, Loader2, Check, X, RefreshCw, ShieldCheck, AlertCircle,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Surface, PageHeader } from '../components/ui/Surface';
import { Card } from '../components/ui/Card';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { Input } from '../components/ui/Input';
import { Drawer } from '../components/ui/Overlay';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton, SkeletonCards } from '../components/ui/Skeleton';
import { SearchableSelect } from '../components/ui/SearchableSelect';
import { humanizeError } from '../utils/messages';

const EASE = [0.22, 1, 0.36, 1];

// ── Helpers ──

const FRESH_WINDOW_MS = 5 * 60 * 1000; // 5 min

const getDeviceStatus = (device) => {
  if (!device.active) return { scheme: 'neutral', label: 'Inactivo', icon: WifiOff };
  if (!device.last_ping) return { scheme: 'warning', label: 'Sin conexión', icon: AlertCircle };
  const ageMs = Date.now() - new Date(device.last_ping + 'Z').getTime();
  if (ageMs <= FRESH_WINDOW_MS) return { scheme: 'success', label: 'Operativo', icon: Wifi };
  if (ageMs <= 30 * 60 * 1000) return { scheme: 'warning', label: 'Conectando', icon: RefreshCw };
  return { scheme: 'danger', label: 'Desconectado', icon: WifiOff };
};

const timeAgo = (iso) => {
  if (!iso) return 'Nunca';
  const ms = Date.now() - new Date(iso + 'Z').getTime();
  const min = Math.floor(ms / 60000);
  if (min < 1) return 'Hace un momento';
  if (min < 60) return `Hace ${min} min`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `Hace ${hr} h`;
  const days = Math.floor(hr / 24);
  return `Hace ${days} d`;
};

// ── Página principal ──

const Devices = () => {
  const { user } = useAuth();
  const [devices, setDevices] = useState([]);
  const [groups, setGroups] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [gradeFilter, setGradeFilter] = useState('');
  const [showRegister, setShowRegister] = useState(false);
  const [revokeTarget, setRevokeTarget] = useState(null);
  const [revoking, setRevoking] = useState(false);
  const [newToken, setNewToken] = useState(null); // {name, token} tras registro

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [devs, grps] = await Promise.all([
        devicesApi.getAll(),
        studentsApi.getGroups().catch(() => []),
      ]);
      setDevices(devs);
      setGroups(grps);
    } catch (err) {
      setError(humanizeError(err, 'No pudimos cargar los dispositivos.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  // Refrescar cada 30s para mantener el estado operativo al día
  useEffect(() => {
    const t = setInterval(() => {
      devicesApi.getAll().then(setDevices).catch(() => {});
    }, 30000);
    return () => clearInterval(t);
  }, []);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return devices.filter((d) => {
      const matchesSearch = !q ||
        (d.device_name || '').toLowerCase().includes(q) ||
        (d.location || '').toLowerCase().includes(q);
      const matchesGrade = !gradeFilter ||
        (d.location || '').toLowerCase().includes(gradeFilter.toLowerCase());
      return matchesSearch && matchesGrade;
    });
  }, [devices, search, gradeFilter]);

  const activeCount = devices.filter((d) => d.active).length;
  const operativeCount = devices.filter((d) => {
    const s = getDeviceStatus(d);
    return s.scheme === 'success';
  }).length;

  const handleRevoke = async () => {
    if (!revokeTarget) return;
    setRevoking(true);
    try {
      await devicesApi.revoke(revokeTarget.device_id);
      setRevokeTarget(null);
      await fetchData();
    } catch (err) {
      setError(humanizeError(err, 'No se pudo revocar el dispositivo.'));
    } finally {
      setRevoking(false);
    }
  };

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Infraestructura"
        title="Sensores biométricos"
        subtitle="Gestiona los lectores de huella de tu institución"
        meta={`${devices.length} dispositivo${devices.length !== 1 ? 's' : ''} · ${operativeCount} operativo${operativeCount !== 1 ? 's' : ''}`}
        actions={
          <Button leftIcon={<Plus size={16} />} onClick={() => setShowRegister(true)}>
            Registrar sensor
          </Button>
        }
      />

      {/* StatCards — resumen visual no técnico */}
      {!loading && !error && (
        <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
          <Card tone="accent" edge>
            <div className="flex items-center gap-3">
              <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]">
                <Fingerprint size={20} />
              </span>
              <div>
                <p className="text-h2 text-[var(--nx-text)] tabular-nums">{devices.length}</p>
                <p className="text-caption text-[var(--nx-text-muted)]">Sensores registrados</p>
              </div>
            </div>
          </Card>
          <Card tone="success" edge>
            <div className="flex items-center gap-3">
              <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-icon-bg-success)] text-[var(--nx-success)]">
                <Wifi size={20} />
              </span>
              <div>
                <p className="text-h2 text-[var(--nx-text)] tabular-nums">{operativeCount}</p>
                <p className="text-caption text-[var(--nx-text-muted)]">Operativos ahora</p>
              </div>
            </div>
          </Card>
          <Card tone={activeCount === devices.length ? 'success' : 'warning'} edge className="col-span-2 md:col-span-1">
            <div className="flex items-center gap-3">
              <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]">
                <ShieldCheck size={20} />
              </span>
              <div>
                <p className="text-h2 text-[var(--nx-text)] tabular-nums">{activeCount}</p>
                <p className="text-caption text-[var(--nx-text-muted)]">Activos</p>
              </div>
            </div>
          </Card>
        </div>
      )}

      {/* Filtros */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <Input
          leftIcon={<Search size={16} />}
          placeholder="Buscar por nombre o ubicación…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="flex-1"
        />
        <div className="sm:w-56">
          <SearchableSelect
            label=""
            options={groups.map((g) => ({ value: g.name || g.group_name, label: g.name || g.group_name }))}
            value={gradeFilter}
            onChange={(v) => setGradeFilter(v || '')}
            placeholder="Todos los grados"
            searchPlaceholder="Buscar grado…"
            clearable
          />
        </div>
      </div>

      {/* Contenido */}
      {error ? (
        <Surface className="p-6">
          <EmptyState
            variant="error"
            title="No pudimos cargar los sensores"
            description={error}
            action={<Button variant="secondary" leftIcon={<RefreshCw size={16} />} onClick={fetchData}>Reintentar</Button>}
          />
        </Surface>
      ) : loading ? (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <SkeletonCards count={3} />
        </div>
      ) : filtered.length === 0 ? (
        <Surface className="p-6">
          <EmptyState
            icon={<Fingerprint size={22} strokeWidth={1.75} className="text-[var(--nx-text-muted)]" />}
            title={devices.length === 0 ? 'No hay sensores registrados' : 'Sin resultados'}
            description={devices.length === 0
              ? 'Registra tu primer lector de huella para empezar a controlar la asistencia biométrica.'
              : 'Ajusta la búsqueda o los filtros para ver tus sensores.'}
            action={devices.length === 0 ? (
              <Button leftIcon={<Plus size={16} />} onClick={() => setShowRegister(true)}>Registrar sensor</Button>
            ) : undefined}
          />
        </Surface>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          <AnimatePresence mode="popLayout">
            {filtered.map((device) => {
              const status = getDeviceStatus(device);
              const StatusIcon = status.icon;
              return (
                <motion.div
                  key={device.device_id}
                  layout
                  initial={{ opacity: 0, y: 8 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, scale: 0.96 }}
                  transition={{ duration: 0.2, ease: EASE }}
                >
                  <DeviceCard
                    device={device}
                    status={status}
                    StatusIcon={StatusIcon}
                    onRevoke={() => setRevokeTarget(device)}
                  />
                </motion.div>
              );
            })}
          </AnimatePresence>
        </div>
      )}

      {/* Drawer: Registrar nuevo sensor */}
      <RegisterDrawer
        open={showRegister}
        onClose={() => setShowRegister(false)}
        onRegistered={(info) => {
          setNewToken(info);
          fetchData();
        }}
      />

      {/* Drawer: Mostrar token tras registro */}
      <TokenDrawer
        info={newToken}
        onClose={() => setNewToken(null)}
      />

      {/* Confirmación: Revocar sensor */}
      <AnimatePresence>
        {revokeTarget && (
          <RevokeConfirm
            device={revokeTarget}
            revoking={revoking}
            onCancel={() => setRevokeTarget(null)}
            onConfirm={handleRevoke}
          />
        )}
      </AnimatePresence>
    </div>
  );
};

// ── Tarjeta de dispositivo ──

const DeviceCard = ({ device, status, StatusIcon, onRevoke }) => {
  return (
    <Card tone={status.scheme} edge className="h-full">
      <div className="flex items-start justify-between gap-3">
        <div className="flex min-w-0 items-start gap-3">
          <span className={`mt-0.5 grid h-11 w-11 shrink-0 place-items-center rounded-control ${
            status.scheme === 'success' ? 'bg-[var(--nx-icon-bg-success)] text-[var(--nx-success)]' :
            status.scheme === 'warning' ? 'bg-[var(--nx-icon-bg-warning)] text-[var(--nx-warning)]' :
            status.scheme === 'danger'  ? 'bg-[var(--nx-icon-bg-danger)] text-[var(--nx-danger)]' :
            'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]'
          }`}>
            <Fingerprint size={20} strokeWidth={1.75} />
          </span>
          <div className="min-w-0">
            <h3 className="text-h3 text-[var(--nx-text)] truncate">{device.device_name || 'Sensor sin nombre'}</h3>
            <p className="mt-0.5 flex items-center gap-1 text-body-sm text-[var(--nx-text-muted)]">
              <MapPin size={13} className="shrink-0" />
              <span className="truncate">{device.location || 'Sin ubicación'}</span>
            </p>
          </div>
        </div>
        <Badge scheme={status.scheme} dot icon={<StatusIcon size={12} />}>
          {status.label}
        </Badge>
      </div>

      <div className="mt-4 flex items-center justify-between border-t border-[var(--nx-border)] pt-3">
        <div className="flex items-center gap-1.5 text-caption text-[var(--nx-text-muted)]">
          <RefreshCw size={12} />
          <span>Última conexión: {timeAgo(device.last_ping)}</span>
        </div>
        {device.active && (
          <Button
            variant="ghost"
            size="sm"
            leftIcon={<Trash2 size={13} />}
            onClick={onRevoke}
            className="text-[var(--nx-text-muted)] hover:text-[var(--nx-danger)]"
          >
            Revocar
          </Button>
        )}
      </div>
    </Card>
  );
};

// ── Drawer: Registrar nuevo sensor ──

const RegisterDrawer = ({ open, onClose, onRegistered }) => {
  const [name, setName] = useState('');
  const [location, setLocation] = useState('');
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState('');

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!name.trim()) return;
    setSaving(true);
    setErr('');
    try {
      const data = await devicesApi.register({ name: name.trim(), location: location.trim() });
      onRegistered(data);
      setName('');
      setLocation('');
      onClose();
    } catch (error) {
      setErr(humanizeError(error, 'No se pudo registrar el sensor.'));
    } finally {
      setSaving(false);
    }
  };

  if (!open) return null;
  return (
    <Drawer
      title="Registrar nuevo sensor"
      context="Configura un lector de huella para tu institución"
      onClose={onClose}
      size="sm"
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" onClick={onClose} leftIcon={<X size={16} />}>Cancelar</Button>
          <Button className="flex-1" loading={saving} onClick={handleSubmit} disabled={!name.trim()} leftIcon={<Check size={16} />}>
            Registrar
          </Button>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-5 p-6">
        {err && (
          <div className="rounded-control bg-[var(--nx-subtle-bg-danger)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">
            {err}
          </div>
        )}
        <Input
          label="Nombre del sensor"
          value={name}
          onChange={(e) => setName(e.target.value)}
          placeholder="Ej. Lector Portería"
          help="Un nombre que identifique dónde está el sensor."
          required
        />
        <Input
          label="Ubicación"
          value={location}
          onChange={(e) => setLocation(e.target.value)}
          placeholder="Ej. Entrada principal, Bloque A"
          help="Dónde está físicamente instalado."
        />
        <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4">
          <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
            Al registrar el sensor, recibirás un <strong className="text-[var(--nx-text)]">código de activación</strong> único.
            Este código se debe configurar en el dispositivo físico para vincularlo con tu institución.
          </p>
        </div>
      </form>
    </Drawer>
  );
};

// ── Drawer: Mostrar token tras registro ──

const TokenDrawer = ({ info, onClose }) => {
  const [copied, setCopied] = useState(false);
  if (!info) return null;

  const token = info.token || '';
  const deviceId = info.device_id || '';

  const handleCopy = () => {
    navigator.clipboard.writeText(token).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  };

  return (
    <Drawer
      title="Sensor registrado"
      context={info.name || 'Nuevo sensor'}
      onClose={onClose}
      size="sm"
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" onClick={onClose}>Cerrar</Button>
          <Button className="flex-1" onClick={handleCopy} leftIcon={copied ? <Check size={16} /> : <Cpu size={16} />}>
            {copied ? 'Copiado' : 'Copiar código'}
          </Button>
        </div>
      }
    >
      <div className="space-y-5 p-6">
        <div className="rounded-control border border-[var(--nx-border-success)] bg-[var(--nx-surface-success)] p-4">
          <div className="flex items-center gap-2 text-[var(--nx-success)]">
            <ShieldCheck size={18} />
            <p className="text-body-sm font-semibold">Sensor vinculado a tu institución</p>
          </div>
        </div>

        <div className="space-y-2">
          <label className="text-label text-[var(--nx-text)]">Código de activación</label>
          <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4">
            <code className="block break-all text-body-sm text-[var(--nx-text)] font-mono">
              {token}
            </code>
          </div>
          <p className="text-caption text-[var(--nx-text-muted)]">
            Guárdalo en un lugar seguro. Lo necesitarás para configurar el dispositivo físico.
            Por seguridad, no volveremos a mostrarlo.
          </p>
        </div>

        <div className="space-y-2">
          <label className="text-label text-[var(--nx-text)]">ID del dispositivo</label>
          <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-3">
            <code className="block break-all text-caption text-[var(--nx-text-muted)] font-mono">
              {deviceId}
            </code>
          </div>
        </div>

        <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4">
          <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
            <strong className="text-[var(--nx-text)]">Siguiente paso:</strong> Configura el dispositivo
            físico con este código de activación y el ID. Una vez conectado, aparecerá automáticamente
            como "Operativo" en esta pantalla.
          </p>
        </div>
      </div>
    </Drawer>
  );
};

// ── Confirmación: Revocar sensor ──

const RevokeConfirm = ({ device, revoking, onCancel, onConfirm }) => (
  <>
    <motion.div
      initial={{ opacity: 0 }}
      animate={{ opacity: 1 }}
      exit={{ opacity: 0 }}
      transition={{ duration: 0.2 }}
      onClick={onCancel}
      className="fixed inset-0 z-40 backdrop-blur-[2px] bg-[color-mix(in_oklch,var(--nx-text)_42%,transparent)]"
    />
    <motion.div
      role="dialog"
      aria-modal="true"
      aria-label="Revocar sensor"
      initial={{ opacity: 0, scale: 0.96 }}
      animate={{ opacity: 1, scale: 1 }}
      exit={{ opacity: 0, scale: 0.96 }}
      transition={{ duration: 0.2, ease: EASE }}
      className="fixed left-1/2 top-1/2 z-50 w-[min(420px,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 rounded-surface border border-[var(--nx-border-danger)] bg-[var(--nx-surface)] p-6 shadow-large"
    >
      <div className="mb-4 flex items-start gap-3">
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]">
          <AlertCircle size={22} />
        </span>
        <div>
          <h3 className="text-h3 text-[var(--nx-text)]">¿Revocar este sensor?</h3>
          <p className="mt-1 text-body-sm text-[var(--nx-text-muted)]">
            <strong className="text-[var(--nx-text)]">{device.device_name}</strong>
            {device.location ? ` · ${device.location}` : ''}
          </p>
        </div>
      </div>
      <p className="mb-6 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">
        El sensor se desactivará inmediatamente y dejará de registrar ingresos biométricos.
        Los estudiantes ya enrolados conservan sus huellas. Puedes volver a activarlo más adelante
        registrando un nuevo sensor.
      </p>
      <div className="flex gap-3">
        <Button variant="secondary" className="flex-1" onClick={onCancel} disabled={revoking}>Cancelar</Button>
        <Button variant="danger" className="flex-1" loading={revoking} onClick={onConfirm} leftIcon={<Trash2 size={16} />}>
          Revocar sensor
        </Button>
      </div>
    </motion.div>
  </>
);

export default Devices;
