import { describe, it, expect, vi, beforeEach } from 'vitest';

// Mock the client module before importing authApi
vi.mock('../../api/client', () => ({
  default: {
    post: vi.fn(),
    get: vi.fn(),
  },
}));

import client from '../../api/client';
import { authApi } from '../../api/auth';

describe('authApi', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('login', () => {
    it('calls POST /auth/login with email, password and action', async () => {
      client.post.mockResolvedValue({ data: { user: { email: 'admin@nexo.edu' } } });

      const result = await authApi.login('admin@nexo.edu', 'admin123');

      expect(client.post).toHaveBeenCalledWith('/auth/login', {
        email: 'admin@nexo.edu',
        password: 'admin123',
        action: 'LOGIN',
      });
      expect(result.user.email).toBe('admin@nexo.edu');
    });

    it('trims email before sending', async () => {
      client.post.mockResolvedValue({ data: {} });

      await authApi.login('  admin@nexo.edu  ', 'admin123');

      expect(client.post).toHaveBeenCalledWith('/auth/login', expect.objectContaining({
        email: 'admin@nexo.edu',
      }));
    });
  });

  describe('verify2FA', () => {
    it('calls POST /auth/verify-2fa with email and code', async () => {
      client.post.mockResolvedValue({ data: { user: { email: 'admin@nexo.edu' } } });

      const result = await authApi.verify2FA('admin@nexo.edu', '123456');

      expect(client.post).toHaveBeenCalledWith('/auth/verify-2fa', {
        email: 'admin@nexo.edu',
        code: '123456',
      });
      expect(result.user.email).toBe('admin@nexo.edu');
    });
  });

  describe('logout', () => {
    it('calls POST /auth/logout', async () => {
      client.post.mockResolvedValue({ data: { status: 'ok' } });

      const result = await authApi.logout();

      expect(client.post).toHaveBeenCalledWith('/auth/logout');
      expect(result.status).toBe('ok');
    });
  });

  describe('getMe', () => {
    it('calls GET /auth/me', async () => {
      client.get.mockResolvedValue({ data: { user: { email: 'admin@nexo.edu', role: 'RECTOR' } } });

      const result = await authApi.getMe();

      expect(client.get).toHaveBeenCalledWith('/auth/me');
      expect(result.user.role).toBe('RECTOR');
    });
  });
});
