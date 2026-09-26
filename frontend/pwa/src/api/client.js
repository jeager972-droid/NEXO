/**
 * API client / NEXO Institucional
 * Responsabilidad: Instancia global de Axios con baseURL, envío automático de cookies
 * HttpOnly, CSRF header, timeout adaptativo para rutas lentas, interceptores de
 * telemetría y manejo global de 401 (redirige a /login).
 * Dependencias: axios.
 */
import axios from 'axios';
import { isTokenExpiringSoon } from '../utils/jwt';


// Base URL: VITE_API_BASE_URL debe apuntar al backend (sin /v1 trailing).
// El backend normaliza rutas via cleanPath, así que usamos la raíz.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || '';

if (import.meta.env.PROD && !API_BASE_URL.startsWith('https://')) {
  throw new Error('VITE_API_BASE_URL debe usar HTTPS en producción');
}

// Rutas que pueden tardar más (envío masivo de WhatsApp, reportes, etc.)
const SLOW_ROUTE_PATTERNS = ['/operations/', '/reports/'];
const DEFAULT_TIMEOUT = 12000;
const SLOW_TIMEOUT = 30000;

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

    // TEMPORAL ITP WORKAROUND: token en localStorage para iOS/Safari donde ITP bloquea cookies cross-site.
    // La cookie HttpOnly sigue funcionando en navegadores que la permiten; este header es fallback.
    // TODO: Cuando frontend y backend estén en same-site, eliminar esto y volver a usar solo cookie HttpOnly.
    const token = localStorage.getItem('nexo:auth-token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
      // Prevent infinite loop: do not trigger token-check if this request is already for refreshing
      const isAuthReq = config.url?.includes('/auth/refresh') || config.url?.includes('/auth/login');
      if (!isAuthReq && !isRefreshing && isTokenExpiringSoon(token, 5)) {
        window.dispatchEvent(new CustomEvent('nexo:token-check'));
      }
    }

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
// VF-009: Auto-refresh en 401 antes de dispatch logout
let isRefreshing = false;
let refreshSubscribers = [];

const subscribeTokenRefresh = (cb) => refreshSubscribers.push(cb);
const onRefreshed = (token) => {
  refreshSubscribers.forEach((cb) => cb(token));
  refreshSubscribers = [];
};

client.interceptors.response.use(
  (response) => {
    emitLatency(response.config, response.status);
    return response;
  },
  async (error) => {
    emitLatency(error.config, error.response?.status ?? 0);
    const status = error.response?.status;
    const originalRequest = error.config;

    if (status === 401 && originalRequest && !originalRequest._retry
        && !originalRequest.url?.includes('/auth/refresh')
        && !originalRequest.url?.includes('/auth/login')
        && !originalRequest.url?.includes('/auth/verify-2fa')) {
      if (isRefreshing) {
        // Si ya hay un refresh en curso, esperar a que termine
        return new Promise((resolve, reject) => {
          subscribeTokenRefresh((newToken) => {
            if (newToken) {
              originalRequest.headers.Authorization = `Bearer ${newToken}`;
              resolve(client(originalRequest));
            } else {
              reject(error);
            }
          });
        });
      }

      originalRequest._retry = true;
      isRefreshing = true;

      const refreshToken = localStorage.getItem('nexo:auth-refresh-token');
      if (refreshToken) {
        try {
          const refreshResponse = await axios.post(
            `${API_BASE_URL}/auth/refresh`,
            { refresh_token: refreshToken },
            { withCredentials: true, timeout: 10000 }
          );
          const data = refreshResponse.data;
          if (data?.token) {
            localStorage.setItem('nexo:auth-token', data.token);
            if (data.refresh_token) localStorage.setItem('nexo:auth-refresh-token', data.refresh_token);
            isRefreshing = false;
            onRefreshed(data.token);
            originalRequest.headers.Authorization = `Bearer ${data.token}`;
            return client(originalRequest);
          }
        } catch (refreshError) {
          isRefreshing = false;
          onRefreshed(null);
          localStorage.removeItem('nexo:auth-token');
          localStorage.removeItem('nexo:auth-refresh-token');
          window.dispatchEvent(new CustomEvent('nexo:auth-logout'));
          return Promise.reject(error);
        }
      }

      isRefreshing = false;
      const publicRoutes = ['/app/login', '/login', '/instalar/', '/descargas'];
      const currentPath = window.location.pathname;
      const isPublicRoute = publicRoutes.some(route => currentPath.startsWith(route) || currentPath.includes(route));
      if (!isPublicRoute) {
        window.dispatchEvent(new CustomEvent('nexo:auth-logout'));
      }
    } else if (status === 401) {
      const publicRoutes = ['/app/login', '/login', '/instalar/', '/descargas'];
      const currentPath = window.location.pathname;
      const isPublicRoute = publicRoutes.some(route => currentPath.startsWith(route) || currentPath.includes(route));
      const isAuthRequest = error.config?.url?.includes('/auth/login') || error.config?.url?.includes('/auth/verify-2fa') || error.config?.url?.includes('/auth/me');

      if (!isAuthRequest && !isPublicRoute) {
        window.dispatchEvent(new CustomEvent('nexo:auth-logout'));
      }
    } else if (status === 403) {
      window.dispatchEvent(new CustomEvent('nexo:forbidden', {
        detail: { url: error.config?.url ?? '', status: 403 },
      }));
    } else if (!error.response || error.code === 'ERR_NETWORK') {
      window.dispatchEvent(new CustomEvent('nexo:network-error', {
        detail: { url: error.config?.url ?? '' },
      }));
    }
    return Promise.reject(error);
  }
);

export default client;
