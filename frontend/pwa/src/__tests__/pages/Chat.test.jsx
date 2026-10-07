import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import Chat from '@/pages/Chat';
import { chatApi } from '@/api/chat';

vi.mock('@/hooks/useAuth', () => {
  const mockValue = { user: { role: 'RECTOR', user_id: 'u1' }, loading: false };
  return { useAuth: () => mockValue };
});

vi.mock('@/api/chat', () => ({
  chatApi: {
    send: vi.fn(),
    history: vi.fn().mockResolvedValue([]),
    sessions: vi.fn().mockResolvedValue([]),
  },
}));

// jsdom no implementa scrollTo — Chat lo usa para anclar el hilo al fondo
if (!Element.prototype.scrollTo) Element.prototype.scrollTo = () => {};

const PENDING_KEY = 'nx:chat:pending';
const SESSION_KEY = 'nx:chat:session';
const seedPending = (text, ts = Date.now()) =>
  sessionStorage.setItem(PENDING_KEY, JSON.stringify({ text, ts }));

const renderChat = () => render(<MemoryRouter><Chat /></MemoryRouter>);

describe('Chat — pending prompt y typing indicator', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    chatApi.send.mockResolvedValue({ reply: 'Respuesta de Nodus', session_id: 'sess-1' });
  });

  it('envía automáticamente un pending prompt reciente al montar', async () => {
    seedPending('¿Cuántas tardanzas van hoy?');
    renderChat();
    await waitFor(() =>
      expect(chatApi.send).toHaveBeenCalledWith('¿Cuántas tardanzas van hoy?', expect.any(String), null));
    expect(screen.getByText('¿Cuántas tardanzas van hoy?')).toBeInTheDocument();
    expect(await screen.findByText('Respuesta de Nodus')).toBeInTheDocument();
    expect(sessionStorage.getItem(PENDING_KEY)).toBeNull();
    expect(chatApi.send).toHaveBeenCalledTimes(1);
  });

  it('conserva el hilo restaurado y agrega el prompt como mensaje nuevo', async () => {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify({
      session_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      messages: [
        { from: 'user', text: 'Pregunta previa' },
        { from: 'bot', text: 'Respuesta previa' },
      ],
    }));
    seedPending('¿Cuántas ausencias van hoy?');
    renderChat();
    await waitFor(() => expect(chatApi.send).toHaveBeenCalledTimes(1));
    expect(screen.getByText('Pregunta previa')).toBeInTheDocument();
    expect(screen.getByText('Respuesta previa')).toBeInTheDocument();
    expect(screen.getByText('¿Cuántas ausencias van hoy?')).toBeInTheDocument();
  });

  it('ignora un pending prompt vencido (>2 min)', async () => {
    seedPending('pregunta vieja', Date.now() - 3 * 60 * 1000);
    renderChat();
    expect(await screen.findByText(/Hola, soy Nodus/)).toBeInTheDocument();
    expect(chatApi.send).not.toHaveBeenCalled();
    expect(sessionStorage.getItem(PENDING_KEY)).toBeNull();
  });

  it('no envía nada sin pending prompt', async () => {
    renderChat();
    expect(await screen.findByText(/Hola, soy Nodus/)).toBeInTheDocument();
    expect(chatApi.send).not.toHaveBeenCalled();
  });

  it('el typing indicator solo muestra los 3 puntos (sin barra parpadeante)', async () => {
    chatApi.send.mockImplementation(() => new Promise(() => {})); // nunca resuelve → thinking fijo
    const user = userEvent.setup();
    renderChat();
    await user.type(screen.getByLabelText('Mensaje para Nodus'), 'hola{Enter}');
    const typing = await screen.findByLabelText('Nodus está escribiendo');
    expect(typing.querySelectorAll('.animate-bounce')).toHaveLength(3);
    expect(typing.querySelectorAll('.animate-pulse')).toHaveLength(0);
  });
});
