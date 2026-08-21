import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

// Mock useAuth — RECTOR role renders AdminDashboard with KPIs
// Stable reference to avoid infinite re-renders from useEffect deps
vi.mock('@/hooks/useAuth', () => {
  const mockValue = {
    user: { role: 'RECTOR', user_id: '123', email: 'admin@nexo.edu', nombre: 'Admin' },
    loading: false,
  };
  return { useAuth: () => mockValue };
});

// Mock dashboard API
vi.mock('@/api/dashboard', () => ({
  dashboardApi: {
    getStats: vi.fn().mockResolvedValue({
      presentCount: 120,
      absentCount: 5,
      alertsCount: 2,
      permCount: 3,
      lateCount: 8,
      pendingTasks: [],
      studentsByGroup: {},
      teacherGroups: [],
      groupStats: { present: 0, absent: 0, alerts: 0, permisos: 0, late: 0, outside: 0 },
    }),
    getEvents: vi.fn().mockResolvedValue({ status: 'ok', data: [] }),
    getTeacherGroupDetail: vi.fn().mockResolvedValue({ status: 'ok', data: [] }),
  },
}));

// Mock tracking API (imported by Dashboard)
vi.mock('@/api/tracking', () => ({
  trackingApi: {
    startTracking: vi.fn(),
    getActive: vi.fn().mockResolvedValue({ data: [] }),
    addNote: vi.fn(),
    getDetails: vi.fn(),
  },
}));

import Dashboard from '@/pages/Dashboard';

const renderDashboard = () =>
  render(
    <MemoryRouter>
      <Dashboard />
    </MemoryRouter>
  );

describe('Dashboard page (RECTOR)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders without crashing', async () => {
    renderDashboard();
    // "Novedades" stream label is always present
    expect(screen.getByText('Novedades')).toBeInTheDocument();
  });

  it('renders KPI stat cards after data loads', async () => {
    renderDashboard();
    await waitFor(() => {
      expect(screen.getAllByText('Presentes').length).toBeGreaterThan(0);
    });
    expect(screen.getAllByText('Alertas').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Inasistentes').length).toBeGreaterThan(0);
  });

  it('renders the real-time presence label', async () => {
    renderDashboard();
    await waitFor(() => {
      expect(screen.getByText(/Presencia estudiantil en tiempo real/i)).toBeInTheDocument();
    });
  });
});
