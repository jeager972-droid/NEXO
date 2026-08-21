import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  requestNotificationPermission,
  requestCameraPermission,
  isWebAuthnSupported,
} from '@/utils/mobilePermissions';

describe('requestNotificationPermission', () => {
  beforeEach(() => {
    delete window.Notification;
  });
  afterEach(() => {
    delete window.Notification;
  });

  it('returns "unsupported" when Notification API is absent', async () => {
    const result = await requestNotificationPermission();
    expect(result).toBe('unsupported');
  });

  it('returns "granted" when permission is already granted', async () => {
    window.Notification = { permission: 'granted', requestPermission: vi.fn() };
    const result = await requestNotificationPermission();
    expect(result).toBe('granted');
    expect(window.Notification.requestPermission).not.toHaveBeenCalled();
  });

  it('requests permission when not already granted', async () => {
    window.Notification = {
      permission: 'default',
      requestPermission: vi.fn().mockResolvedValue('granted'),
    };
    const result = await requestNotificationPermission();
    expect(result).toBe('granted');
    expect(window.Notification.requestPermission).toHaveBeenCalled();
  });

  it('returns "denied" when requestPermission throws', async () => {
    window.Notification = {
      permission: 'default',
      requestPermission: vi.fn().mockRejectedValue(new Error('blocked')),
    };
    const result = await requestNotificationPermission();
    expect(result).toBe('denied');
  });
});

describe('requestCameraPermission', () => {
  afterEach(() => {
    delete navigator.mediaDevices;
  });

  it('returns unsupported when mediaDevices is absent', async () => {
    delete navigator.mediaDevices;
    const result = await requestCameraPermission();
    expect(result).toEqual({ ok: false, reason: 'unsupported' });
  });

  it('returns ok:true when getUserMedia succeeds and stops tracks', async () => {
    const stop = vi.fn();
    const stream = { getTracks: () => [{ stop }, { stop }] };
    navigator.mediaDevices = {
      getUserMedia: vi.fn().mockResolvedValue(stream),
    };
    const result = await requestCameraPermission();
    expect(result).toEqual({ ok: true });
    expect(stop).toHaveBeenCalledTimes(2);
  });

  it('returns denied when getUserMedia rejects', async () => {
    navigator.mediaDevices = {
      getUserMedia: vi.fn().mockRejectedValue(new Error('denied')),
    };
    const result = await requestCameraPermission();
    expect(result).toEqual({ ok: false, reason: 'denied' });
  });
});

describe('isWebAuthnSupported', () => {
  afterEach(() => {
    delete window.PublicKeyCredential;
  });

  it('returns false when PublicKeyCredential is absent', () => {
    delete window.PublicKeyCredential;
    expect(isWebAuthnSupported()).toBe(false);
  });

  it('returns true when PublicKeyCredential is present', () => {
    window.PublicKeyCredential = class {};
    expect(isWebAuthnSupported()).toBe(true);
  });
});
