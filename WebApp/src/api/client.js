import axios from 'axios';

// Base URL: VITE_API_BASE_URL debe apuntar al backend (sin /v1 trailing).
// El backend normaliza rutas via cleanPath, así que usamos la raíz.
const API_BASE_URL =
  import.meta.env.VITE_API_BASE_URL ||
  'https://nexo-production-f0ef.up.railway.app/v1';

if (import.meta.env.PROD && !API_BASE_URL.startsWith('https://')) {
  throw new Error('VITE_API_BASE_URL debe usar HTTPS en producción');
}

// Rutas que pueden tardar más (envío masivo de WhatsApp, reportes, etc.)
const SLOW_ROUTE_PATTERNS = ['/operations/', '/reports/'];
const DEFAULT_TIMEOUT = 15000;
const SLOW_TIMEOUT = 45000;

const client = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest', // FIX: CSRF protection header
  },
  timeout: DEFAULT_TIMEOUT,
  withCredentials: true, // PILAR 2.2: Enviar cookies HttpOnly automáticamente
});

// Interceptor para ajustar timeout por ruta
client.interceptors.request.use(
  (config) => {
    const url = config.url || '';
    if (SLOW_ROUTE_PATTERNS.some((p) => url.includes(p))) {
      config.timeout = SLOW_TIMEOUT;
    }
    return config;
  },
  (error) => Promise.reject(error)
);

// Interceptor para manejar errores globales (ej. 401 Unauthorized)
client.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('user');
      if (window.location.pathname !== '/login') {
        window.location.href = '/login';
      }
    }
    return Promise.reject(error);
  }
);

export default client;
