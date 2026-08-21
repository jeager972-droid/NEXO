import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

// Mock useAuth — SECRETARIA role has access to Enrollment
// Stable reference to avoid infinite re-renders from useEffect deps
vi.mock('@/hooks/useAuth', () => {
  const mockValue = {
    user: { role: 'SECRETARY', user_id: '123', email: 'secretaria@nexo.edu' },
    loading: false,
  };
  return { useAuth: () => mockValue };
});

// Mock students API
vi.mock('@/api/students', () => ({
  studentsApi: {
    getGroups: vi.fn().mockResolvedValue([]),
    getAll: vi.fn().mockResolvedValue({ students: [], lastId: 0, hasMore: false }),
    getAllPaginated: vi.fn().mockResolvedValue([]),
    create: vi.fn().mockResolvedValue({}),
  },
}));

// Mock devices API (used by EnrollmentDrawer / StudentProfileDrawer)
vi.mock('@/api/devices', () => ({
  devicesApi: {
    getByRole: vi.fn().mockResolvedValue(null),
    requestEnrollment: vi.fn(),
    authorizeExit: vi.fn(),
  },
}));

import Enrollment from '@/pages/Enrollment';

const renderEnrollment = () =>
  render(
    <MemoryRouter>
      <Enrollment />
    </MemoryRouter>
  );

describe('Enrollment page (SECRETARIA)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders without crashing', () => {
    renderEnrollment();
    expect(screen.getByText('Nuevo alumno')).toBeInTheDocument();
  });

  it('renders the "Nuevo alumno" action card', () => {
    renderEnrollment();
    expect(screen.getByText('Nuevo alumno')).toBeInTheDocument();
    expect(
      screen.getByText(/Registra un nuevo alumno paso a paso/i)
    ).toBeInTheDocument();
  });

  it('renders the "Buscar estudiante" action card', () => {
    renderEnrollment();
    expect(screen.getByText('Buscar estudiante')).toBeInTheDocument();
    expect(
      screen.getByText(/Consulta y edita alumnos existentes/i)
    ).toBeInTheDocument();
  });
});
