/**
 * SCR-DWN-01 Downloads
 * Descargas nativas por plataforma.
 */
import { useState, useEffect } from 'react';
import { Monitor, Apple, Smartphone, Info } from 'lucide-react';
import { PageHeader } from '../components/ui/Surface';
import { Card } from '../components/ui/Card';
import { Badge } from '../components/ui/Badge';

const platforms = {
  android: { id: 'android', label: 'Android', icon: Smartphone, color: 'var(--nx-success)', apk: '/downloads/nexo-android.apk', instructions: ['Descarga el APK.', 'Permite instalación de orígenes desconocidos.', 'Abre el archivo e instala.'] },
  windows: { id: 'windows', label: 'Windows', icon: Monitor, color: 'var(--nx-accent)', exe: '/downloads/nexo-windows.exe', instructions: ['Descarga el instalador.', 'Ejecuta el archivo .exe.', 'Sigue las instrucciones del asistente.'] },
  mac:     { id: 'mac', label: 'macOS', icon: Apple, color: 'var(--nx-text)', dmg: '/downloads/nexo-macos.dmg', instructions: ['Descarga el .dmg.', 'Arrastra NEXO a Aplicaciones.', 'Abre desde Launchpad.'] },
};

const detectOs = () => {
  const ua = window.navigator.userAgent.toLowerCase();
  if (ua.includes('win')) return 'windows';
  if (ua.includes('mac') && !ua.includes('android')) return 'mac';
  if (ua.includes('android')) return 'android';
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
  const link = active.apk || active.exe || active.dmg;

  return (
    <div className="mx-auto max-w-3xl space-y-8">
      <PageHeader
        eyebrow="Aplicaciones nativas"
        title="Descargas"
        subtitle="Aplicaciones nativas de NEXO por plataforma"
      />

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        {Object.values(platforms).map((p) => (
          <button
            key={p.id}
            onClick={() => setSelected(p.id)}
            className={`rounded-surface border p-4 text-left transition-all duration-fast ${
              selected === p.id ? 'border-[var(--nx-accent)] bg-[color-mix(in_oklch,var(--nx-accent)_8%,transparent)]' : 'border-[var(--nx-border)] hover:border-[var(--nx-text-muted)]'
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
          <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-control" style={{ backgroundColor: 'color-mix(in_oklch, var(--nx-accent) 10%, transparent)' }}>
            <Icon size={24} style={{ color: active.color }} />
          </div>
          <div className="flex-1">
            <p className="text-h2 text-[var(--nx-text)]">{active.label}</p>
            <ol className="mt-4 space-y-2 text-body text-[var(--nx-text-muted)] list-decimal pl-4">
              {active.instructions.map((step, i) => <li key={i}>{step}</li>)}
            </ol>
            {link && (
              <div className="mt-6">
                <a
                  href={link}
                  download
                  className="inline-flex h-11 items-center justify-center rounded-control bg-[var(--nx-accent)] px-5 text-body font-label text-[var(--nx-accent-text)] transition-all hover:shadow-medium active:scale-[0.98]"
                >
                  Descargar para {active.label}
                </a>
              </div>
            )}
          </div>
        </div>
      </Card>

      <p className="flex items-center gap-2 text-caption text-[var(--nx-text-muted)]">
        <Info size={14} /> Las versiones móviles requieren permisos de instalación según la plataforma.
      </p>
    </div>
  );
};

export default Downloads;
