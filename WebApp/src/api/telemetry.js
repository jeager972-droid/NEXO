import client from './client.js';
import { userStore } from '../store/userStore';

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
  // No enviar si no hay sesión activa (usuario no logueado)
  if (!userStore.get()) return;

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
  // Escuchar eventos de latencia emitidos por client.js (sin circular dep)
  window.addEventListener('nexo:telemetry', (e) => {
    const { type, payload } = e.detail ?? {};
    if (type === 'API_LATENCY') {
      trackLatency(payload.path, payload.method, payload.status, payload.duration_ms);
    }
  });

  // Captura global de errores JS no manejados
  window.addEventListener('error', (e) => {
    trackError(e.error ?? { message: e.message, name: 'Error' }, e.filename ?? '');
  });
  window.addEventListener('unhandledrejection', (e) => {
    trackError(
      e.reason instanceof Error ? e.reason : { message: String(e.reason), name: 'UnhandledRejection' },
      'promise'
    );
  });

  // Flush inicial (diferido 10 s para no competir con el arranque)
  setTimeout(flushTelemetry, 10_000);

  // Flush periódico cada 5 minutos
  setInterval(flushTelemetry, FLUSH_INTERVAL_MS);

  // Flush al cerrar la pestaña (best-effort)
  window.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flushTelemetry();
  });
}
