import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

// Rol mutable por test — la referencia se lee en cada llamada a useAuth
const authState = vi.hoisted(() => ({ user: null }));
vi.mock('@/hooks/useAuth', () => ({
  useAuth: () => ({ user: authState.user, setUser: vi.fn(), logout: vi.fn() }),
}));

vi.mock('@/api/users', () => ({
  usersApi: {
    getExtendedProfile: vi.fn().mockResolvedValue({
      status: 'ok',
      data: { email: 'u@nexo.edu', phone: '3001234567', profile_photo_url: null },
    }),
    uploadPhoto: vi.fn(),
    changePassword: vi.fn(),
    updateProfile: vi.fn(),
    sendVerificationCode: vi.fn(),
    verifyCode: vi.fn(),
    resetPassword: vi.fn(),
  },
}));

// APIs que OnboardingFlow (importado por Profile) usaría si se abriera
vi.mock('@/api/school', () => ({
  schoolApi: {
    completeOnboarding: vi.fn(), completeGroupsOnboarding: vi.fn(),
    completeRiskConfig: vi.fn(), getTeachers: vi.fn().mockResolvedValue({ teachers: [] }),
  },
}));
vi.mock('@/api/risk', () => ({
  riskApi: { getPolicy: vi.fn().mockResolvedValue({ data: {} }), createPolicyVersion: vi.fn() },
}));
vi.mock('@/api/teacher', () => ({
  teacherApi: {
    getAlertRules: vi.fn().mockResolvedValue({ rules: [] }),
    createAlertRule: vi.fn(), updateAlertRule: vi.fn(), completeOnboarding: vi.fn(),
  },
}));
vi.mock('@/api/chat', () => ({
  chatApi: { policies: vi.fn().mockResolvedValue({}), savePolicies: vi.fn() },
}));

import Profile from '@/pages/Profile';

const renderProfile = (role) => {
  authState.user = { role, nombre: 'Test User', user_id: 'u1' };
  return render(
    <MemoryRouter>
      <Profile />
    </MemoryRouter>
  );
};

describe('Profile — sección «Tus criterios de aviso»', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authState.user = null;
  });

  it('se muestra al DOCENTE', async () => {
    renderProfile('TEACHER');
    await waitFor(() => expect(screen.getByText('Contacto')).toBeInTheDocument());
    expect(screen.getByText('Tus criterios de aviso')).toBeInTheDocument();
    expect(screen.getByText('Editar con Nexus')).toBeInTheDocument();
  });

  it('NO se muestra al PSICORIENTADOR (sin endpoint de reglas en backend)', async () => {
    renderProfile('COUNSELOR');
    await waitFor(() => expect(screen.getByText('Contacto')).toBeInTheDocument());
    expect(screen.queryByText('Tus criterios de aviso')).not.toBeInTheDocument();
    expect(screen.queryByText('Editar con Nexus')).not.toBeInTheDocument();
  });

  it('RECTOR ve la configuración institucional pero no los criterios docentes', async () => {
    renderProfile('RECTOR');
    await waitFor(() => expect(screen.getByText('Contacto')).toBeInTheDocument());
    expect(screen.queryByText('Tus criterios de aviso')).not.toBeInTheDocument();
    expect(screen.getByText('Asistente Nexus')).toBeInTheDocument();
    expect(screen.getByText('Jornadas y horarios')).toBeInTheDocument();
  });
});
