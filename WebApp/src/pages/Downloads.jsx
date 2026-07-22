/**
 * Downloads page / NEXO Institucional
 * Responsabilidad: Página pública de descarga de instaladores. Detecta el SO del navegador,
 * permite seleccionar plataforma y descarga el APK Android; otras plataformas muestran
 * estado "Próximamente".
 * Dependencias: React, lucide-react.
 */
import { useState, useEffect } from 'react';
import { Download, Monitor, Apple, Smartphone, Info } from 'lucide-react';

const Downloads = () => {
  const [selectedOs, setSelectedOs] = useState('android');
  const [detectedOs, setDetectedOs] = useState('unknown');

  useEffect(() => {
    const userAgent = window.navigator.userAgent.toLowerCase();
    let detected = 'android'; // Default to android as it is our primary active platform
    if (userAgent.includes('win')) {
      detected = 'windows';
    } else if (userAgent.includes('mac')) {
      detected = 'macos';
    } else if (userAgent.includes('linux')) {
      detected = 'linux';
    } else if (userAgent.includes('android')) {
      detected = 'android';
    } else if (userAgent.includes('iphone') || userAgent.includes('ipad')) {
      detected = 'ios';
    }
    setDetectedOs(detected);
    setSelectedOs(detected);
  }, []);

  const platforms = {
    android: {
      id: 'android',
      label: 'Android (APK)',
      icon: <Smartphone className="w-6 h-6" />,
      url: '/assets/downloads/nexo.apk',
      filename: 'nexo.apk',
      description: 'Aplicación nativa para celulares y tablets Android (requiere habilitar orígenes desconocidos).',
      disabled: false,
      badge: 'Recomendado'
    },
    windows: {
      id: 'windows',
      label: 'Windows',
      icon: <Monitor className="w-6 h-6" />,
      url: null,
      description: 'Cliente de escritorio nativo para Windows 10 y 11 con inicio automatizado.',
      disabled: true,
      badge: 'Próximamente'
    },
    macos: {
      id: 'macos',
      label: 'macOS',
      icon: <Apple className="w-6 h-6" />,
      url: null,
      description: 'Versión nativa optimizada para Apple Silicon (M1/M2/M3) y procesadores Intel.',
      disabled: true,
      badge: 'Próximamente'
    },
    linux: {
      id: 'linux',
      label: 'Linux',
      icon: <Monitor className="w-6 h-6" />,
      url: null,
      description: 'Instalador universal AppImage compatible con las principales distribuciones.',
      disabled: true,
      badge: 'Próximamente'
    },
    ios: {
      id: 'ios',
      label: 'iOS (App Store)',
      icon: <Smartphone className="w-6 h-6" />,
      url: null,
      description: 'Aplicación oficial para iPhone y iPad disponible próximamente en App Store.',
      disabled: true,
      badge: 'Próximamente'
    }
  };

  const activePlatform = platforms[selectedOs] || platforms.android;

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-gray-50 dark:bg-slate-950 p-6 transition-colors duration-300">
      {/* Header */}
      <div className="text-center max-w-2xl mb-12">
        <h1 className="text-4xl md:text-5xl font-black text-institutional-900 dark:text-white uppercase tracking-tight mb-4">
          Descarga NEXO Institucional
        </h1>
        <p className="text-gray-500 dark:text-slate-400 text-lg">
          La aplicación oficial para la gestión educativa y control de asistencia en tiempo real. 
          Selecciona tu plataforma para descargar el instalador oficial.
        </p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 max-w-5xl w-full">
        {/* Left Column: Platform Selector */}
        <div className="lg:col-span-1 flex flex-col gap-3">
          <h2 className="text-sm font-bold text-gray-400 dark:text-slate-500 uppercase tracking-wider mb-2 px-2">
            Plataformas Soportadas
          </h2>
          {Object.values(platforms).map((plat) => {
            const isSelected = selectedOs === plat.id;
            const isDetected = detectedOs === plat.id;

            return (
              <button
                key={plat.id}
                onClick={() => setSelectedOs(plat.id)}
                className={`w-full flex items-center justify-between p-4 rounded-2xl border text-left transition-all duration-200 ${
                  isSelected
                    ? 'bg-institutional-900 border-institutional-900 text-white shadow-lg shadow-institutional-900/10'
                    : 'bg-white dark:bg-slate-900 border-gray-100 dark:border-slate-800 hover:border-gray-300 dark:hover:border-slate-700 text-gray-700 dark:text-slate-300'
                }`}
              >
                <div className="flex items-center gap-3">
                  <div className={isSelected ? 'text-white' : 'text-institutional-600 dark:text-institutional-400'}>
                    {plat.icon}
                  </div>
                  <div>
                    <span className="font-bold block text-sm md:text-base">{plat.label}</span>
                    {isDetected && (
                      <span className={`text-[10px] uppercase font-bold px-1.5 py-0.5 rounded-md ${
                        isSelected 
                          ? 'bg-white/20 text-white' 
                          : 'bg-institutional-100 dark:bg-institutional-950 text-institutional-600 dark:text-institutional-400'
                      }`}>
                        Tu Sistema
                      </span>
                    )}
                  </div>
                </div>
                {plat.badge && (
                  <span className={`text-xs px-2.5 py-1 rounded-full font-semibold ${
                    isSelected
                      ? 'bg-white/10 text-white'
                      : plat.disabled
                        ? 'bg-gray-100 dark:bg-slate-800 text-gray-400 dark:text-slate-500'
                        : 'bg-green-100 dark:bg-green-950 text-green-600 dark:text-green-400'
                  }`}>
                    {plat.badge}
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {/* Right Column: Download Card */}
        <div className="lg:col-span-2 flex flex-col justify-between bg-white dark:bg-slate-900 p-8 md:p-10 rounded-3xl shadow-xl border border-gray-100 dark:border-slate-800">
          <div className="space-y-6">
            <div className="flex items-center justify-between">
              <div className="p-4 bg-institutional-50 dark:bg-institutional-950/50 rounded-2xl text-institutional-600 dark:text-institutional-400 inline-block">
                {activePlatform.icon}
              </div>
              {activePlatform.disabled && (
                <span className="bg-amber-50 dark:bg-amber-950/30 text-amber-600 dark:text-amber-400 border border-amber-100 dark:border-amber-900/30 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider">
                  Desarrollo en Progreso
                </span>
              )}
            </div>

            <div>
              <h3 className="text-3xl font-extrabold text-gray-900 dark:text-white mb-2">
                NEXO para {activePlatform.label}
              </h3>
              <p className="text-gray-500 dark:text-slate-400 text-base md:text-lg">
                {activePlatform.description}
              </p>
            </div>

            {/* Android Specific Instruction Badge */}
            {!activePlatform.disabled && activePlatform.id === 'android' && (
              <div className="flex items-start gap-3 p-4 bg-blue-50 dark:bg-slate-800/50 rounded-2xl border border-blue-100 dark:border-slate-800 text-sm text-blue-800 dark:text-slate-300">
                <Info className="w-5 h-5 shrink-0 mt-0.5 text-blue-600 dark:text-blue-400" />
                <div>
                  <span className="font-bold block mb-0.5">Instrucciones de Instalación:</span>
                  <span>Al finalizar la descarga, abre el archivo APK. Si tu navegador lo solicita, permite la instalación de aplicaciones desde fuentes desconocidas en la configuración de seguridad.</span>
                </div>
              </div>
            )}
          </div>

          <div className="mt-8 pt-6 border-t border-gray-100 dark:border-slate-800 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div className="text-center sm:text-left">
              <span className="text-xs text-gray-400 dark:text-slate-500 block">Licencia</span>
              <span className="text-sm font-bold text-gray-700 dark:text-slate-300">Gratuita y Segura</span>
            </div>

            {activePlatform.disabled ? (
              <button
                disabled
                className="w-full sm:w-auto inline-flex items-center justify-center gap-3 bg-gray-200 dark:bg-slate-800 text-gray-400 dark:text-slate-500 px-8 py-4 rounded-2xl font-black uppercase cursor-not-allowed"
              >
                No Disponible
              </button>
            ) : (
              <a
                href={activePlatform.url}
                download={activePlatform.filename}
                className="w-full sm:w-auto inline-flex items-center justify-center gap-3 bg-institutional-900 text-white hover:bg-institutional-800 active:scale-95 px-8 py-4 rounded-2xl font-black uppercase transition-all shadow-lg shadow-institutional-900/20"
              >
                <Download size={20} />
                Comenzar Descarga
              </a>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default Downloads;
