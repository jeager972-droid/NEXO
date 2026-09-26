import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../api/client', () => ({
  default: { get: vi.fn(), post: vi.fn() },
}));

import client from '../../api/client';
import { operationsApi } from '../../api/operations';

describe('operationsApi', () => {
  beforeEach(() => vi.clearAllMocks());

  it('execute calls POST /operations/:command with wrapped payload', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await operationsApi.execute('sos', { student_id: '123' });
    expect(client.post).toHaveBeenCalledWith('/operations/sos', {
      action: 'EXECUTE_COMMAND',
      command: 'sos',
      params: { student_id: '123' },
    });
  });

  it('execute throws when status is not ok', async () => {
    client.post.mockResolvedValue({ data: { status: 'error', message: 'No permitido' } });
    await expect(operationsApi.execute('sos', {})).rejects.toThrow('No permitido');
  });

  it('execute throws default message when no message in payload', async () => {
    client.post.mockResolvedValue({ data: { status: 'error' } });
    await expect(operationsApi.execute('sos', {})).rejects.toThrow('Error en la operación institucional');
  });

  it('sos calls execute with sos command and path', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await operationsApi.sos({ student_id: '1' });
    expect(client.post).toHaveBeenCalledWith('/operations/sos', expect.objectContaining({
      command: 'sos',
    }));
  });

  it('citacion calls execute with citacion command', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await operationsApi.citacion({ student_id: '1' });
    expect(client.post).toHaveBeenCalledWith('/operations/citacion', expect.objectContaining({
      command: 'citacion',
    }));
  });

  it('salida calls execute with autorizar_salida command', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await operationsApi.salida({ student_id: '1' });
    expect(client.post).toHaveBeenCalledWith('/operations/salida', expect.objectContaining({
      command: 'autorizar_salida',
    }));
  });

  it('permiso calls execute with permiso command', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await operationsApi.permiso({ student_id: '1' });
    expect(client.post).toHaveBeenCalledWith('/operations/permiso', expect.objectContaining({
      command: 'permiso',
    }));
  });

  it('checkTwilioStatus calls POST /operations/twilio-status', async () => {
    client.post.mockResolvedValue({ data: { status: 'ok' } });
    await operationsApi.checkTwilioStatus(['msg1', 'msg2']);
    expect(client.post).toHaveBeenCalledWith('/operations/twilio-status', { message_ids: ['msg1', 'msg2'] });
  });
});
