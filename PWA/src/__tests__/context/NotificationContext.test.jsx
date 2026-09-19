import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, waitFor, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import {
  NotificationProvider,
  useNotifications,
} from '@/context/NotificationContext';
import NotificationContext from '@/context/NotificationContext';

// Mock notifications API
vi.mock('@/api/notifications', () => ({
  notificationsApi: {
    getAll: vi.fn(),
    clearAll: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
  },
}));

// Mock useAuth hook
vi.mock('@/hooks/useAuth', () => ({
  useAuth: vi.fn(() => ({ isAuthenticated: false })),
}));

import { notificationsApi } from '@/api/notifications';
import { useAuth } from '@/hooks/useAuth';

const renderWithProvider = (ui, { auth = { isAuthenticated: false } } = {}) => {
  useAuth.mockReturnValue(auth);
  return render(
    <MemoryRouter>
      <NotificationProvider>{ui}</NotificationProvider>
    </MemoryRouter>
  );
};

const Probe = ({ onValue }) => {
  const ctx = useNotifications();
  if (onValue) onValue(ctx);
  return (
    <div>
      <span data-testid="notif-count">{ctx.notifCount}</span>
      <span data-testid="notif-length">{ctx.notifications.length}</span>
      <button data-testid="refresh-btn" onClick={ctx.refreshNotifications}>
        Refresh
      </button>
      <button data-testid="clear-btn" onClick={ctx.clearNotifications}>
        Clear
      </button>
      <button data-testid="read-btn" onClick={() => ctx.markRead(1)}>
        Read1
      </button>
      <button data-testid="readall-btn" onClick={ctx.markAllRead}>
        ReadAll
      </button>
    </div>
  );
};

describe('NotificationProvider', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    useAuth.mockReturnValue({ isAuthenticated: false });
  });

  it('provides default values (empty notifications, count 0)', () => {
    let contextValue;
    renderWithProvider(
      <NotificationContext.Consumer>
        {(value) => {
          contextValue = value;
          return null;
        }}
      </NotificationContext.Consumer>
    );

    expect(contextValue.notifCount).toBe(0);
    expect(contextValue.notifications).toEqual([]);
    expect(typeof contextValue.refreshNotifications).toBe('function');
    expect(typeof contextValue.clearNotifications).toBe('function');
  });

  it('throws when useNotifications is used outside provider', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const Outside = () => {
      useNotifications();
      return null;
    };

    expect(() => render(<Outside />)).toThrow(
      'useNotifications debe usarse dentro de NotificationProvider'
    );
    spy.mockRestore();
  });

  it('does not fetch when not authenticated', () => {
    renderWithProvider(<Probe />, { auth: { isAuthenticated: false } });
    expect(notificationsApi.getAll).not.toHaveBeenCalled();
  });

  it('fetches notifications when authenticated', async () => {
    notificationsApi.getAll.mockResolvedValue([{ id: 1 }, { id: 2 }]);
    renderWithProvider(<Probe />, { auth: { isAuthenticated: true } });

    await waitFor(() => expect(notificationsApi.getAll).toHaveBeenCalledTimes(1));
  });

  it('stores notifications and computes count from getAll', async () => {
    notificationsApi.getAll.mockResolvedValue([{ id: 1 }, { id: 2 }, { id: 3 }]);
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(getByTestId('notif-length').textContent).toBe('3'));
    expect(getByTestId('notif-count').textContent).toBe('3');
  });

  it('computes unread count from the read flag returned by the server', async () => {
    // El estado de lectura vive en el servidor (read_at), no en sessionStorage:
    // persistirlo ahí hacía que las leídas volvieran a contar al reingresar.
    notificationsApi.getAll.mockResolvedValue([
      { id: 1, read: true }, { id: 2, read: false }, { id: 3, read: false },
    ]);
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(getByTestId('notif-length').textContent).toBe('3'));
    expect(getByTestId('notif-count').textContent).toBe('2');
  });

  it('markRead marca una notificación en servidor y estado local', async () => {
    notificationsApi.getAll.mockResolvedValue([
      { id: 1, read: false }, { id: 2, read: false },
    ]);
    notificationsApi.markRead.mockResolvedValue({ status: 'ok' });
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(getByTestId('notif-count').textContent).toBe('2'));
    await act(async () => { getByTestId('read-btn').click(); });
    expect(notificationsApi.markRead).toHaveBeenCalledWith(1);
    expect(getByTestId('notif-count').textContent).toBe('1');
  });

  it('markAllRead marca todas y deja el contador en 0', async () => {
    notificationsApi.getAll.mockResolvedValue([
      { id: 1, read: false }, { id: 2, read: false },
    ]);
    notificationsApi.markAllRead.mockResolvedValue({ status: 'ok' });
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(getByTestId('notif-count').textContent).toBe('2'));
    await act(async () => { getByTestId('readall-btn').click(); });
    expect(notificationsApi.markAllRead).toHaveBeenCalled();
    expect(getByTestId('notif-count').textContent).toBe('0');
  });

  it('handles non-array response gracefully', async () => {
    notificationsApi.getAll.mockResolvedValue({ not: 'an array' });
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(getByTestId('notif-length').textContent).toBe('0'));
    expect(getByTestId('notif-count').textContent).toBe('0');
  });

  it('handles getAll rejection silently', async () => {
    notificationsApi.getAll.mockRejectedValue(new Error('network'));
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(notificationsApi.getAll).toHaveBeenCalledTimes(1));
    expect(getByTestId('notif-length').textContent).toBe('0');
    expect(getByTestId('notif-count').textContent).toBe('0');
  });

  it('refreshNotifications updates state with new data', async () => {
    notificationsApi.getAll.mockResolvedValue([]);
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(getByTestId('notif-length').textContent).toBe('0'));

    notificationsApi.getAll.mockResolvedValue([{ id: 1 }, { id: 2 }]);
    await act(async () => {
      getByTestId('refresh-btn').click();
    });

    await waitFor(() => expect(getByTestId('notif-length').textContent).toBe('2'));
  });

  it('clearNotifications calls API and resets state', async () => {
    notificationsApi.getAll.mockResolvedValue([{ id: 1 }, { id: 2 }]);
    notificationsApi.clearAll.mockResolvedValue({ success: true });
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(getByTestId('notif-length').textContent).toBe('2'));

    await act(async () => {
      getByTestId('clear-btn').click();
    });

    await waitFor(() => expect(notificationsApi.clearAll).toHaveBeenCalledTimes(1));
    expect(getByTestId('notif-length').textContent).toBe('0');
    expect(getByTestId('notif-count').textContent).toBe('0');
  });

  it('handles clearAll rejection silently', async () => {
    notificationsApi.getAll.mockResolvedValue([{ id: 1 }]);
    notificationsApi.clearAll.mockRejectedValue(new Error('fail'));
    const { getByTestId } = renderWithProvider(<Probe />, {
      auth: { isAuthenticated: true },
    });

    await waitFor(() => expect(getByTestId('notif-length').textContent).toBe('1'));

    await act(async () => {
      getByTestId('clear-btn').click();
    });

    await waitFor(() => expect(notificationsApi.clearAll).toHaveBeenCalledTimes(1));
    // state unchanged on error
    expect(getByTestId('notif-length').textContent).toBe('1');
  });

});
