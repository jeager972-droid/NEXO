/**
 * telemetry / NEXO Institucional
 * Responsabilidad: Recolectar eventos no sensibles (errores, latencia API, uso biométrico,
 * rendimiento de render y pings). Este módulo NO se auto-inicializa en App.jsx; el flush
 * periódico está deshabilitado para reducir requests al backend y uso de Redis. Se puede
 * invocar manualmente si se requiere diagnóstico puntual.
 * Dependencias: axios client.js, navegador APIs (PerformanceObserver, window.onerror, etc.).
 */
import client from './client.js';


// ── Configuración ──────────────────────────────────────────
const FLUSH_INTERVAL_MS = 5 * 60 * 1000; // 5 minutos
const MAX_QUEUE_SIZE    = 100;

const SESSION_ID  = crypto.randomUUID();
const APP_VERSION = import.meta.env.VITE_APP_VERSION ?? 'dev';

const queue = [];

// ── Detección de plataforma (sin PII) ─────────────────────
function getPlatform() {
  if (typeof window.__TAURI__ !== 'undefined') {
    const ua = navigator.userAgent;
    if (/android/i.test(ua)) return 'android';
    if (/iphone|ipad|ipod/i.test(ua)) return 'ios';
    return 'desktop';
  }
  return 'web';
}

// ── Sanitización de paths: /students/123 → /students/:id ──
function sanitizePath(url = '') {
  try {
    const path = new URL(url, 'http://x').pathname;
    return path.replace(/\/\d+/g, '/:id').slice(0, 120);
  } catch {
    return String(url).replace(/\/\d+/g, '/:id').slice(0, 120);
  }
}

// ── Funciones de tracking (exportadas para uso externo) ───
export function trackError(error, source = '') {
  if (queue.length >= MAX_QUEUE_SIZE) return;
  queue.push({
    type: 'JS_ERROR',
    severity: 'error',
    payload: {
      message: String(error?.message ?? error).slice(0, 300),
      type:    error?.name ?? 'Error',
      source:  String(source).slice(0, 80),
      lineno:  typeof error?.lineNumber === 'number' ? error.lineNumber : null,
    },
  });
}

export function trackLatency(path, method, status, durationMs) {
  if (queue.length >= MAX_QUEUE_SIZE) return;
  queue.push({
    type:     'API_LATENCY',
    severity: durationMs > 3000 ? 'warn' : 'info',
    payload: {
      path:        sanitizePath(path),
      method:      String(method).toUpperCase().slice(0, 10),
      status:      Number(status),
      duration_ms: Math.round(durationMs),
    },
  });
}

export function trackBiometric(durationMs, success) {
  if (queue.length >= MAX_QUEUE_SIZE) return;
  queue.push({
    type:     'BIOMETRIC_LATENCY',
    severity: success ? 'info' : 'warn',
    payload: {
      duration_ms: Math.round(durationMs),
      success:     Boolean(success),
    },
  });
}

export function trackRenderSlow(component, durationMs) {
  if (queue.length >= MAX_QUEUE_SIZE) return;
  queue.push({
    type:     'RENDER_SLOW',
    severity: 'warn',
    payload: {
      component:   String(component).slice(0, 80),
      duration_ms: Math.round(durationMs),
    },
  });
}

// ── Ping de vida (sin PII) ─────────────────────────────────
function buildPing() {
  const payload = {
    online:  navigator.onLine,
    screen:  `${window.screen.width}x${window.screen.height}`,
    tz:      Intl.DateTimeFormat().resolvedOptions().timeZone,
  };
  if (typeof performance?.memory !== 'undefined') {
    payload.memory_mb = Math.round(performance.memory.usedJSHeapSize / 1_048_576);
  }
  if (navigator.connection?.effectiveType) {
    payload.connection = navigator.connection.effectiveType;
  }
  return { type: 'APP_PING', severity: 'debug', payload };
}

// ── Flush: envía cola + ping al backend ───────────────────
export async function flushTelemetry() {
  // No enviar si estamos en rutas públicas
  const publicRoutes = ['/login', '/instalar', '/descargas', '/app/login'];
  if (publicRoutes.some(route => window.location.pathname.includes(route))) return;

  const events = [...queue, buildPing()];
  queue.length = 0; // limpiar cola antes del await (evita doble envío)

  try {
    await client.post('/telemetry', {
      session_id:  SESSION_ID,
      app_version: APP_VERSION,
      platform:    getPlatform(),
      events,
    });
  } catch {
    // Silencioso: la telemetría nunca interrumpe el flujo de la app
  }
}

// ── Inicialización: llama una vez desde App.jsx o main.jsx ─
export function initTelemetry() {
  const onTelemetry = (e) => {
    const { type, payload } = e.detail ?? {};
    if (type === 'API_LATENCY') {
      trackLatency(payload.path, payload.method, payload.status, payload.duration_ms);
    }
  };
  const onError = (e) => {
    trackError(e.error ?? { message: e.message, name: 'Error' }, e.filename ?? '');
  };
  const onUnhandledRejection = (e) => {
    trackError(
      e.reason instanceof Error ? e.reason : { message: String(e.reason), name: 'UnhandledRejection' },
      'promise'
    );
  };
  const onVisibilityChange = () => {
    if (document.visibilityState === 'hidden') flushTelemetry();
  };

  window.addEventListener('nexo:telemetry', onTelemetry);
  window.addEventListener('error', onError);
  window.addEventListener('unhandledrejection', onUnhandledRejection);
  window.addEventListener('visibilitychange', onVisibilityChange);

  const initialFlushTimer = setTimeout(flushTelemetry, 10_000);
  const flushInterval = setInterval(flushTelemetry, FLUSH_INTERVAL_MS);

  return function stopTelemetry() {
    clearTimeout(initialFlushTimer);
    clearInterval(flushInterval);
    window.removeEventListener('nexo:telemetry', onTelemetry);
    window.removeEventListener('error', onError);
    window.removeEventListener('unhandledrejection', onUnhandledRejection);
    window.removeEventListener('visibilitychange', onVisibilityChange);
  };
}
