import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import ProtectedRoute from '../../routes/ProtectedRoute';
import { AuthContext } from '../../context/AuthContext';
import { COOKIE_CONSENT_KEY, TERMS_VERSION } from '../../config/legal';

// Usuario que ya resolvió el gate legal: consentimiento de cookies en
// localStorage + Términos vigentes aceptados en el servidor.
const seedCookieConsent = () => {
  localStorage.setItem(COOKIE_CONSENT_KEY, JSON.stringify({
    version: '1.0',
    timestamp: new Date().toISOString(),
    categories: { necessary: true, preferences: false, analytics: false, marketing: false },
  }));
};
const legalUser = (extra = {}) => ({ email: 'admin@nexo.edu', role: 'RECTOR', terms_version: TERMS_VERSION, terms_accepted: true, ...extra });

beforeEach(() => {
  localStorage.clear();
});

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

  it('renders protected content when user is authenticated and legal gate is resolved', () => {
    seedCookieConsent();
    renderWithAuth({
      user: legalUser(),
      loading: false,
    });
    expect(screen.getByTestId('protected-content')).toBeInTheDocument();
  });

  it('shows the legal gate (cookies) when the authenticated user has not consented', () => {
    renderWithAuth({
      user: legalUser(),
      loading: false,
    });
    expect(screen.queryByTestId('protected-content')).not.toBeInTheDocument();
    expect(screen.getByText('Uso de cookies y almacenamiento local')).toBeInTheDocument();
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
    seedCookieConsent();
    renderWithRoles(
      { user: legalUser({ role: 'SECURITY' }), loading: false },
      undefined
    );
    expect(screen.getByTestId('protected-content')).toBeInTheDocument();
  });
});
