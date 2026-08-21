import { describe, it, expect, vi, beforeEach, afterEach, beforeAll } from 'vitest';

// Mock axios: use vi.hoisted so the mock objects exist when the hoisted vi.mock factory runs.
const { mockInstance, mockAxiosPost, mockAxiosCreate } = vi.hoisted(() => {
  // axios instance is callable: `client(config)`. Make it a function with methods.
  const mockInstance = vi.fn();
  mockInstance.get = vi.fn();
  mockInstance.post = vi.fn();
  mockInstance.put = vi.fn();
  mockInstance.patch = vi.fn();
  mockInstance.delete = vi.fn();
  mockInstance.interceptors = {
    request: { use: vi.fn() },
    response: { use: vi.fn() },
  };
  const mockAxiosPost = vi.fn();
  const mockAxiosCreate = vi.fn(() => mockInstance);
  return { mockInstance, mockAxiosPost, mockAxiosCreate };
});

vi.mock('axios', () => ({
  default: { create: mockAxiosCreate, post: mockAxiosPost, get: vi.fn() },
  create: mockAxiosCreate,
}));

vi.mock('@/utils/jwt', () => ({
  isTokenExpiringSoon: vi.fn(() => false),
}));

import axios from 'axios';
import { isTokenExpiringSoon } from '@/utils/jwt';
import client from '@/api/client';

let requestFulfilled;
let requestRejected;
let responseFulfilled;
let responseRejected;

beforeAll(() => {
  requestFulfilled = mockInstance.interceptors.request.use.mock.calls[0][0];
  requestRejected = mockInstance.interceptors.request.use.mock.calls[0][1];
  responseFulfilled = mockInstance.interceptors.response.use.mock.calls[0][0];
  responseRejected = mockInstance.interceptors.response.use.mock.calls[0][1];
});

beforeEach(() => {
  localStorage.clear();
  // Clear call history on network mocks without wiping interceptor.use call records.
  mockInstance.get.mockClear();
  mockInstance.post.mockClear();
  mockInstance.put.mockClear();
  mockInstance.patch.mockClear();
  mockInstance.delete.mockClear();
  mockAxiosPost.mockClear();
  // Reset jwt helper to default implementation.
  isTokenExpiringSoon.mockReturnValue(false);
  // Reset location to a neutral path.
  window.history.replaceState({}, '', '/');
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('client (axios instance)', () => {
  it('creates an axios instance with baseURL, withCredentials and default timeout', () => {
    expect(axios.create).toHaveBeenCalledTimes(1);
    const config = axios.create.mock.calls[0][0];
    expect(config.baseURL).toBe('');
    expect(config.withCredentials).toBe(true);
    expect(config.timeout).toBe(25000);
    expect(config.headers['X-Requested-With']).toBe('XMLHttpRequest');
  });

  it('registers request and response interceptors', () => {
    expect(mockInstance.interceptors.request.use).toHaveBeenCalledTimes(1);
    expect(mockInstance.interceptors.response.use).toHaveBeenCalledTimes(1);
  });

  it('exports the created instance as default', () => {
    expect(client).toBe(mockInstance);
  });
});

describe('request interceptor', () => {
  it('sets adaptive timeout for slow routes (/operations/)', () => {
    const config = { url: '/operations/bulk', method: 'post' };
    expect(requestFulfilled(config).timeout).toBe(45000);
  });

  it('sets adaptive timeout for slow routes (/reports/)', () => {
    const config = { url: '/reports/annual', method: 'get' };
    expect(requestFulfilled(config).timeout).toBe(45000);
  });

  it('keeps default timeout for normal routes', () => {
    const config = { url: '/devices', method: 'get' };
    expect(requestFulfilled(config).timeout).toBeUndefined();
  });

  it('records a telemetry timestamp _t0', () => {
    const result = requestFulfilled({ url: '/devices' });
    expect(result._t0).toEqual(expect.any(Number));
  });

  it('attaches Authorization header when token present in localStorage', () => {
    localStorage.setItem('nexo:auth-token', 'abc123');
    const result = requestFulfilled({ url: '/devices', headers: {} });
    expect(result.headers.Authorization).toBe('Bearer abc123');
  });

  it('does not attach Authorization when no token present', () => {
    const result = requestFulfilled({ url: '/devices', headers: {} });
    expect(result.headers.Authorization).toBeUndefined();
  });

  it('dispatches nexo:token-check event when token is expiring soon', () => {
    isTokenExpiringSoon.mockReturnValue(true);
    localStorage.setItem('nexo:auth-token', 'expiring');
    const spy = vi.spyOn(window, 'dispatchEvent');
    requestFulfilled({ url: '/devices', headers: {} });
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:token-check' }));
    expect(isTokenExpiringSoon).toHaveBeenCalledWith('expiring', 5);
  });

  it('does not dispatch token-check when token not expiring', () => {
    isTokenExpiringSoon.mockReturnValue(false);
    localStorage.setItem('nexo:auth-token', 'fresh');
    const spy = vi.spyOn(window, 'dispatchEvent');
    requestFulfilled({ url: '/devices', headers: {} });
    expect(spy).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:token-check' }));
  });

  it('rejects on request error', async () => {
    const err = new Error('boom');
    await expect(requestRejected(err)).rejects.toBe(err);
  });
});

describe('response interceptor - success', () => {
  it('passes through response and emits latency telemetry', () => {
    const spy = vi.spyOn(window, 'dispatchEvent');
    const response = { config: { _t0: performance.now(), url: '/devices', method: 'get' }, status: 200, data: {} };
    expect(responseFulfilled(response)).toBe(response);
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:telemetry' }));
  });

  it('skips telemetry when _t0 missing', () => {
    const spy = vi.spyOn(window, 'dispatchEvent');
    responseFulfilled({ config: {}, status: 200, data: {} });
    expect(spy).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:telemetry' }));
  });
});

describe('response interceptor - error handling', () => {
  it('dispatches nexo:forbidden on 403 with url and status detail', async () => {
    const spy = vi.spyOn(window, 'dispatchEvent');
    const error = { config: { url: '/devices' }, response: { status: 403 } };
    await expect(responseRejected(error)).rejects.toBe(error);
    const event = spy.mock.calls.find((c) => c[0]?.type === 'nexo:forbidden')?.[0];
    expect(event).toBeInstanceOf(CustomEvent);
    expect(event.detail).toEqual({ url: '/devices', status: 403 });
  });

  it('dispatches nexo:network-error on network failure (no response)', async () => {
    const spy = vi.spyOn(window, 'dispatchEvent');
    const error = { config: { url: '/devices' }, code: 'ERR_NETWORK' };
    await expect(responseRejected(error)).rejects.toBe(error);
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:network-error' }));
  });

  it('dispatches nexo:network-error when response is missing', async () => {
    const spy = vi.spyOn(window, 'dispatchEvent');
    const error = { config: { url: '/devices' } };
    await expect(responseRejected(error)).rejects.toBe(error);
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:network-error' }));
  });

  it('does not dispatch auth-logout on 401 for /auth/login endpoint', async () => {
    const spy = vi.spyOn(window, 'dispatchEvent');
    const error = { config: { url: '/auth/login' }, response: { status: 401 } };
    await expect(responseRejected(error)).rejects.toBe(error);
    expect(spy).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:auth-logout' }));
  });

  it('dispatches nexo:auth-logout on 401 for non-auth route when no refresh token', async () => {
    window.history.replaceState({}, '', '/app/dashboard');
    const spy = vi.spyOn(window, 'dispatchEvent');
    const error = { config: { url: '/devices', headers: {}, method: 'get' }, response: { status: 401 } };
    await expect(responseRejected(error)).rejects.toBe(error);
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:auth-logout' }));
  });

  it('does not dispatch auth-logout on 401 when on a public route', async () => {
    window.history.replaceState({}, '', '/app/login');
    const spy = vi.spyOn(window, 'dispatchEvent');
    const error = { config: { url: '/devices', headers: {}, method: 'get' }, response: { status: 401 } };
    await expect(responseRejected(error)).rejects.toBe(error);
    expect(spy).not.toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:auth-logout' }));
  });
});

describe('response interceptor - 401 refresh flow', () => {
  it('attempts token refresh using refresh token, stores new tokens, and retries original request', async () => {
    localStorage.setItem('nexo:auth-refresh-token', 'rtok');
    mockAxiosPost.mockResolvedValueOnce({ data: { token: 'newtok', refresh_token: 'newrt' } });
    // The retry calls the instance as a function: client(originalRequest).
    mockInstance.mockResolvedValueOnce({ data: { ok: true } });

    const error = {
      config: { url: '/devices', headers: {}, method: 'get' },
      response: { status: 401 },
    };
    const result = await responseRejected(error);

    expect(mockAxiosPost).toHaveBeenCalledWith(
      expect.stringContaining('/auth/refresh'),
      { refresh_token: 'rtok' },
      expect.objectContaining({ withCredentials: true, timeout: 10000 })
    );
    expect(localStorage.getItem('nexo:auth-token')).toBe('newtok');
    expect(localStorage.getItem('nexo:auth-refresh-token')).toBe('newrt');
    // Original request retried via instance call with new Authorization header.
    expect(mockInstance).toHaveBeenCalledWith(
      expect.objectContaining({ url: '/devices', headers: expect.objectContaining({ Authorization: 'Bearer newtok' }) })
    );
    expect(result).toEqual({ data: { ok: true } });
  });

  it('dispatches auth-logout when refresh call fails', async () => {
    window.history.replaceState({}, '', '/app/dashboard');
    localStorage.setItem('nexo:auth-refresh-token', 'rtok');
    mockAxiosPost.mockRejectedValueOnce(new Error('refresh failed'));
    const spy = vi.spyOn(window, 'dispatchEvent');

    const error = {
      config: { url: '/devices', headers: {}, method: 'get' },
      response: { status: 401 },
    };
    await expect(responseRejected(error)).rejects.toBe(error);
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({ type: 'nexo:auth-logout' }));
  });
});
