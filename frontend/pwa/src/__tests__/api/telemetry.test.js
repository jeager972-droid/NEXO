import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import {
  trackError,
  trackLatency,
  trackBiometric,
  trackRenderSlow,
  flushTelemetry,
} from '../../api/telemetry';

describe('telemetry', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(window, 'location', 'get').mockRestore?.();
  });

  describe('trackError', () => {
    it('pushes JS_ERROR event to queue (flushed via flushTelemetry)', async () => {
      client.post.mockResolvedValue({ data: {} });
      trackError(new Error('Test error'), 'test.js');
      // flushTelemetry sends queued events
      // Set pathname to a non-public route
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await flushTelemetry();
      expect(client.post).toHaveBeenCalledWith('/telemetry', expect.objectContaining({
        events: expect.arrayContaining([
          expect.objectContaining({ type: 'JS_ERROR' }),
        ]),
      }));
    });
  });

  describe('trackLatency', () => {
    it('pushes API_LATENCY event with sanitized path', async () => {
      client.post.mockResolvedValue({ data: {} });
      trackLatency('https://api.nexo.edu/students/123', 'GET', 200, 150);
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await flushTelemetry();
      const call = client.post.mock.calls[0];
      const latencyEvent = call[1].events.find(e => e.type === 'API_LATENCY');
      expect(latencyEvent).toBeDefined();
      expect(latencyEvent.payload.path).toBe('/students/:id');
      expect(latencyEvent.payload.method).toBe('GET');
      expect(latencyEvent.payload.status).toBe(200);
      expect(latencyEvent.payload.duration_ms).toBe(150);
    });

    it('sets severity to warn when duration > 3000ms', async () => {
      client.post.mockResolvedValue({ data: {} });
      trackLatency('/students', 'GET', 200, 5000);
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await flushTelemetry();
      const call = client.post.mock.calls[0];
      const latencyEvent = call[1].events.find(e => e.type === 'API_LATENCY');
      expect(latencyEvent.severity).toBe('warn');
    });
  });

  describe('trackBiometric', () => {
    it('pushes BIOMETRIC_LATENCY event', async () => {
      client.post.mockResolvedValue({ data: {} });
      trackBiometric(300, true);
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await flushTelemetry();
      const call = client.post.mock.calls[0];
      const bioEvent = call[1].events.find(e => e.type === 'BIOMETRIC_LATENCY');
      expect(bioEvent).toBeDefined();
      expect(bioEvent.payload.success).toBe(true);
      expect(bioEvent.severity).toBe('info');
    });

    it('sets severity to warn when success is false', async () => {
      client.post.mockResolvedValue({ data: {} });
      trackBiometric(300, false);
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await flushTelemetry();
      const call = client.post.mock.calls[0];
      const bioEvent = call[1].events.find(e => e.type === 'BIOMETRIC_LATENCY');
      expect(bioEvent.severity).toBe('warn');
    });
  });

  describe('trackRenderSlow', () => {
    it('pushes RENDER_SLOW event', async () => {
      client.post.mockResolvedValue({ data: {} });
      trackRenderSlow('Dashboard', 2000);
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await flushTelemetry();
      const call = client.post.mock.calls[0];
      const renderEvent = call[1].events.find(e => e.type === 'RENDER_SLOW');
      expect(renderEvent).toBeDefined();
      expect(renderEvent.payload.component).toBe('Dashboard');
      expect(renderEvent.payload.duration_ms).toBe(2000);
    });
  });

  describe('flushTelemetry', () => {
    it('does not send on public routes', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/login' },
        writable: true,
      });
      await flushTelemetry();
      expect(client.post).not.toHaveBeenCalled();
    });

    it('does not send on /app/login', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/app/login' },
        writable: true,
      });
      await flushTelemetry();
      expect(client.post).not.toHaveBeenCalled();
    });

    it('does not send on /instalar', async () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/instalar' },
        writable: true,
      });
      await flushTelemetry();
      expect(client.post).not.toHaveBeenCalled();
    });

    it('sends on non-public routes', async () => {
      client.post.mockResolvedValue({ data: {} });
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await flushTelemetry();
      expect(client.post).toHaveBeenCalledTimes(1);
    });

    it('includes APP_PING event in flush', async () => {
      client.post.mockResolvedValue({ data: {} });
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await flushTelemetry();
      const call = client.post.mock.calls[0];
      const pingEvent = call[1].events.find(e => e.type === 'APP_PING');
      expect(pingEvent).toBeDefined();
      expect(pingEvent.payload.online).toBeDefined();
    });

    it('silently catches errors', async () => {
      client.post.mockRejectedValue(new Error('Network error'));
      Object.defineProperty(window, 'location', {
        value: { pathname: '/dashboard' },
        writable: true,
      });
      await expect(flushTelemetry()).resolves.not.toThrow();
    });
  });
});
