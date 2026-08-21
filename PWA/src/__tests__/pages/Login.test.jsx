import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

// Mock useAuth hook (pages import from ../hooks/useAuth)
// Stable reference to avoid infinite re-renders from useEffect deps
vi.mock('@/hooks/useAuth', () => {
  const mockValue = {
    user: null,
    loading: false,
    login: vi.fn().mockResolvedValue({ user: { user_id: '123', email: 'admin@nexo.edu' } }),
    setUser: vi.fn(),
  };
  return { useAuth: () => mockValue };
});

// Mock auth API (used for 2FA verification)
vi.mock('@/api/auth', () => ({
  authApi: {
    verify2FA: vi.fn().mockResolvedValue({ user: { user_id: '123' }, token: 'tok' }),
  },
}));

import Login from '@/pages/Login';

const renderLogin = () =>
  render(
    <MemoryRouter>
      <Login />
    </MemoryRouter>
  );

describe('Login page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders without crashing', () => {
    renderLogin();
    expect(screen.getByText('Entrar')).toBeInTheDocument();
  });

  it('renders the email input field with label', () => {
    renderLogin();
    expect(screen.getByText('Correo institucional')).toBeInTheDocument();
  });

  it('renders the password input field with label', () => {
    renderLogin();
    expect(screen.getByText('Contraseña')).toBeInTheDocument();
  });

  it('renders the submit button', () => {
    renderLogin();
    const btn = screen.getByRole('button', { name: /Entrar/i });
    expect(btn).toBeInTheDocument();
    expect(btn).toHaveAttribute('type', 'submit');
  });

  it('renders the help text for contacting admin', () => {
    renderLogin();
    expect(
      screen.getByText(/Si no puedes acceder, contacta al administrador/i)
    ).toBeInTheDocument();
  });
});
