import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import ProtectedRoute from '../../routes/ProtectedRoute';
import { AuthContext } from '../../context/AuthContext';

const renderWithAuth = (authValue, { initialPath = '/' } = {}) => {
  return render(
    <MemoryRouter initialEntries={[initialPath]}>
      <AuthContext.Provider value={authValue}>
        <Routes>
          <Route element={<ProtectedRoute />}>
            <Route path="/" element={<div data-testid="protected-content">Protected</div>} />
          </Route>
          <Route path="/login" element={<div data-testid="login-page">Login</div>} />
          <Route path="/unauthorized" element={<div data-testid="unauthorized-page">Unauthorized</div>} />
        </Routes>
      </AuthContext.Provider>
    </MemoryRouter>
  );
};

describe('ProtectedRoute', () => {
  it('shows loading state when loading=true', () => {
    renderWithAuth({ user: null, loading: true });
    expect(screen.getByText('Cargando…')).toBeInTheDocument();
    expect(screen.queryByTestId('protected-content')).not.toBeInTheDocument();
  });

  it('redirects to /login when no user', () => {
    renderWithAuth({ user: null, loading: false });
    expect(screen.getByTestId('login-page')).toBeInTheDocument();
    expect(screen.queryByTestId('protected-content')).not.toBeInTheDocument();
  });

  it('renders protected content when user is authenticated', () => {
    renderWithAuth({
      user: { email: 'admin@nexo.edu', role: 'RECTOR' },
      loading: false,
    });
    expect(screen.getByTestId('protected-content')).toBeInTheDocument();
  });
});

describe('ProtectedRoute with allowedRoles', () => {
  const renderWithRoles = (authValue, allowedRoles) => {
    return render(
      <MemoryRouter initialEntries={['/protected']}>
        <AuthContext.Provider value={authValue}>
          <Routes>
            <Route element={<ProtectedRoute allowedRoles={allowedRoles} />}>
              <Route path="/protected" element={<div data-testid="protected-content">Protected</div>} />
            </Route>
            <Route path="/login" element={<div data-testid="login-page">Login</div>} />
            <Route path="/unauthorized" element={<div data-testid="unauthorized-page">Unauthorized</div>} />
          </Routes>
        </AuthContext.Provider>
      </MemoryRouter>
    );
  };

  it('allows access when role is in allowedRoles', () => {
    renderWithRoles(
      { user: { role: 'RECTOR' }, loading: false },
      ['RECTOR', 'COORDINATOR']
    );
    expect(screen.getByTestId('protected-content')).toBeInTheDocument();
  });

  it('redirects to /unauthorized when role is not allowed', () => {
    renderWithRoles(
      { user: { role: 'TEACHER' }, loading: false },
      ['RECTOR', 'COORDINATOR']
    );
    expect(screen.getByTestId('unauthorized-page')).toBeInTheDocument();
    expect(screen.queryByTestId('protected-content')).not.toBeInTheDocument();
  });

  it('allows any authenticated user when allowedRoles is not provided', () => {
    renderWithRoles(
      { user: { role: 'SECURITY' }, loading: false },
      undefined
    );
    expect(screen.getByTestId('protected-content')).toBeInTheDocument();
  });
});
