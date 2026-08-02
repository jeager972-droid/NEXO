import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { useAuth } from '../../hooks/useAuth';
import { AuthContext } from '../../context/AuthContext';

const Consumer = () => {
  const ctx = useAuth();
  return <div data-testid="ctx">{JSON.stringify(ctx)}</div>;
};

describe('useAuth', () => {
  it('returns context values when inside AuthProvider', () => {
    const mockValue = {
      user: { email: 'admin@nexo.edu', role: 'RECTOR' },
      loading: false,
      isAuthenticated: true,
      login: () => {},
      logout: () => {},
      setUser: () => {},
    };

    const { getByTestId } = render(
      <MemoryRouter>
        <AuthContext.Provider value={mockValue}>
          <Consumer />
        </AuthContext.Provider>
      </MemoryRouter>
    );

    const ctx = JSON.parse(getByTestId('ctx').textContent);
    expect(ctx.user.email).toBe('admin@nexo.edu');
    expect(ctx.isAuthenticated).toBe(true);
    expect(ctx.loading).toBe(false);
  });

  it('throws when used outside AuthProvider', () => {
    // Suppress console.error from React for this expected throw
    const spy = vi.spyOn(console, 'error');
    spy.mockImplementation(() => {});

    expect(() => render(<Consumer />)).toThrow(
      'useAuth debe ser usado dentro de un AuthProvider'
    );

    spy.mockRestore();
  });
});
