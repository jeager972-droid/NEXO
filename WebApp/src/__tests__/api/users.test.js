import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { usersApi } from '../../api/users';

describe('usersApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getByRole calls GET /users/by-role with role and same_shift', async () => {
    client.get.mockResolvedValue({ data: { data: [{ id: '1' }] } });
    const result = await usersApi.getByRole('TEACHER', true);
    expect(client.get).toHaveBeenCalledWith('/users/by-role', {
      params: { role: 'TEACHER', same_shift: 1 },
    });
    expect(result.data).toHaveLength(1);
  });

  it('getByRole defaults same_shift to false (0)', async () => {
    client.get.mockResolvedValue({ data: { data: [] } });
    await usersApi.getByRole('TEACHER');
    expect(client.get).toHaveBeenCalledWith('/users/by-role', {
      params: { role: 'TEACHER', same_shift: 0 },
    });
  });

  it('getExtendedProfile calls GET /users/me/extended', async () => {
    client.get.mockResolvedValue({ data: { phone: '123' } });
    const result = await usersApi.getExtendedProfile();
    expect(client.get).toHaveBeenCalledWith('/users/me/extended');
    expect(result.phone).toBe('123');
  });

  it('uploadPhoto sends FormData with 30s timeout', async () => {
    client.post.mockResolvedValue({ data: { url: 'http://photo.png' } });
    const file = new File(['test'], 'photo.png', { type: 'image/png' });
    const result = await usersApi.uploadPhoto(file);
    expect(client.post).toHaveBeenCalledWith('/users/upload-photo', expect.any(FormData), { timeout: 30000 });
    expect(result.url).toBe('http://photo.png');
  });

  it('getMyPhoto calls GET /users/me/photo', async () => {
    client.get.mockResolvedValue({ data: { url: 'http://x.png' } });
    await usersApi.getMyPhoto();
    expect(client.get).toHaveBeenCalledWith('/users/me/photo');
  });

  it('sendVerificationCode calls POST /users/send-verification', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await usersApi.sendVerificationCode('phone_change', '+57123');
    expect(client.post).toHaveBeenCalledWith('/users/send-verification', {
      purpose: 'phone_change', target: '+57123',
    });
  });

  it('verifyCode calls POST /users/verify-code', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await usersApi.verifyCode('phone_change', '123456');
    expect(client.post).toHaveBeenCalledWith('/users/verify-code', {
      purpose: 'phone_change', code: '123456',
    });
  });

  it('updateProfile calls POST /users/update-profile', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await usersApi.updateProfile('phone', '+57123');
    expect(client.post).toHaveBeenCalledWith('/users/update-profile', {
      purpose: 'phone', value: '+57123',
    });
  });

  it('changePassword calls POST /users/change-password', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await usersApi.changePassword('old123', 'new123');
    expect(client.post).toHaveBeenCalledWith('/users/change-password', {
      current_password: 'old123', new_password: 'new123',
    });
  });

  it('resetPassword calls POST /users/reset-password', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await usersApi.resetPassword('code123', 'newpass');
    expect(client.post).toHaveBeenCalledWith('/users/reset-password', {
      code: 'code123', new_password: 'newpass',
    });
  });

  it('deleteField calls POST /users/delete-field', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await usersApi.deleteField('phone');
    expect(client.post).toHaveBeenCalledWith('/users/delete-field', { field: 'phone' });
  });

  it('returns empty object when response.data is null', async () => {
    client.get.mockResolvedValue({ data: null });
    const result = await usersApi.getExtendedProfile();
    expect(result).toEqual({});
  });
});
