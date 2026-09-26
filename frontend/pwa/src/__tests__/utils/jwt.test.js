import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  decodeJwtPayload,
  getTokenExp,
  isTokenExpiringSoon,
  isTokenExpired,
} from '@/utils/jwt';

// Helper: build a JWT with a given payload (base64url, no signature validation).
// Encodes the payload as UTF-8 before base64 so unicode decodes correctly.
const makeToken = (payload) => {
  const header = btoa(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
  const utf8 = new TextEncoder().encode(JSON.stringify(payload));
  let binary = '';
  utf8.forEach((b) => (binary += String.fromCharCode(b)));
  const body = btoa(binary);
  return `${header}.${body}.signature`;
};

describe('decodeJwtPayload', () => {
  it('returns null for falsy or non-string input', () => {
    expect(decodeJwtPayload(null)).toBeNull();
    expect(decodeJwtPayload(undefined)).toBeNull();
    expect(decodeJwtPayload('')).toBeNull();
    expect(decodeJwtPayload(123)).toBeNull();
    expect(decodeJwtPayload({})).toBeNull();
  });

  it('returns null for a token with fewer than 2 parts', () => {
    expect(decodeJwtPayload('onlyonepart')).toBeNull();
  });

  it('decodes a valid JWT payload', () => {
    const token = makeToken({ sub: '123', role: 'admin' });
    expect(decodeJwtPayload(token)).toEqual({ sub: '123', role: 'admin' });
  });

  it('decodes payload with unicode characters', () => {
    const token = makeToken({ name: 'Ñoño' });
    expect(decodeJwtPayload(token).name).toBe('Ñoño');
  });

  it('returns null for invalid base64 payload', () => {
    expect(decodeJwtPayload('header.@@@.sig')).toBeNull();
  });
});

describe('getTokenExp', () => {
  it('returns the exp value when present', () => {
    const token = makeToken({ exp: 1700000000 });
    expect(getTokenExp(token)).toBe(1700000000);
  });

  it('returns null when exp is missing', () => {
    const token = makeToken({ sub: '1' });
    expect(getTokenExp(token)).toBeNull();
  });

  it('returns null when exp is not a number', () => {
    const token = makeToken({ exp: 'soon' });
    expect(getTokenExp(token)).toBeNull();
  });

  it('returns null for invalid token', () => {
    expect(getTokenExp('bad')).toBeNull();
  });
});

describe('isTokenExpiringSoon', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2024-01-01T00:00:00Z'));
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it('returns true when exp is within the window', () => {
    const now = Math.floor(Date.now() / 1000);
    const token = makeToken({ exp: now + 5 * 60 }); // 5 min ahead
    expect(isTokenExpiringSoon(token, 10)).toBe(true);
  });

  it('returns false when exp is beyond the window', () => {
    const now = Math.floor(Date.now() / 1000);
    const token = makeToken({ exp: now + 60 * 60 }); // 1 hour ahead
    expect(isTokenExpiringSoon(token, 10)).toBe(false);
  });

  it('uses default withinMinutes of 10', () => {
    const now = Math.floor(Date.now() / 1000);
    const token = makeToken({ exp: now + 9 * 60 });
    expect(isTokenExpiringSoon(token)).toBe(true);
  });

  it('returns false when token has no exp', () => {
    const token = makeToken({ sub: '1' });
    expect(isTokenExpiringSoon(token)).toBe(false);
  });

  it('returns false for invalid token', () => {
    expect(isTokenExpiringSoon('bad')).toBe(false);
  });
});

describe('isTokenExpired', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2024-01-01T00:00:00Z'));
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it('returns true when exp is in the past', () => {
    const now = Math.floor(Date.now() / 1000);
    const token = makeToken({ exp: now - 100 });
    expect(isTokenExpired(token)).toBe(true);
  });

  it('returns false when exp is in the future', () => {
    const now = Math.floor(Date.now() / 1000);
    const token = makeToken({ exp: now + 100 });
    expect(isTokenExpired(token)).toBe(false);
  });

  it('returns true when exp equals now', () => {
    const now = Math.floor(Date.now() / 1000);
    const token = makeToken({ exp: now });
    expect(isTokenExpired(token)).toBe(true);
  });

  it('returns false when token has no exp', () => {
    const token = makeToken({ sub: '1' });
    expect(isTokenExpired(token)).toBe(false);
  });

  it('returns false for invalid token', () => {
    expect(isTokenExpired('bad')).toBe(false);
  });
});
