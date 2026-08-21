import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

// Mock useAuth — RECTOR role has access to multiple commands
// Stable reference to avoid infinite re-renders from useCallback/useEffect deps
vi.mock('@/hooks/useAuth', () => {
  const mockValue = {
    user: { role: 'RECTOR', user_id: '123', email: 'admin@nexo.edu' },
    loading: false,
  };
  return { useAuth: () => mockValue };
});

// Mock students API (fetches groups + students on mount)
vi.mock('@/api/students', () => ({
  studentsApi: {
    getGroups: vi.fn().mockResolvedValue([]),
    getAllPaginated: vi.fn().mockResolvedValue([]),
    getAll: vi.fn().mockResolvedValue({ students: [] }),
    create: vi.fn(),
  },
}));

// Mock operations API
vi.mock('@/api/operations', () => ({
  operationsApi: {
    execute: vi.fn(),
    citacion: vi.fn(),
    salida: vi.fn(),
    permiso: vi.fn(),
    checkTwilioStatus: vi.fn(),
  },
}));

// Mock devices API
vi.mock('@/api/devices', () => ({
  devicesApi: {
    getByRole: vi.fn().mockResolvedValue(null),
    authorizeExit: vi.fn(),
  },
}));

// Mock users API
vi.mock('@/api/users', () => ({
  usersApi: {
    getByRole: vi.fn().mockResolvedValue({ data: [] }),
  },
}));

import Operation from '@/pages/Operation';

const renderOperation = () =>
  render(
    <MemoryRouter>
      <Operation />
    </MemoryRouter>
  );

describe('Operation page (RECTOR)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders without crashing', async () => {
    renderOperation();
    await waitFor(() => {
      expect(screen.getByText('Atajos disponibles')).toBeInTheDocument();
    });
  });

  it('renders command cards for the rector role', async () => {
    renderOperation();
    // Wait for loading to finish and commands to appear
    await waitFor(() => {
      expect(screen.getByText('Citar acudiente')).toBeInTheDocument();
    });
    expect(screen.getByText('Autorizar salida')).toBeInTheDocument();
    expect(screen.getByText('Situación Crítica')).toBeInTheDocument();
  });

  it('renders the "Atajos disponibles" section label', async () => {
    renderOperation();
    await waitFor(() => {
      expect(screen.getByText('Atajos disponibles')).toBeInTheDocument();
    });
  });
});
