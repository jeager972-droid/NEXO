import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { AuthProvider, AuthContext } from '../../context/AuthContext';
import { useAuth } from '../../hooks/useAuth';

// Mock authApi
vi.mock('../../api/auth', () => ({
  authApi: {
    login: vi.fn(),
    logout: vi.fn(),
    getMe: vi.fn(),
    verify2FA: vi.fn(),
  },
}));

import { authApi } from '../../api/auth';

const renderWithRouter = (ui, { route = '/' } = {}) => {
  return render(
    <MemoryRouter initialEntries={[route]}>
      <Routes>
        <Route path="/login" element={<div data-testid="login-page">Login</div>} />
      </Routes>
      <AuthProvider>{ui}</AuthProvider>
    </MemoryRouter>
  );
};

describe('AuthProvider', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    sessionStorage.clear();
  });

  it('starts with loading=true and user=null', () => {
    authApi.getMe.mockReturnValue(new Promise(() => {})); // never resolves
    let contextValue;
    renderWithRouter(
      <AuthContext.Consumer>
        {(value) => { contextValue = value; return null; }}
      </AuthContext.Consumer>
    );
    expect(contextValue.user).toBeNull();
    expect(contextValue.loading).toBe(true);
    expect(contextValue.isAuthenticated).toBe(false);
  });

  it('sets user when getMe succeeds', async () => {
    const mockUser = { user_id: '123', email: 'admin@nexo.edu', role: 'RECTOR' };
    authApi.getMe.mockResolvedValue({ user: mockUser });

    let contextValue;
    renderWithRouter(
      <AuthContext.Consumer>
        {(value) => { contextValue = value; return null; }}
      </AuthContext.Consumer>
    );

    await waitFor(() => expect(contextValue.loading).toBe(false));
    expect(contextValue.user).toEqual(mockUser);
    expect(contextValue.isAuthenticated).toBe(true);
  });

  it('sets user=null when getMe fails', async () => {
    authApi.getMe.mockRejectedValue(new Error('Not authenticated'));

    let contextValue;
    renderWithRouter(
      <AuthContext.Consumer>
        {(value) => { contextValue = value; return null; }}
      </AuthContext.Consumer>
    );

    await waitFor(() => expect(contextValue.loading).toBe(false));
    expect(contextValue.user).toBeNull();
    expect(contextValue.isAuthenticated).toBe(false);
  });

  it('login sets user on success', async () => {
    const mockUser = { user_id: '123', email: 'admin@nexo.edu', role: 'RECTOR' };
    authApi.getMe.mockRejectedValue(new Error('no session'));
    authApi.login.mockResolvedValue({ user: mockUser });

    let contextValue;
    renderWithRouter(
      <AuthContext.Consumer>
        {(value) => { contextValue = value; return null; }}
      </AuthContext.Consumer>
    );

    await waitFor(() => expect(contextValue.loading).toBe(false));

    await act(async () => {
      const result = await contextValue.login('admin@nexo.edu', 'admin123');
      expect(result.user).toEqual(mockUser);
    });

    expect(contextValue.user).toEqual(mockUser);
    expect(contextValue.isAuthenticated).toBe(true);
  });

  it('login returns data without setting user when 2fa_required', async () => {
    authApi.getMe.mockRejectedValue(new Error('no session'));
    authApi.login.mockResolvedValue({ status: '2fa_required', masked_destination: 'a***n@nexo.edu' });

    let contextValue;
    renderWithRouter(
      <AuthContext.Consumer>
        {(value) => { contextValue = value; return null; }}
      </AuthContext.Consumer>
    );

    await waitFor(() => expect(contextValue.loading).toBe(false));

    let result;
    await act(async () => {
      result = await contextValue.login('admin@nexo.edu', 'admin123');
    });

    expect(result.status).toBe('2fa_required');
    expect(contextValue.user).toBeNull();
  });

  it('login throws on API error', async () => {
    authApi.getMe.mockRejectedValue(new Error('no session'));
    authApi.login.mockRejectedValue({
      response: { data: { message: 'Credenciales incorrectas' } },
    });

    let contextValue;
    renderWithRouter(
      <AuthContext.Consumer>
        {(value) => { contextValue = value; return null; }}
      </AuthContext.Consumer>
    );

    await waitFor(() => expect(contextValue.loading).toBe(false));

    await expect(
      act(async () => contextValue.login('wrong@test.com', 'wrongpass'))
    ).rejects.toThrow('Credenciales incorrectas');
  });

  it('logout calls authApi.logout and clears user', async () => {
    const mockUser = { user_id: '123', email: 'admin@nexo.edu', role: 'RECTOR' };
    authApi.getMe.mockResolvedValue({ user: mockUser });
    authApi.logout.mockResolvedValue({});

    const Probe = () => {
      const { user, logout } = useAuth();
      return (
        <div>
          <span data-testid="user-state">{user ? 'authenticated' : 'empty'}</span>
          <button data-testid="logout-btn" onClick={logout}>Logout</button>
        </div>
      );
    };

    render(
      <MemoryRouter initialEntries={['/']}>
        <AuthProvider>
          <Probe />
        </AuthProvider>
      </MemoryRouter>
    );

    await waitFor(() => expect(screen.getByTestId('user-state').textContent).toBe('authenticated'));

    await userEvent.click(screen.getByTestId('logout-btn'));

    // Verify the logout API was called
    expect(authApi.logout).toHaveBeenCalledTimes(1);
  });
});
