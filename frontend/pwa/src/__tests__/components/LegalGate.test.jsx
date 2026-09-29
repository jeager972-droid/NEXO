import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import LegalGate from '../../components/legal/LegalGate';
import { AuthContext } from '../../context/AuthContext';
import { authApi } from '../../api/auth';
import { COOKIE_CONSENT_KEY, TERMS_VERSION, TERMS_FALLBACK_KEY } from '../../config/legal';

vi.mock('../../api/auth', () => ({
  authApi: { acceptTerms: vi.fn().mockResolvedValue({ status: 'ok', terms_version: '2026.09' }) },
}));

const renderGate = (authValue) =>
  render(
    <AuthContext.Provider value={authValue}>
      <LegalGate user={authValue.user}>
        <div data-testid="app-content">Contenido</div>
      </LegalGate>
    </AuthContext.Provider>
  );

const baseUser = { id: 'u-1', email: 'rector@nexo.edu', role: 'RECTOR' };

const seedCookieConsent = () => {
  localStorage.setItem(COOKIE_CONSENT_KEY, JSON.stringify({
    version: '1.0',
    timestamp: new Date().toISOString(),
    categories: { necessary: true, preferences: true, analytics: true, marketing: false },
  }));
};

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
});

describe('LegalGate', () => {
  it('bloquea la app y muestra el aviso de cookies primero', () => {
    renderGate({ user: baseUser, loading: false });
    expect(screen.queryByTestId('app-content')).not.toBeInTheDocument();
    expect(screen.getByText('Uso de cookies y almacenamiento local')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Aceptar todas/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Solo necesarias/i })).toBeInTheDocument();
  });

  it('tras decidir cookies muestra Términos con Nexus y exige aceptar', () => {
    renderGate({ user: baseUser, loading: false });
    fireEvent.click(screen.getByRole('button', { name: /Solo necesarias/i }));
    expect(screen.getByText('Términos y Condiciones de Uso')).toBeInTheDocument();
    expect(screen.getAllByText('NEXUS').length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: /Aceptar y continuar/i })).toBeInTheDocument();
    expect(screen.queryByTestId('app-content')).not.toBeInTheDocument();
  });

  it('«Leer más» despliega el texto legal completo', () => {
    renderGate({ user: baseUser, loading: false });
    expect(screen.queryByText(/Marco legal/)).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Leer más/i }));
    expect(screen.getByText(/Marco legal/)).toBeInTheDocument();
  });

  it('aceptar los términos registra la versión y libera la app', async () => {
    const setUser = vi.fn();
    seedCookieConsent();
    renderGate({ user: baseUser, loading: false, setUser });
    fireEvent.click(screen.getByRole('button', { name: /Aceptar y continuar/i }));
    await vi.waitFor(() => {
      expect(screen.getByTestId('app-content')).toBeInTheDocument();
    });
    expect(authApi.acceptTerms).toHaveBeenCalledWith(TERMS_VERSION);
    const stored = JSON.parse(localStorage.getItem(TERMS_FALLBACK_KEY));
    expect(stored.user_id).toBe('u-1');
    expect(stored.version).toBe(TERMS_VERSION);
  });

  it('no muestra el gate cuando el usuario ya resolvió todo', () => {
    seedCookieConsent();
    renderGate({ user: { ...baseUser, terms_version: TERMS_VERSION, terms_accepted: true }, loading: false });
    expect(screen.getByTestId('app-content')).toBeInTheDocument();
  });
});
