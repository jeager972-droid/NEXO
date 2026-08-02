/**
 * SCR-INS-01 InstallPage
 * Instrucciones de instalación PWA y enlaces a descargas nativas.
 */
import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Download, CheckCircle, Globe, Monitor, Apple, Smartphone } from 'lucide-react';
import { Button } from '../components/ui/Button';
import { Surface } from '../components/ui/Surface';
import { Card } from '../components/ui/Card';

const APP_URL = 'https://nexo-eight-xi.vercel.app';

const PLATFORMS = {
  android: { title: 'Android', icon: Smartphone, store: 'Google Play', steps: [`Abre Chrome y visita ${APP_URL}.`, 'Toca el menú y selecciona "Agregar a pantalla de inicio".', 'Confirma con "Agregar".'] },
  ios:     { title: 'iOS',     icon: Smartphone, store: 'App Store',   steps: [`Abre Safari y visita ${APP_URL}.`, 'Toca Compartir y luego "Agregar a inicio".', 'Confirma con "Agregar".'] },
  windows: { title: 'Windows', icon: Monitor,    store: 'Edge/Chrome', steps: ['Abre Edge o Chrome.', 'Haz clic en el icono de instalación en la barra de direcciones.', 'Confirma la instalación.'] },
  mac:     { title: 'macOS',   icon: Apple,      store: 'Chrome',      steps: ['Abre Chrome.', 'Haz clic en el icono de instalación en la barra de direcciones.', 'Arrastra NEXO a Aplicaciones si aplica.'] },
  linux:   { title: 'Linux',   icon: Monitor,    store: 'Chrome',      steps: ['Abre Chrome.', 'Haz clic en "Instalar" en el banner de PWA.', 'Confirma la instalación.'] },
};

const detectBrowser = () => {
  const ua = navigator.userAgent.toLowerCase();
  if (ua.includes('edg/')) return 'edge';
  if (ua.includes('chrome')) return 'chrome';
  if (ua.includes('safari')) return 'safari';
  if (ua.includes('firefox')) return 'firefox';
  return 'chrome';
};

const isMobile = () => /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/i.test(navigator.userAgent);

export default function InstallPage() {
  const { platform } = useParams();
  const navigate = useNavigate();
  const [deferredPrompt, setDeferredPrompt] = useState(null);
  const [installed, setInstalled] = useState(false);

  useEffect(() => {
    if (!PLATFORMS[platform]) navigate('/login');
    if (window.__nexoPwaPrompt) setDeferredPrompt(window.__nexoPwaPrompt);

    const handler = (e) => { e.preventDefault(); window.__nexoPwaPrompt = e; setDeferredPrompt(e); };
    const installedHandler = () => setInstalled(true);
    window.addEventListener('beforeinstallprompt', handler);
    window.addEventListener('appinstalled', installedHandler);
    return () => {
      window.removeEventListener('beforeinstallprompt', handler);
      window.removeEventListener('appinstalled', installedHandler);
    };
  }, [platform, navigate]);

  const handleInstall = async () => {
    if (deferredPrompt?.prompt) {
      deferredPrompt.prompt();
      const { outcome } = await deferredPrompt.userChoice;
      if (outcome === 'accepted') setInstalled(true);
    }
  };

  const config = PLATFORMS[platform] || PLATFORMS.android;
  const Icon = config.icon;

  return (
    <div className="mx-auto max-w-2xl space-y-8 py-6">
      {installed ? (
        <Surface className="flex items-center gap-3 p-5 border border-[var(--nx-success)]">
          <CheckCircle size={24} className="text-[var(--nx-success)]" />
          <p className="text-body text-[var(--nx-text)]">NEXO se ha instalado correctamente.</p>
        </Surface>
      ) : (
        <>
          <Card>
            <div className="flex items-start gap-4">
              <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-control bg-[var(--nx-subtle-bg-accent)]">
                <Icon size={24} className="text-[var(--nx-accent)]" />
              </div>
              <div className="flex-1">
                <p className="text-h2 text-[var(--nx-text)]">{config.title}</p>
                <p className="text-body text-[var(--nx-text-muted)] mt-1">Navegador: {detectBrowser()} · {isMobile() ? 'Móvil' : 'Escritorio'}</p>
              </div>
            </div>
          </Card>

          {deferredPrompt && (
            <Button size="lg" className="w-full" onClick={handleInstall} leftIcon={<Download size={18} />}>
              Instalar ahora
            </Button>
          )}

          <Surface className="p-5 space-y-3">
            <p className="text-label text-[var(--nx-text)]">Pasos manuales</p>
            <ol className="list-decimal pl-5 space-y-2 text-body text-[var(--nx-text-muted)]">
              {config.steps.map((s, i) => <li key={i}>{s}</li>)}
            </ol>
          </Surface>

          <p className="flex items-center gap-2 text-caption text-[var(--nx-text-muted)]">
            <Globe size={14} /> NEXO funciona mejor como PWA instalada.
          </p>
        </>
      )}
    </div>
  );
}
