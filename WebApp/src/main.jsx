/**
 * WebApp entry / NEXO Institucional
 * Responsabilidad: Montar la SPA React en #root, registrar el Service Worker PWA,
 * limpiar SWs viejos y proveer ThemeProvider + AuthProvider bajo BrowserRouter con basename /app/.
 * Dependencias: React 18, react-dom/client, react-router-dom, virtual:pwa-register, Auth/Theme contexts.
 */
import '@fontsource-variable/inter'
import React from 'react'
import ReactDOM from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { registerSW } from 'virtual:pwa-register'
import App from './App.jsx'
import './index.css'
import { AuthProvider } from './context/AuthContext'
import { ThemeProvider } from './context/ThemeContext'

// FIX: Limpia service workers viejos/bloqueados para evitar pantalla en blanco por cache PWA stale
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.getRegistrations().then(regs => {
    regs.forEach(r => {
      // Si el SW no puede responder con un fetch válido, desregistrarlo
      r.update().catch(() => {
        r.unregister().then(() => console.log('[NEXO] Stale SW unregistered'));
      });
    });
  });
}

registerSW({ immediate: true })

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
)
