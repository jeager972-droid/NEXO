/**
 * WebApp entry / NEXO Institucional
 * Punto de entrada: monta la SPA, registra el service worker y provee contextos.
 */
import '@fontsource-variable/inter';
import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { registerSW } from 'virtual:pwa-register';
import App from './App.jsx';
import './index.css';
import { AuthProvider } from './context/AuthContext';
import { ThemeProvider } from './context/ThemeContext';

// Limpieza segura de caches antiguas de Workbox.
// No desregistramos el SW activo, solo borramos caches obsoletas
// para evitar que logos o assets viejos queden pegados.
if ('serviceWorker' in navigator && 'caches' in window) {
  window.addEventListener('load', () => {
    caches.keys().then((cacheNames) => {
      const validCaches = ['nexo-api-cache', 'nexo-hashed-assets', 'nexo-images', 'workbox-precache-v2'];
      cacheNames.forEach((name) => {
        if (!validCaches.includes(name)) {
          caches.delete(name).then((deleted) => {
            if (deleted) console.log('[NEXO] Removed stale cache:', name);
          });
        }
      });
    }).catch(() => {});
  });
}

// Registro del SW con autoUpdate. El SW de vite-plugin-pwa maneja
// su propio ciclo de vida. No forzamos unregister para evitar
// perder el cache offline en conexiones inestables.
registerSW({
  immediate: true,
  onRegistered(r) {
    if (r) {
      // Verificar actualizaciones cada hora
      setInterval(() => r.update().catch(() => {}), 60 * 60 * 1000);
    }
  },
});

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter basename="/app" future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
      <ThemeProvider>
        <AuthProvider>
          <App />
        </AuthProvider>
      </ThemeProvider>
    </BrowserRouter>
  </React.StrictMode>,
);
