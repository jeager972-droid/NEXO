import axios from 'axios';


// Base URL: VITE_API_BASE_URL debe apuntar al backend (sin /v1 trailing).
// El backend normaliza rutas via cleanPath, así que usamos la raíz.
const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL ||
  'https://nexo-production-13c0.up.railway.app/v1';

if (import.meta.env.PROD && !API_BASE_URL.startsWith('https://')) {
  throw new Error('VITE_API_BASE_URL debe usar HTTPS en producción');
}

// Rutas que pueden tardar más (envío masivo de WhatsApp, reportes, etc.)
const SLOW_ROUTE_PATTERNS = ['/operations/', '/reports/'];
const DEFAULT_TIMEOUT = 25000;
const SLOW_TIMEOUT = 45000;

const client = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'X-Requested-With': 'XMLHttpRequest', // FIX: CSRF protection header
  },
  timeout: DEFAULT_TIMEOUT,
  withCredentials: true, // PILAR 2.2: Enviar cookies HttpOnly automáticamente
});

// Interceptor de request: timeout adaptativo + timestamp para telemetría + iOS ITP fallback
client.interceptors.request.use(
  (config) => {
    const url = config.url || '';
    if (SLOW_ROUTE_PATTERNS.some((p) => url.includes(p))) {
      config.timeout = SLOW_TIMEOUT;
    }
    config._t0 = performance.now();

    return config;
  },
  (error) => Promise.reject(error)
);

function emitLatency(config, status) {
  if (!config?._t0) return;
  const duration_ms = Math.round(performance.now() - config._t0);
  window.dispatchEvent(
    new CustomEvent('nexo:telemetry', {
      detail: {
        type: 'API_LATENCY',
        payload: {
          path:        config.url ?? '',
          method:      (config.method ?? 'GET').toUpperCase(),
          status,
          duration_ms,
        },
      },
    })
  );
}

// Interceptor de response: telemetría de latencia + manejo de errores globales
client.interceptors.response.use(
  (response) => {
    emitLatency(response.config, response.status);
    return response;
  },
  (error) => {
    emitLatency(error.config, error.response?.status ?? 0);
    if (error.response?.status === 401) {
      // BUGFIX: La app vive en /app/ (basename). Sin ello el redirect causa 404 en Vercel.
      const publicRoutes = ['/app/login', '/login', '/instalar/', '/descargas'];
      const currentPath = window.location.pathname;
      const isPublicRoute = publicRoutes.some(route => currentPath.startsWith(route) || currentPath.includes(route));
      const isAuthRequest = error.config?.url?.includes('/auth/login') || error.config?.url?.includes('/auth/verify-2fa');

      if (!isAuthRequest && !isPublicRoute) {
        window.location.href = '/app/login';
      }
    }
    return Promise.reject(error);
  }
);

export default client;
