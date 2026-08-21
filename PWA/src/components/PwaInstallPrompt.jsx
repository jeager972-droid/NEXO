/**
 * PwaInstallPrompt / NEXO Institucional
 * Botón flotante para instalar la PWA cuando el navegador lo permite.
 */
import { useEffect, useState } from 'react';
import { Download, X } from 'lucide-react';
import { Button } from './ui/Button';

function PwaInstallPrompt() {
  const [deferredPrompt, setDeferredPrompt] = useState(null);
  const [installed, setInstalled] = useState(false);

  useEffect(() => {
    const handler = (e) => { e.preventDefault(); window.__nexoPwaPrompt = e; setDeferredPrompt(e); };
    const installedHandler = () => setInstalled(true);
    window.addEventListener('beforeinstallprompt', handler);
    window.addEventListener('appinstalled', installedHandler);
    return () => {
      window.removeEventListener('beforeinstallprompt', handler);
      window.removeEventListener('appinstalled', installedHandler);
    };
  }, []);

  const handleInstall = async () => {
    if (!deferredPrompt?.prompt) return;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    if (outcome === 'accepted') setInstalled(true);
  };

  if (!deferredPrompt || installed) return null;

  return (
    <div className="fixed bottom-4 right-4 z-[70] max-w-sm rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4 shadow-high">
      <div className="flex items-start gap-3">
        <Download size={20} className="mt-0.5 text-[var(--nx-accent)]" />
        <div className="flex-1">
          <p className="text-label text-[var(--nx-text)]">Instalar NEXO</p>
          <p className="text-body-sm text-[var(--nx-text-muted)]">Añade la app para acceso rápido y notificaciones.</p>
        </div>
        <button onClick={() => setDeferredPrompt(null)} className="text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]"><X size={16} /></button>
      </div>
      <div className="mt-4 flex justify-end gap-2">
        <Button size="sm" variant="secondary" onClick={() => setDeferredPrompt(null)}>Más tarde</Button>
        <Button size="sm" onClick={handleInstall}>Instalar</Button>
      </div>
    </div>
  );
}

export default PwaInstallPrompt;
