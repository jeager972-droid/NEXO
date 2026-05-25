import { useState, useEffect } from 'react';
import { Download, Monitor, Apple, Smartphone } from 'lucide-react';

const Downloads = () => {
  const [os, setOs] = useState('unknown');

  useEffect(() => {
    const userAgent = window.navigator.userAgent.toLowerCase();
    if (userAgent.includes('win')) setOs('windows');
    else if (userAgent.includes('mac')) setOs('macos');
    else if (userAgent.includes('linux')) setOs('linux');
    else if (userAgent.includes('android')) setOs('android');
    else if (userAgent.includes('iphone') || userAgent.includes('ipad')) setOs('ios');
  }, []);

  const getDownloadLink = () => {
    switch (os) {
      case 'windows': return { url: null, icon: <Monitor />, label: 'Windows - Próximamente', disabled: true };
      case 'macos': return { url: null, icon: <Apple />, label: 'macOS - Próximamente', disabled: true };
      case 'linux': return { url: null, icon: <Monitor />, label: 'Linux - Próximamente', disabled: true };
      case 'android': return { url: '/app/downloads/nexo.apk', icon: <Smartphone />, label: 'Descargar APK Android', disabled: false };
      case 'ios': return { url: null, icon: <Smartphone />, label: 'iOS - Próximamente', disabled: true };
      default: return { url: null, icon: <Download />, label: 'Selecciona tu plataforma', disabled: true };
    }
  };

  const activeDownload = getDownloadLink();

  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-gray-50 dark:bg-slate-950 p-6">
      <h1 className="text-4xl font-black text-institutional-900 dark:text-white uppercase tracking-tight mb-4">
        Descarga NEXO Institucional
      </h1>
      <p className="text-gray-500 mb-12 text-center max-w-lg">
        La aplicación nativa incluye soporte para biometría local, notificaciones nativas y modo offline de contingencia.
      </p>

      <div className="bg-white dark:bg-slate-900 p-10 rounded-3xl shadow-xl text-center border border-gray-100 dark:border-slate-800">
        <div className="flex justify-center mb-6 text-institutional-600">
          {activeDownload.icon}
        </div>
        <h2 className="text-2xl font-bold mb-6 dark:text-white">
          Sistema detectado: <span className="capitalize">{os}</span>
        </h2>
        {activeDownload.disabled ? (
          <button
            disabled
            className="inline-flex items-center gap-3 bg-gray-400 text-white px-8 py-4 rounded-2xl font-black uppercase cursor-not-allowed opacity-50"
          >
            <Download size={20} />
            {activeDownload.label}
          </button>
        ) : (
          <a 
            href={activeDownload.url}
            download="nexo.apk"
            className="inline-flex items-center gap-3 bg-institutional-900 text-white px-8 py-4 rounded-2xl font-black uppercase hover:scale-105 transition-all"
          >
            <Download size={20} />
            {activeDownload.label}
          </a>
        )}
      </div>
    </div>
  );
};

export default Downloads;
