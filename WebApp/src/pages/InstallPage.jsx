/**
 * SCR-INS-01 InstallPage
 * Pantalla de instalación con descarga directa y PWA.
 * Fondo verde (success), botón de descarga nativa + instalación PWA.
 */
import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { Download, CheckCircle, Globe, Monitor, Apple, Smartphone, ArrowLeft } from 'lucide-react';
import { Button } from '../components/ui/Button';
import LogoNexo from '../components/LogoNexo';

const APP_URL = 'https://nexo-eight-xi.vercel.app';

const PLATFORMS = {
  android: {
    title: 'Android',
    icon: Smartphone,
    steps: [`Abre Chrome y visita ${APP_URL}.`, 'Toca el menú y selecciona "Agregar a pantalla de inicio".', 'Confirma con "Agregar".'],
  },
  ios: {
    title: 'iOS',
    icon: Smartphone,
    steps: [`Abre Safari y visita ${APP_URL}.`, 'Toca Compartir y luego "Agregar a inicio".', 'Confirma con "Agregar".'],
  },
  windows: {
    title: 'Windows',
    icon: Monitor,
    steps: ['Abre Edge o Chrome.', 'Haz clic en el icono de instalación en la barra de direcciones.', 'Confirma la instalación.'],
  },
  mac: {
    title: 'macOS',
    icon: Apple,
    steps: ['Abre Chrome.', 'Haz clic en el icono de instalación en la barra de direcciones.', 'Arrastra NEXO a Aplicaciones si aplica.'],
  },
  linux: {
    title: 'Linux',
    icon: Monitor,
    steps: ['Abre Chrome.', 'Haz clic en "Instalar" en el banner de PWA.', 'Confirma la instalación.'],
  },
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
    <div
      className="fixed inset-0 z-[100] flex flex-col items-center justify-center overflow-y-auto px-4 py-8"
      style={{
        background:
          'radial-gradient(ellipse at 50% 0%, var(--nx-surface-success), var(--nx-canvas) 70%)',
      }}
    >
      {/* Back button */}
      <button
        onClick={() => navigate('/login')}
        className="absolute top-4 left-4 flex items-center gap-1.5 text-body-sm text-[var(--nx-text-muted)] hover:text-[var(--nx-text)] transition-colors"
      >
        <ArrowLeft size={16} /> Volver
      </button>

      <div className="w-full max-w-md space-y-6">
        {/* Logo */}
        <div className="flex flex-col items-center gap-3">
          <LogoNexo className="h-16" useImage />
          <h1 className="text-h1 text-[var(--nx-text)] text-center">Instalar NEXO</h1>
          <p className="text-body text-[var(--nx-text-muted)] text-center">
            {config.title} · {detectBrowser()} · {isMobile() ? 'Móvil' : 'Escritorio'}
          </p>
        </div>

        {installed ? (
          /* Success state */
          <div className="flex flex-col items-center gap-4 rounded-panel border border-[var(--nx-border-success)] bg-[var(--nx-surface-success)] p-8">
            <CheckCircle size={48} className="text-[var(--nx-success)]" />
            <p className="text-h3 text-[var(--nx-text)] text-center">
              NEXO se ha instalado correctamente
            </p>
            <Button variant="primary" size="lg" block onClick={() => navigate('/')}>
              Abrir NEXO
            </Button>
          </div>
        ) : (
          <>
            {/* Download card with green theme */}
            <div className="rounded-panel border border-[var(--nx-border-success)] bg-[var(--nx-surface)] p-6 shadow-high space-y-5">
              <div className="flex items-center gap-4">
                <div
                  className="flex h-14 w-14 shrink-0 items-center justify-center rounded-control"
                  style={{ backgroundColor: 'var(--nx-icon-bg-success)' }}
                >
                  <Icon size={28} className="text-[var(--nx-success)]" />
                </div>
                <div className="flex-1">
                  <p className="text-h2 text-[var(--nx-text)]">{config.title}</p>
                  <p className="text-body-sm text-[var(--nx-text-muted)] mt-0.5">
                    Instala NEXO como aplicación en tu dispositivo
                  </p>
                </div>
              </div>

              {/* Main green button: PWA install or manual instructions */}
              {deferredPrompt ? (
                <button
                  onClick={handleInstall}
                  className="flex h-13 w-full items-center justify-center gap-2 rounded-control font-label text-body transition-all hover:shadow-medium active:scale-[0.98]"
                  style={{
                    backgroundColor: 'var(--nx-success)',
                    color: 'var(--nx-on-solid)',
                  }}
                >
                  <Download size={20} />
                  Descargar app
                </button>
              ) : (
                <a
                  href={APP_URL}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex h-13 w-full items-center justify-center gap-2 rounded-control font-label text-body transition-all hover:shadow-medium active:scale-[0.98]"
                  style={{
                    backgroundColor: 'var(--nx-success)',
                    color: 'var(--nx-on-solid)',
                  }}
                >
                  <Globe size={20} />
                  Descargar app
                </a>
              )}
            </div>

            {/* Manual steps */}
            <div className="rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] p-5 space-y-3">
              <p className="text-label text-[var(--nx-text)]">
                Pasos manuales
              </p>
              <ol className="list-decimal pl-5 space-y-2 text-body-sm text-[var(--nx-text-muted)]">
                {config.steps.map((s, i) => <li key={i}>{s}</li>)}
              </ol>
            </div>

            <p className="flex items-center justify-center gap-2 text-caption text-[var(--nx-text-muted)]">
              <Globe size={14} /> NEXO también funciona como PWA en cualquier navegador.
            </p>
          </>
        )}
      </div>
    </div>
  );
}
