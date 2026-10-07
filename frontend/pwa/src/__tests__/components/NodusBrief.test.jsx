import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { NodusBrief } from '@/components/patterns/NodusBrief';
import { dashboardApi } from '@/api/dashboard';

vi.mock('@/api/dashboard', () => ({
  dashboardApi: { getBrief: vi.fn() },
}));

const briefRes = (over = {}) => ({
  status: 'ok',
  data: { brief: { tone: 'ok', text: 'Todo en orden en la institución.', ...over } },
});

describe('NodusBrief', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('muestra el texto del brief que devuelve la API', async () => {
    dashboardApi.getBrief.mockResolvedValue(briefRes({ text: 'Hoy las inasistencias suben 15% sobre el promedio.' }));
    render(<NodusBrief />);
    expect(await screen.findByText(/inasistencias suben 15%/i)).toBeInTheDocument();
  });

  it('pasa el grupo seleccionado como group_name a la API', async () => {
    dashboardApi.getBrief.mockResolvedValue(briefRes());
    render(<NodusBrief groupName="8A" />);
    await waitFor(() => expect(dashboardApi.getBrief).toHaveBeenCalledWith('8A'));
  });

  it('recarga cuando cambia el grupo seleccionado', async () => {
    dashboardApi.getBrief.mockResolvedValue(briefRes());
    const { rerender } = render(<NodusBrief groupName="8A" />);
    await waitFor(() => expect(dashboardApi.getBrief).toHaveBeenCalledWith('8A'));
    rerender(<NodusBrief groupName="9B" />);
    await waitFor(() => expect(dashboardApi.getBrief).toHaveBeenCalledWith('9B'));
  });

  it('tono warn marca la burbuja como no leída', async () => {
    dashboardApi.getBrief.mockResolvedValue(briefRes({ tone: 'warn', text: 'Alerta de métricas.' }));
    render(<NodusBrief />);
    await screen.findByText('Alerta de métricas.');
    expect(screen.getByTitle('Sin leer')).toBeInTheDocument();
  });

  it('tono ok no marca la burbuja', async () => {
    dashboardApi.getBrief.mockResolvedValue(briefRes());
    render(<NodusBrief />);
    await screen.findByText(/Todo en orden/);
    expect(screen.queryByTitle('Sin leer')).not.toBeInTheDocument();
  });

  it('error de API muestra estado honesto', async () => {
    dashboardApi.getBrief.mockRejectedValue(new Error('red'));
    render(<NodusBrief />);
    expect(await screen.findByText(/No pude leer el estado de las métricas/i)).toBeInTheDocument();
  });
});
