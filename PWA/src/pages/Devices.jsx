/**
 * SCR-DEV-01 Dispositivos · Gestión de sensores biométricos
 * Acceso: RECTOR (y COORDINADOR).
 * Filosofía: el rector no es programador. Ve sus lectores de huella,
 * dónde están, si están configurados y puede registrar nuevos o revocarlos.
 *
 * Sensores: 1 por grupo + sensor de secretaria + sensor de coordinador.
 * "Configurado" reemplaza "Activo" en la UI.
 * Revocación: password + countdown 1h + coordinador puede cancelar.
 * Eliminación: hard delete (no soft delete).
 */
import { useState, useEffect, useCallback, useMemo } from 'react';
import { devicesApi } from '../api/devices';
import { studentsApi } from '../api/students';
import {
  Fingerprint, Plus, Search, MapPin, Wifi, WifiOff,
  Cpu, Trash2, Check, X, RefreshCw, ShieldCheck, AlertCircle,
  Clock, Settings2, ArrowLeftRight, KeyRound,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { Surface, PageHeader } from '../components/ui/Surface';
import { Card } from '../components/ui/Card';
import { Button } from '../components/ui/Button';
import { Badge } from '../components/ui/Badge';
import { Input } from '../components/ui/Input';
import { Drawer } from '../components/ui/Overlay';
import { EmptyState } from '../components/ui/EmptyState';
import { Skeleton } from '../components/ui/Skeleton';
import { SearchableSelect } from '../components/ui/SearchableSelect';
import { humanizeError } from '../utils/messages';
import { ROLES, getRoleDisplay } from '../config/roles';
import { usersApi } from '../api/users';
import { useAuth } from '../hooks/useAuth';
import { formatGroupName } from '../utils/groupFormat';

const EASE = [0.22, 1, 0.36, 1];
const FRESH_WINDOW_MS = 5 * 60 * 1000;

// ── Helpers ──

const getDeviceStatus = (device, pendingRevocationIds) => {
  if (pendingRevocationIds?.has(device.device_id)) {
    return { scheme: 'warning', label: 'En eliminación', icon: Clock };
  }
  if (device.last_ping) {
    const ageMs = Date.now() - new Date(device.last_ping + 'Z').getTime();
    if (ageMs <= FRESH_WINDOW_MS) return { scheme: 'success', label: 'Operativo', icon: Wifi };
    if (ageMs <= 30 * 60 * 1000) return { scheme: 'warning', label: 'Conectando', icon: RefreshCw };
    return { scheme: 'danger', label: 'Desconectado', icon: WifiOff };
  }
  if (!device.configured) return { scheme: 'neutral', label: 'No configurado', icon: Settings2 };
  return { scheme: 'warning', label: 'Sin conexión', icon: AlertCircle };
};

const getDeviceLocation = (device) => {
  if (device.group_name) return `Grupo ${device.group_name}`;
  return device.location || 'Sin ubicación';
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

// ── Skeleton dinámico ──

const DynamicSkeleton = ({ count }) => {
  // El skeleton se ajusta al grid: 1 col en mobile, 2 en md, 3 en lg
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] p-5">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-start gap-3">
              <Skeleton className="h-11 w-11 rounded-control" />
              <div className="space-y-2">
                <Skeleton className="h-5 w-32" />
                <Skeleton className="h-4 w-24" />
              </div>
            </div>
            <Skeleton className="h-6 w-24 rounded-full" />
          </div>
          <div className="mt-4 border-t border-[var(--nx-border)] pt-3">
            <Skeleton className="h-4 w-40" />
          </div>
        </div>
      ))}
    </div>
  );
};

// ── Página principal ──

const Devices = () => {
  const { user } = useAuth();
  const canContingency = user?.role === ROLES.RECTOR || user?.role === ROLES.COORDINADOR;
  const [devices, setDevices] = useState([]);
  const [groups, setGroups] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [gradeFilter, setGradeFilter] = useState('');
  const [showRegister, setShowRegister] = useState(false);
  const [configureTarget, setConfigureTarget] = useState(null);
  const [revokeTarget, setRevokeTarget] = useState(null);
  const [newToken, setNewToken] = useState(null);
  const [pendingRevocations, setPendingRevocations] = useState([]);
  const [reassignTarget, setReassignTarget] = useState(null);
  const [reprovisionTarget, setReprovisionTarget] = useState(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      // Cargar grupos primero para que el skeleton sepa cuántos sensores esperar
      const grps = await studentsApi.getGroups().catch(() => []);
      setGroups(grps);

      const [devs, revocations] = await Promise.all([
        devicesApi.getAll(),
        devicesApi.getPendingRevocations().catch(() => []),
      ]);
      setDevices(devs);
      setPendingRevocations(revocations);
    } catch (err) {
      setError(humanizeError(err, 'No pudimos cargar los sensores.'));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { fetchData(); }, [fetchData]);

  // Auto-refresh cada 30s
  useEffect(() => {
    const t = setInterval(() => {
      devicesApi.getAll().then(setDevices).catch(() => {});
      devicesApi.getPendingRevocations().then(setPendingRevocations).catch(() => {});
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
        (d.location || '').toLowerCase().includes(gradeFilter.toLowerCase()) ||
        (d.group_name || '').toLowerCase().includes(gradeFilter.toLowerCase());
      return matchesSearch && matchesGrade;
    });
  }, [devices, search, gradeFilter]);

  // Sensores con revocación pendiente: marcar visualmente como "En eliminación"
  const pendingRevocationIds = useMemo(() => new Set(pendingRevocations.map((r) => r.device_id)), [pendingRevocations]);

  const configuredCount = devices.filter((d) => d.configured).length;
  const operativeCount = devices.filter((d) => {
    const s = getDeviceStatus(d, pendingRevocationIds);
    return s.scheme === 'success';
  }).length;

  return (
    <div className="space-y-6">
      <PageHeader
        eyebrow="Sensores"
        title="Lectores de huella"
        subtitle="Revisa el estado de tus sensores y configura nuevos lectores"
        meta={`${devices.length} sensor${devices.length !== 1 ? 'es' : ''} · ${operativeCount} operativo${operativeCount !== 1 ? 's' : ''}`}
        actions={
          <Button leftIcon={<Plus size={16} />} onClick={() => setShowRegister(true)}>
            Nuevo sensor
          </Button>
        }
      />

      {/* StatCards: Total + Configurados arriba (2 cols), Operativos abajo (ancho completo) */}
      {!loading && !error && (
        <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
          <Card tone="accent" edge>
            <div className="flex items-center gap-3">
              <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-icon-bg-accent)] text-[var(--nx-accent)]">
                <Fingerprint size={20} />
              </span>
              <div>
                <p className="text-h2 text-[var(--nx-text)] tabular-nums">{devices.length}</p>
                <p className="text-caption text-[var(--nx-text-muted)]">Total sensores</p>
              </div>
            </div>
          </Card>
          <Card tone={configuredCount === devices.length ? 'success' : 'warning'} edge>
            <div className="flex items-center gap-3">
              <span className="grid h-11 w-11 place-items-center rounded-control bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]">
                <ShieldCheck size={20} />
              </span>
              <div>
                <p className="text-h2 text-[var(--nx-text)] tabular-nums">{configuredCount}</p>
                <p className="text-caption text-[var(--nx-text-muted)]">Configurados</p>
              </div>
            </div>
          </Card>
          <Card tone="success" edge className="col-span-2 md:col-span-1">
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
        </div>
      )}

      {/* Revocaciones pendientes */}
      {pendingRevocations.length > 0 && (
        <Surface className="p-4 border-[var(--nx-border-warning)]">
          <div className="flex items-start gap-3">
            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-control bg-[var(--nx-subtle-bg-warning)] text-[var(--nx-warning)]">
              <Clock size={18} />
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-body-sm font-semibold text-[var(--nx-text)]">
                {pendingRevocations.length} sensor{pendingRevocations.length !== 1 ? 'es' : ''} en proceso de eliminaci&oacute;n
              </p>
              <div className="mt-2 space-y-1.5">
                {pendingRevocations.map((rev) => {
                  const device = devices.find((d) => d.device_id === rev.device_id);
                  const remaining = Math.max(0, new Date(rev.executes_at + 'Z').getTime() - Date.now());
                  const mins = Math.floor(remaining / 60000);
                  return (
                    <div key={rev.revocation_id} className="flex items-center justify-between gap-3 text-body-sm">
                      <span className="text-[var(--nx-text-muted)] truncate">
                        {device?.device_name || 'Sensor'} — {device?.location || ''}
                      </span>
                      <div className="flex items-center gap-2 shrink-0">
                        <Badge scheme="warning" dot>En {mins} min</Badge>
                        <Button
                          variant="ghost"
                          size="sm"
                          leftIcon={<X size={13} />}
                          onClick={() => setRevokeTarget({ ...device, revocationId: rev.revocation_id, cancelMode: true })}
                        >
                          Cancelar
                        </Button>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </div>
        </Surface>
      )}

      {/* Filtros */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <Input
          leftIcon={<Search size={16} />}
          placeholder="Buscar sensor por nombre o ubicación…"
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
            placeholder="Todos los grupos"
            searchPlaceholder="Buscar grupo…"
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
        <DynamicSkeleton count={Math.max(groups.length + 2, 3)} />
      ) : filtered.length === 0 ? (
        <Surface className="p-6">
          <EmptyState
            icon={<Fingerprint size={22} strokeWidth={1.75} className="text-[var(--nx-text-muted)]" />}
            title={devices.length === 0 ? 'No hay sensores registrados' : 'Sin resultados'}
            description={devices.length === 0
              ? 'Cuando configures los grupos académicos, los sensores se crearán automáticamente. También puedes registrar uno manualmente.'
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
              const status = getDeviceStatus(device, pendingRevocationIds);
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
                    isPendingRevocation={pendingRevocationIds.has(device.device_id)}
                    onConfigure={() => setConfigureTarget(device)}
                    onRevoke={() => setRevokeTarget({ ...device, cancelMode: false })}
                    onReassign={canContingency ? () => setReassignTarget(device) : undefined}
                    onReprovision={canContingency ? () => setReprovisionTarget(device) : undefined}
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
        groups={groups}
        onClose={() => setShowRegister(false)}
        onRegistered={(info) => {
          setNewToken(info);
          fetchData();
        }}
      />

      {/* Drawer: Configurar sensor (mostrar ID + token) */}
      <ConfigureDrawer
        device={configureTarget}
        onClose={() => setConfigureTarget(null)}
        onConfigured={(info) => {
          fetchData();
          if (info?.token) {
            setNewToken(info);
          }
          if (!info?.stayOpen) {
            setConfigureTarget(null);
          }
        }}
      />

      {/* Drawer: Mostrar token tras registro */}
      <TokenDrawer info={newToken} onClose={() => setNewToken(null)} />

      {/* Contingencia: reasignar nodo de punto */}
      <ReassignDrawer
        device={reassignTarget}
        groups={groups}
        onClose={() => setReassignTarget(null)}
        onDone={() => { setReassignTarget(null); fetchData(); }}
      />

      {/* Contingencia: reprovisionar (rota llaves — el token se muestra UNA vez) */}
      <ReprovisionConfirm
        device={reprovisionTarget}
        onClose={() => setReprovisionTarget(null)}
        onDone={(res) => {
          setReprovisionTarget(null);
          if (res?.device_token) {
            setNewToken({ token: res.device_token, device_id: res.device_id, name: reprovisionTarget?.device_name });
          }
          fetchData();
        }}
      />

      {/* Confirmación: Revocar sensor */}
      <AnimatePresence>
        {revokeTarget && (
          <RevokeConfirm
            device={revokeTarget}
            onCancel={() => setRevokeTarget(null)}
            onDone={() => {
              setRevokeTarget(null);
              fetchData();
            }}
          />
        )}
      </AnimatePresence>
    </div>
  );
};

// ── Tarjeta de dispositivo ──

const DeviceCard = ({ device, status, StatusIcon, onConfigure, onRevoke, isPendingRevocation, onReassign, onReprovision }) => {
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
              <span className="truncate">{getDeviceLocation(device)}</span>
            </p>
            {device.assigned_user_id && (
              <p className="mt-0.5 text-caption text-[var(--nx-text-muted)]">
                Asignado a: {device.assigned_user_first_name || ''} {device.assigned_user_last_name || ''}
                {device.assigned_user_role ? ` (${getRoleDisplay(device.assigned_user_role)})` : ''}
              </p>
            )}
          </div>
        </div>
        <Badge scheme={status.scheme} dot icon={<StatusIcon size={12} />}>
          {status.label}
        </Badge>
      </div>

      <div className="mt-4 flex items-center justify-between border-t border-[var(--nx-border)] pt-3">
        <div className="flex items-center gap-1.5 text-caption text-[var(--nx-text-muted)]">
          <RefreshCw size={12} />
          <span>{timeAgo(device.last_ping)}</span>
        </div>
        <div className="flex items-center gap-2">
          {!device.configured && !isPendingRevocation && (
            <Button variant="quiet" size="sm" leftIcon={<Settings2 size={13} />} onClick={onConfigure}>
              Configurar
            </Button>
          )}
          {!isPendingRevocation && (
            <Button
              variant="ghost"
              size="sm"
              leftIcon={<Trash2 size={13} />}
              onClick={onRevoke}
              className="text-[var(--nx-text-muted)] hover:text-[var(--nx-danger)]"
            >
              Eliminar
            </Button>
          )}
        </div>
      </div>
      {(onReassign || onReprovision) && !isPendingRevocation && (
        <div className="mt-2 flex items-center gap-2 border-t border-[var(--nx-border)] pt-2.5">
          {onReassign && (
            <Button variant="quiet" size="sm" leftIcon={<ArrowLeftRight size={13} />} onClick={onReassign}>
              Reasignar
            </Button>
          )}
          {onReprovision && (
            <Button variant="quiet" size="sm" leftIcon={<KeyRound size={13} />} onClick={onReprovision}
              className="text-[var(--nx-danger)]">
              Reprovisionar
            </Button>
          )}
        </div>
      )}
    </Card>
  );
};

// ── Drawer: Registrar nuevo sensor ──

const ROLE_OPTIONS = Object.values(ROLES).map((r) => ({ value: r, label: getRoleDisplay(r) }));

const RegisterDrawer = ({ open, groups, onClose, onRegistered }) => {
  const [name, setName] = useState('');
  const [location, setLocation] = useState('');
  const [groupId, setGroupId] = useState('');
  const [selectedRole, setSelectedRole] = useState('');
  const [assignedUserId, setAssignedUserId] = useState('');
  const [roleUsers, setRoleUsers] = useState([]);
  const [loadingUsers, setLoadingUsers] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState('');

  useEffect(() => {
    if (selectedRole) {
      setLoadingUsers(true);
      usersApi.getByRole(selectedRole, false)
        .then((res) => setRoleUsers(res.data || []))
        .catch(() => setRoleUsers([]))
        .finally(() => setLoadingUsers(false));
    } else {
      setRoleUsers([]);
      setAssignedUserId('');
    }
  }, [selectedRole]);

  const handleSubmit = async (e) => {
    e?.preventDefault();
    if (!name.trim()) return;
    setSaving(true);
    setErr('');
    try {
      const data = await devicesApi.register({
        name: name.trim(),
        location: location.trim(),
        group_id: groupId || undefined,
        assigned_user_id: assignedUserId || undefined,
      });
      onRegistered(data);
      setName('');
      setLocation('');
      setGroupId('');
      setSelectedRole('');
      setAssignedUserId('');
      onClose();
    } catch (error) {
      setErr(humanizeError(error, 'No pudimos registrar el sensor. Inténtalo de nuevo.'));
    } finally {
      setSaving(false);
    }
  };

  if (!open) return null;
  return (
    <Drawer
      title="Registrar nuevo sensor"
      context="Agrega un lector de huella a tu institución"
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
          placeholder="Ej. Sensor 7A, Sensor Portería"
          help="Un nombre que identifique el sensor."
          required
        />
        <Input
          label="Ubicación"
          value={location}
          onChange={(e) => setLocation(e.target.value)}
          placeholder="Ej. Entrada principal, Bloque A"
          help="Dónde está físicamente instalado."
        />
        {groups.length > 0 && (
          <div className="space-y-2">
            <label className="text-label text-[var(--nx-text)]">Grupo asociado (opcional)</label>
            <SearchableSelect
              options={groups.map((g) => ({ value: g.group_id || g.id, label: formatGroupName(g.name || g.group_name) }))}
              value={groupId}
              onChange={(v) => setGroupId(v || '')}
              placeholder="— Ninguno (sensor general) —"
              searchPlaceholder="Buscar grupo…"
              clearable
            />
            <p className="text-caption text-[var(--nx-text-muted)]">
              Si es un sensor de aula, selecciónalo. Los sensores de secretaría o generales no necesitan grupo.
            </p>
          </div>
        )}
        <div className="space-y-2">
          <label className="text-label text-[var(--nx-text)]">Rol del usuario asignado (opcional)</label>
          <SearchableSelect
            options={ROLE_OPTIONS}
            value={selectedRole}
            onChange={(v) => { setSelectedRole(v || ''); setAssignedUserId(''); }}
            placeholder="— Ninguno —"
            searchPlaceholder="Buscar rol…"
            clearable
          />
          <p className="text-caption text-[var(--nx-text-muted)]">
            Selecciona un rol para ver los usuarios disponibles.
          </p>
        </div>
        {selectedRole && (
          <div className="space-y-2">
            <label className="text-label text-[var(--nx-text)]">Usuario asignado</label>
            {loadingUsers ? (
              <div className="h-12 w-full nx-skeleton rounded-control" aria-hidden />
            ) : roleUsers.length === 0 ? (
              <div className="rounded-control bg-[var(--nx-surface-subtle)] px-4 py-3 text-body-sm text-[var(--nx-text-muted)]">
                No hay usuarios con el rol seleccionado.
              </div>
            ) : (
              <SearchableSelect
                options={roleUsers.map((u) => ({
                  value: u.user_id || u.id,
                  label: `${u.first_name || ''} ${u.last_name || ''}`.trim() || u.email || u.user_id,
                }))}
                value={assignedUserId}
                onChange={(v) => setAssignedUserId(v || '')}
                placeholder="— Seleccionar usuario —"
                searchPlaceholder="Buscar usuario…"
                clearable
              />
            )}
            <p className="text-caption text-[var(--nx-text-muted)]">
              Solo este usuario podrá usar el sensor para enrolar huellas. Si no seleccionas ninguno, el sensor no tendrá usuario asignado.
            </p>
          </div>
        )}
        <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4">
          <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
            Al registrar el sensor, recibirás un <strong className="text-[var(--nx-text)]">código de activación</strong> único.
            Guárdalo bien: lo necesitarás para configurar el sensor físico y vincularlo con tu institución.
          </p>
        </div>
      </form>
    </Drawer>
  );
};

// ── Drawer: Configurar sensor (marcar como configurado) ──

const ConfigureDrawer = ({ device, onClose, onConfigured }) => {
  const [phase, setPhase] = useState('info'); // 'info' → 'details'
  const [configuring, setConfiguring] = useState(false);
  const [err, setErr] = useState('');

  const handleConfigure = async () => {
    setConfiguring(true);
    setErr('');
    try {
      const result = await devicesApi.configure(device.device_id);
      // Mostrar la pantalla con ID + token
      setPhase('details');
      onConfigured({
        device_id: device.device_id,
        name: device.device_name,
        token: result?.token || null,
        stayOpen: true,
      });
    } catch (error) {
      setErr(humanizeError(error, 'No pudimos configurar el sensor. Inténtalo de nuevo.'));
    } finally {
      setConfiguring(false);
    }
  };

  if (!device) return null;

  if (phase === 'details') {
    // Después de configurar, mostrar ID + token (reusa TokenDrawer visual)
    return (
      <TokenDrawer
        info={{
          device_id: device.device_id,
          name: device.device_name,
          token: null, // El token ya se mostró en onConfigured → TokenDrawer
        }}
        onClose={onClose}
      />
    );
  }

  return (
    <Drawer
      title="Configurar sensor"
      context={device.device_name}
      onClose={onClose}
      size="sm"
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button className="flex-1" loading={configuring} onClick={handleConfigure} leftIcon={<Check size={16} />}>
            Aceptar y configurar
          </Button>
        </div>
      }
    >
      <div className="space-y-5 p-6">
        {err && (
          <div className="rounded-control bg-[var(--nx-subtle-bg-danger)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">
            {err}
          </div>
        )}
        <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4">
          <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
            Vas a configurar el sensor <strong className="text-[var(--nx-text)]">{device.device_name}</strong>.
            Al hacerlo, generaremos un <strong className="text-[var(--nx-text)]">código de activación</strong> único
            que debes guardar y usar en el dispositivo físico. Una vez que el sensor se conecte,
            aparecerá automáticamente como <strong className="text-[var(--nx-text)]">Operativo</strong>.
          </p>
        </div>
        {device.group_name && (
          <div className="rounded-control border border-[var(--nx-border-success)] bg-[var(--nx-surface-success)] p-4">
            <div className="flex items-center gap-2 mb-2">
              <span className="text-[var(--nx-success)]">🎯</span>
              <p className="text-body-sm font-semibold text-[var(--nx-text)]">Asignación automática</p>
            </div>
            <p className="text-body-sm text-[var(--nx-text-muted)]">
              Este sensor está asignado al grupo <strong className="text-[var(--nx-text)]">{device.group_name}</strong>.
              Esta asignación no se puede cambiar durante la configuración.
            </p>
          </div>
        )}
        {device.assigned_user_name && (
          <div className="rounded-control border border-[var(--nx-border-success)] bg-[var(--nx-surface-success)] p-4">
            <div className="flex items-center gap-2 mb-2">
              <span className="text-[var(--nx-success)]">👤</span>
              <p className="text-body-sm font-semibold text-[var(--nx-text)]">Usuario asignado</p>
            </div>
            <p className="text-body-sm text-[var(--nx-text-muted)]">
              Este sensor está asignado a <strong className="text-[var(--nx-text)]">{device.assigned_user_name}</strong>.
              Esta asignación no se puede cambiar durante la configuración.
            </p>
          </div>
        )}
        <div className="rounded-control border border-[var(--nx-border-warning)] bg-[var(--nx-surface-subtle)] p-4">
          <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
            <strong className="text-[var(--nx-text)]">Importante:</strong> El código de activación solo se muestra una vez.
            Guárdalo en un lugar seguro. Si lo pierdes, necesitarás la llave maestra para reconfigurar el sensor.
          </p>
        </div>
      </div>
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
    const textToCopy = `ID del sensor: ${deviceId}\nCódigo de activación: ${token}`;
    navigator.clipboard.writeText(textToCopy).then(() => {
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    });
  };

  return (
    <Drawer
      title={info.token ? "Sensor configurado" : "Sensor registrado correctamente"}
      context={info.name || 'Sensor'}
      onClose={onClose}
      size="sm"
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" onClick={onClose}>Cerrar</Button>
          <Button className="flex-1" onClick={handleCopy} leftIcon={copied ? <Check size={16} /> : <Cpu size={16} />}>
            {copied ? 'Copiado' : 'Copiar ID y código de activación'}
          </Button>
        </div>
      }
    >
      <div className="space-y-5 p-6">
        <div className="rounded-control border border-[var(--nx-border-success)] bg-[var(--nx-surface-success)] p-4">
          <div className="flex items-center gap-2 text-[var(--nx-success)]">
            <ShieldCheck size={18} />
            <p className="text-body-sm font-semibold">Sensor listo para usar</p>
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
            Guárdalo en un lugar seguro. Lo necesitarás para configurar el sensor físico.
            Por seguridad, no lo volveremos a mostrar.
          </p>
        </div>

        <div className="space-y-2">
          <label className="text-label text-[var(--nx-text)]">ID del sensor</label>
          <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-3">
            <code className="block break-all text-caption text-[var(--nx-text-muted)] font-mono">
              {deviceId}
            </code>
          </div>
        </div>

        <div className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-4">
          <p className="text-body-sm text-[var(--nx-text-muted)] leading-relaxed">
            <strong className="text-[var(--nx-text)]">Siguiente paso:</strong> Usa este código y el ID
            para configurar el sensor físico. Una vez que se conecte, aparecerá automáticamente
            como &laquo;Operativo&raquo; en esta pantalla.
          </p>
        </div>
      </div>
    </Drawer>
  );
};

// ── Confirmación: Revocar sensor (con password + countdown) ──

const RevokeConfirm = ({ device, onCancel, onDone }) => {
  const isCancelMode = device?.cancelMode;
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [err, setErr] = useState('');

  const handleSubmit = async (e) => {
    e?.preventDefault();
    if (!password) return;
    setSubmitting(true);
    setErr('');
    try {
      if (isCancelMode) {
        await devicesApi.cancelRevocation(device.device_id, device.revocationId, password);
      } else {
        await devicesApi.startRevocation(device.device_id, password);
      }
      onDone();
    } catch (error) {
      setErr(humanizeError(error, isCancelMode ? 'No pudimos cancelar la eliminación.' : 'No pudimos iniciar la eliminación.'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
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
        aria-label={isCancelMode ? 'Cancelar eliminación' : 'Eliminar sensor'}
        initial={{ opacity: 0, scale: 0.96 }}
        animate={{ opacity: 1, scale: 1 }}
        exit={{ opacity: 0, scale: 0.96 }}
        transition={{ duration: 0.2, ease: EASE }}
        className="fixed left-1/2 top-1/2 z-50 w-[min(440px,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 rounded-surface border border-[var(--nx-border-danger)] bg-[var(--nx-surface)] p-6 shadow-large"
      >
        <form onSubmit={handleSubmit}>
          <div className="mb-4 flex items-start gap-3">
            <span className="grid h-11 w-11 shrink-0 place-items-center rounded-control bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]">
              {isCancelMode ? <ShieldCheck size={22} /> : <AlertCircle size={22} />}
            </span>
            <div>
              <h3 className="text-h3 text-[var(--nx-text)]">
                {isCancelMode ? '¿Cancelar la eliminación?' : '¿Eliminar este sensor?'}
              </h3>
              <p className="mt-1 text-body-sm text-[var(--nx-text-muted)]">
                <strong className="text-[var(--nx-text)]">{device.device_name}</strong>
                {device.location ? ` · ${device.location}` : ''}
              </p>
            </div>
          </div>

          <p className="mb-4 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">
            {isCancelMode ? (
              'Si cancelas, el sensor seguirá activo y funcionando normalmente.'
            ) : (
              'El sensor se eliminará en 1 hora. Durante ese tiempo, puedes cancelar si fue un error. ' +
              'Tras la eliminación, el sensor desaparece permanentemente. Para volver a usarlo, necesitarás registrarlo de nuevo.'
            )}
          </p>

          {err && (
            <div className="mb-4 rounded-control bg-[var(--nx-subtle-bg-danger)] px-4 py-3 text-body-sm text-[var(--nx-danger)]" role="alert">
              {err}
            </div>
          )}

          <Input
            label="Confirma con tu contraseña"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            placeholder="Tu contraseña de acceso"
            required
            autoFocus
          />

          <div className="mt-6 flex gap-3">
            <Button type="button" variant="secondary" className="flex-1" onClick={onCancel} disabled={submitting}>
              No, cancelar
            </Button>
            <Button
              type="submit"
              variant={isCancelMode ? 'primary' : 'danger'}
              className="flex-1"
              loading={submitting}
              disabled={!password}
              leftIcon={isCancelMode ? <Check size={16} /> : <Trash2 size={16} />}
            >
              {isCancelMode ? 'Sí, cancelar eliminación' : 'Sí, eliminar sensor'}
            </Button>
          </div>
        </form>
      </motion.div>
    </>
  );
};

// ── Contingencia: Reasignar nodo de punto ──

const ReassignDrawer = ({ device, groups, onClose, onDone }) => {
  const [groupId, setGroupId] = useState('');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    setGroupId(device?.group_id || '');
    setReason('');
    setError('');
  }, [device]);

  if (!device) return null;

  const handleSubmit = async () => {
    setSaving(true);
    setError('');
    try {
      await devicesApi.reassign(device.device_id, {
        groupId: groupId || null,
        classroomId: device.classroom_id || null, // se conserva el aula actual
        reason: reason || 'Reubicación de nodo',
      });
      onDone();
    } catch (e) {
      setError(humanizeError(e, 'No se pudo reasignar el nodo'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <Drawer
      title={`Reasignar ${device.device_name || 'sensor'}`}
      context={getDeviceLocation(device)}
      onClose={onClose}
      size="sm"
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button className="flex-1" loading={saving} onClick={handleSubmit} leftIcon={<Check size={16} />}>
            Reasignar
          </Button>
        </div>
      }
    >
      <div className="space-y-5 p-6">
        <p className="text-body-sm text-[var(--nx-text-muted)]">
          Mueve el nodo a otro grupo sin tocar sus llaves. Útil cuando un sensor se traslada de punto o un aula queda sin cobertura.
        </p>
        <SearchableSelect
          label="Grupo"
          options={[{ value: '', label: 'Sin grupo (desasignar)' }, ...groups.map((g) => {
            const id = g.group_id || g.id;
            const name = g.name || g.group_name || String(g);
            return { value: id, label: formatGroupName(name) };
          })]}
          value={groupId}
          onChange={(v) => setGroupId(v)}
          placeholder="— Seleccionar grupo —"
          searchPlaceholder="Buscar grupo…"
          clearable={false}
        />
        <div>
          <label className="text-label text-[var(--nx-text)]" htmlFor="reassign-reason">Motivo</label>
          <textarea
            id="reassign-reason"
            rows={2}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Reubicación de nodo"
            className="mt-1.5 w-full rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-3 py-2.5 text-body-sm text-[var(--nx-text)] focus:outline-none focus:ring-2 focus:ring-[var(--nx-ring)]"
          />
        </div>
        {error && <p role="alert" className="text-body-sm text-[var(--nx-danger)]">{error}</p>}
      </div>
    </Drawer>
  );
};

// ── Contingencia: Reprovisionar (rota token + OTA key) ──

const ReprovisionConfirm = ({ device, onClose, onDone }) => {
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => { setError(''); }, [device]);
  if (!device) return null;

  const handleSubmit = async () => {
    setSaving(true);
    setError('');
    try {
      const res = await devicesApi.reprovision(device.device_id, 'Reprovisión desde el panel');
      onDone(res);
    } catch (e) {
      setError(humanizeError(e, 'No se pudo reprovisionar el nodo'));
      setSaving(false);
    }
  };

  return (
    <Drawer
      title={`Reprovisionar ${device.device_name || 'sensor'}`}
      context="Solo si las llaves se comprometieron o el nodo se reemplaza"
      onClose={onClose}
      size="sm"
      footer={
        <div className="flex gap-3">
          <Button variant="secondary" onClick={onClose}>Cancelar</Button>
          <Button variant="danger" className="flex-1" loading={saving} onClick={handleSubmit} leftIcon={<KeyRound size={16} />}>
            Rotar llaves
          </Button>
        </div>
      }
    >
      <div className="space-y-4 p-6">
        <p className="text-body-sm text-[var(--nx-text)]">
          Rota el <b>token</b> y la <b>OTA key</b> del nodo. Las llaves anteriores mueren al instante y el sensor vuelve a pedir provisionamiento.
        </p>
        <div className="rounded-control bg-[var(--nx-subtle-bg-danger)] px-4 py-3 text-body-sm text-[var(--nx-danger)]">
          La nueva llave se muestra UNA sola vez después de rotar — instálala en el nodo físico. Nunca aparece en listas ni queda guardada visible.
        </div>
        {error && <p role="alert" className="text-body-sm text-[var(--nx-danger)]">{error}</p>}
      </div>
    </Drawer>
  );
};

export default Devices;
