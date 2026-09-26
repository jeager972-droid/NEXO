import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

// Mock useAuth — RECTOR role has access to RiskConfig
// Stable reference to avoid infinite re-renders from useEffect deps
vi.mock('@/hooks/useAuth', () => {
  const mockValue = {
    user: { role: 'RECTOR', user_id: '123', email: 'admin@nexo.edu' },
    loading: false,
  };
  return { useAuth: () => mockValue };
});

// Mock risk API
vi.mock('@/api/risk', () => ({
  riskApi: {
    getPolicy: vi.fn().mockResolvedValue({
      data: {
        policy: { version: 1, activated_at: '2024-01-01T00:00:00Z' },
        config: { rules: [], mapping: [], combos: [] },
      },
    }),
    getEventTypes: vi.fn().mockResolvedValue({
      data: [
        { type_code: 'LATE_ARRIVAL', category: 'asistencia', display_name: 'Llegada tarde', description: 'Ingreso después de la hora' },
      ],
    }),
    getPolicyHistory: vi.fn().mockResolvedValue({ data: [] }),
    createPolicyVersion: vi.fn().mockResolvedValue({}),
  },
}));

import RiskConfig from '@/pages/RiskConfig';

const renderRiskConfig = () =>
  render(
    <MemoryRouter>
      <RiskConfig />
    </MemoryRouter>
  );

describe('RiskConfig page (RECTOR)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders without crashing', async () => {
    renderRiskConfig();
    await waitFor(() => {
      expect(screen.getByText('Análisis de Riesgo Pedagógico')).toBeInTheDocument();
    });
  });

  it('renders the page title', async () => {
    renderRiskConfig();
    await waitFor(() => {
      expect(screen.getByText('Análisis de Riesgo Pedagógico')).toBeInTheDocument();
    });
  });

  it('renders the Historial button', async () => {
    renderRiskConfig();
    await waitFor(() => {
      expect(screen.getByText('Historial')).toBeInTheDocument();
    });
  });

  it('renders the severity levels section', async () => {
    renderRiskConfig();
    await waitFor(() => {
      expect(screen.getByText('Niveles de gravedad')).toBeInTheDocument();
    });
    // "Sin importancia" appears both in the info banner and the severity card
    expect(screen.getAllByText('Sin importancia').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Leve').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Muy Alta').length).toBeGreaterThan(0);
  });

  it('renders the "How it works" info banner', async () => {
    renderRiskConfig();
    await waitFor(() => {
      expect(screen.getByText('¿Cómo funciona?')).toBeInTheDocument();
    });
  });
});
