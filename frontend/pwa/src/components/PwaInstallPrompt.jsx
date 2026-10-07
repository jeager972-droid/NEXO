/**
 * PwaInstallPrompt / NEXO Institucional
 * Botón flotante para instalar la PWA cuando el navegador lo permite.
 * «Más tarde»/«X» se recuerdan 7 días — el prompt no reaparece en cada
 * sesión. Va en la esquina inferior IZQUIERDA sobre la barra de
 * navegación: la derecha es del bot de Nodus.
 */
import { useEffect, useState } from 'react';
import { Download, X } from 'lucide-react';
import { Button } from './ui/Button';

const DISMISS_KEY = 'nx:pwa-install-dismissed';
const DISMISS_MS = 7 * 24 * 60 * 60 * 1000; // 7 días

const wasDismissed = () => {
  try {
    const t = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
    return t > 0 && Date.now() - t < DISMISS_MS;
  } catch { return false; }
};

function PwaInstallPrompt() {
  const [deferredPrompt, setDeferredPrompt] = useState(null);
  const [installed, setInstalled] = useState(false);

  useEffect(() => {
    const handler = (e) => {
      e.preventDefault();
      window.__nexoPwaPrompt = e;
      if (wasDismissed()) return;
      setDeferredPrompt(e);
    };
    const installedHandler = () => setInstalled(true);
    window.addEventListener('beforeinstallprompt', handler);
    window.addEventListener('appinstalled', installedHandler);
    return () => {
      window.removeEventListener('beforeinstallprompt', handler);
      window.removeEventListener('appinstalled', installedHandler);
    };
  }, []);

  const dismiss = () => {
    try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch { /* sin storage */ }
    setDeferredPrompt(null);
  };

  const handleInstall = async () => {
    if (!deferredPrompt?.prompt) return;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    if (outcome === 'accepted') setInstalled(true);
  };

  if (!deferredPrompt || installed) return null;

  return (
    <div className="fixed bottom-20 left-4 z-[70] max-w-sm rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4 shadow-high sm:bottom-6 sm:left-6">
      <div className="flex items-start gap-3">
        <Download size={20} className="mt-0.5 text-[var(--nx-accent)]" />
        <div className="flex-1">
          <p className="text-label text-[var(--nx-text)]">Instalar NEXO</p>
          <p className="text-body-sm text-[var(--nx-text-muted)]">Añade la app para acceso rápido y notificaciones.</p>
        </div>
        <button onClick={dismiss} className="text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]"><X size={16} /></button>
      </div>
      <div className="mt-4 flex justify-end gap-2">
        <Button size="sm" variant="secondary" onClick={dismiss}>Más tarde</Button>
        <Button size="sm" onClick={handleInstall}>Instalar</Button>
      </div>
    </div>
  );
}

export default PwaInstallPrompt;
