import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { NodusBotAnnouncer } from '@/layout/Layout';
import { dashboardApi } from '@/api/dashboard';
import { useNotifications } from '@/context/NotificationContext';

vi.mock('@/api/dashboard', () => ({
  dashboardApi: { getInsights: vi.fn().mockResolvedValue({ status: 'ok', data: { insights: [] } }) },
}));
vi.mock('@/context/NotificationContext', () => ({ useNotifications: vi.fn() }));

const ctx = (over = {}) => ({
  notifCount: 0,
  notifications: [],
  markAllRead: vi.fn(),
  markRead: vi.fn(),
  refreshNotifications: vi.fn(),
  clearNotifications: vi.fn(),
  ...over,
});

const renderAt = (path) =>
  render(<MemoryRouter initialEntries={[path]}><NodusBotAnnouncer /></MemoryRouter>);

const flush = () => act(async () => {});
const finishTyping = () => act(() => { vi.advanceTimersByTime(6000); });

const dismissedKeys = () => JSON.parse(sessionStorage.getItem('nx:bot-dismissed') || '[]');

describe('NodusBotAnnouncer', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    localStorage.clear();
    sessionStorage.clear();
    dashboardApi.getInsights.mockResolvedValue({ status: 'ok', data: { insights: [] } });
    useNotifications.mockReturnValue(ctx());
  });
  afterEach(() => { vi.useRealTimers(); });

  it('«Marcar revisadas» marca todas las no-leídas como leídas', async () => {
    const markAllRead = vi.fn();
    useNotifications.mockReturnValue(ctx({
      notifCount: 2,
      notifications: [{ id: 'u2', read: false }, { id: 'u1', read: false }],
      markAllRead,
    }));
    renderAt('/operacion');
    await flush();
    finishTyping();
    fireEvent.click(screen.getByRole('button', { name: 'Marcar revisadas' }));
    expect(markAllRead).toHaveBeenCalledTimes(1);
    expect(screen.queryByText(/Llegaron/)).not.toBeInTheDocument();
  });

  it('la llave del aviso es la no-leída más reciente (orden del servidor)', async () => {
    useNotifications.mockReturnValue(ctx({
      notifCount: 2,
      notifications: [{ id: 'uuid-nueva', read: false }, { id: 'uuid-vieja', read: false }],
    }));
    renderAt('/operacion');
    await flush();
    finishTyping();
    fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
    expect(dismissedKeys()).toContain('notif:uuid-nueva');
  });

  it('no emerge en /notificaciones ni en /chat', async () => {
    useNotifications.mockReturnValue(ctx({
      notifCount: 3,
      notifications: [{ id: 'u1', read: false }],
    }));
    const { unmount } = renderAt('/notificaciones');
    await flush();
    finishTyping();
    expect(screen.queryByText(/Llegaron/)).not.toBeInTheDocument();
    unmount();

    const { unmount: unmount2 } = renderAt('/chat');
    await flush();
    finishTyping();
    expect(screen.queryByText(/Llegaron/)).not.toBeInTheDocument();
    unmount2();
  });

  it('una notificación prioritaria no quema el presupuesto del hint', async () => {
    useNotifications.mockReturnValue(ctx({
      notifCount: 1,
      notifications: [{ id: 'u1', read: false }],
    }));
    renderAt('/operacion');
    await flush();
    finishTyping();
    // Solo se muestra el aviso de notificación; el hint está encolado, no mostrado
    expect(screen.getByText(/Llegaron/)).toBeInTheDocument();
    expect(localStorage.getItem('nx:hint:/operacion')).toBeNull();

    // Al resolver la notificación, el hint pasa a ser el mensaje visible → se marca
    fireEvent.click(screen.getByRole('button', { name: 'Marcar revisadas' }));
    await flush();
    finishTyping();
    expect(localStorage.getItem('nx:hint:/operacion')).toBe('1');
  });

  it('la novedad del motor se anuncia fuera de home y se marca al mostrarse', async () => {
    dashboardApi.getInsights.mockResolvedValue({
      status: 'ok',
      data: { insights: [{ kind: 'LATE_CLUSTER', title: 'Llegadas tarde concentradas', body: 'detalle', score: 0.9 }] },
    });
    renderAt('/operacion');
    await flush();
    finishTyping();
    expect(screen.getByText(/Llegadas tarde concentradas/)).toBeInTheDocument();
    expect(JSON.parse(sessionStorage.getItem('nx:shown-insights') || '[]')).toContain('LATE_CLUSTER');
    // el hint sigue encolado sin consumir presupuesto
    expect(localStorage.getItem('nx:hint:/operacion')).toBeNull();

    // avanzar (burbuja sin chips) muestra el hint → ahí sí se marca
    fireEvent.click(screen.getByText(/Llegadas tarde concentradas/).closest('[role="button"]'));
    await flush();
    finishTyping();
    expect(localStorage.getItem('nx:hint:/operacion')).toBe('1');
  });

  it('un insight ya mostrado no se repite; sin novedades no hay bot', async () => {
    sessionStorage.setItem('nx:shown-insights', JSON.stringify(['LATE_CLUSTER']));
    dashboardApi.getInsights.mockResolvedValue({
      status: 'ok',
      data: { insights: [{ kind: 'LATE_CLUSTER', title: 'T', body: 'B' }] },
    });
    renderAt('/operacion');
    await flush();
    finishTyping();
    // solo queda el hint de la sección
    expect(screen.queryByText(/Llegadas tarde/)).not.toBeInTheDocument();
  });
});
