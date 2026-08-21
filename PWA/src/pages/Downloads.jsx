/**
 * SCR-DWN-01 Downloads
 * Instalación PWA por plataforma.
 */
import { useState, useEffect } from 'react';
import { Monitor, Apple, Smartphone, Info } from 'lucide-react';
import { Card } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';

const platforms = {
  android: { id: 'android', label: 'Android', icon: Smartphone, color: 'var(--nx-success)', instructions: ['Abre NEXO en Chrome.', 'Toca el ícono de instalar en la barra de direcciones.', 'Confirma "Instalar app".'] },
  ios:     { id: 'ios', label: 'iOS', icon: Apple, color: 'var(--nx-text)', instructions: ['Abre NEXO en Safari.', 'Toca el botón Compartir.', 'Selecciona "Añadir a pantalla de inicio".'] },
  windows: { id: 'windows', label: 'Windows', icon: Monitor, color: 'var(--nx-accent)', instructions: ['Abre NEXO en Chrome o Edge.', 'Haz clic en el ícono de instalar en la barra de direcciones.', 'Confirma "Instalar".'] },
  mac:     { id: 'mac', label: 'macOS', icon: Apple, color: 'var(--nx-text)', instructions: ['Abre NEXO en Chrome.', 'Haz clic en el ícono de instalar en la barra de direcciones.', 'Confirma "Instalar".'] },
};

const detectOs = () => {
  const ua = window.navigator.userAgent.toLowerCase();
  if (ua.includes('win')) return 'windows';
  if (ua.includes('mac') && !ua.includes('android')) return 'mac';
  if (ua.includes('android')) return 'android';
  if (ua.includes('iphone') || ua.includes('ipad')) return 'ios';
  return 'android';
};

const Downloads = () => {
  const [selected, setSelected] = useState('android');
  const [detected, setDetected] = useState('android');

  useEffect(() => {
    const os = detectOs();
    setDetected(os);
    setSelected(os);
  }, []);

  const active = platforms[selected];
  const Icon = active.icon;

  return (
    <div className="mx-auto max-w-3xl space-y-8">
      <div className="grid grid-cols-1 sm:grid-cols-4 gap-3">
        {Object.values(platforms).map((p) => (
          <button
            key={p.id}
            onClick={() => setSelected(p.id)}
            className={`rounded-surface border p-4 text-left transition-all duration-fast ${
              selected === p.id ? 'border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)]' : 'border-[var(--nx-border)] hover:border-[var(--nx-text-muted)]'
            }`}
          >
            <div className="flex items-center justify-between">
              <p.icon size={22} style={{ color: p.color }} />
              {detected === p.id && <Badge scheme="success" dot>Detectado</Badge>}
            </div>
            <p className="mt-3 text-h3 text-[var(--nx-text)]">{p.label}</p>
          </button>
        ))}
      </div>

      <Card>
        <div className="flex items-start gap-4">
          <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-control" style={{ backgroundColor: 'var(--nx-subtle-bg-accent)' }}>
            <Icon size={24} style={{ color: active.color }} />
          </div>
          <div className="flex-1">
            <p className="text-h2 text-[var(--nx-text)]">{active.label}</p>
            <ol className="mt-4 space-y-2 text-body text-[var(--nx-text-muted)] list-decimal pl-4">
              {active.instructions.map((step, i) => <li key={i}>{step}</li>)}
            </ol>
          </div>
        </div>
      </Card>

      <p className="flex items-center gap-2 text-caption text-[var(--nx-text-muted)]">
        <Info size={14} /> NEXO es una PWA: no requiere tienda de apps. Funciona offline después de instalar.
      </p>
    </div>
  );
};

export default Downloads;
