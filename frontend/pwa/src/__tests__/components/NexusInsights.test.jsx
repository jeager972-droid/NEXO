import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { NexusInsights } from '@/components/patterns/NexusInsights';
import { dashboardApi } from '@/api/dashboard';

vi.mock('@/api/dashboard', () => ({
  dashboardApi: { getInsights: vi.fn() },
}));

const PENDING_KEY = 'nx:chat:pending';
const ins = (kind, extra = {}) => ({
  kind, severity: 'MEDIUM', title: `Título ${kind}`, body: `Cuerpo ${kind}`, ...extra,
});
const pendingText = () => JSON.parse(sessionStorage.getItem(PENDING_KEY) || 'null')?.text;

const renderInsights = () =>
  render(
    <MemoryRouter initialEntries={['/']}>
      <Routes>
        <Route path="/" element={<NexusInsights />} />
        <Route path="/chat" element={<p>DESTINO CHAT</p>} />
        <Route path="/casos" element={<p>DESTINO CASOS</p>} />
        <Route path="/dispositivos" element={<p>DESTINO DISPOSITIVOS</p>} />
      </Routes>
    </MemoryRouter>
  );

describe('NexusInsights', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
  });

  it('target «consulta» deja pending prompt y abre /chat (LATE_CLUSTER con grupo)', async () => {
    dashboardApi.getInsights.mockResolvedValue({ status: 'ok', data: { insights: [
      ins('LATE_CLUSTER', {
        action: { label: 'Ver grupo 8A', target: 'consulta' },
        data: { group: '8A', n: 9, window: ['7:02', '7:12'] },
      }),
    ] } });
    const user = userEvent.setup();
    renderInsights();
    await user.click(await screen.findByRole('button', { name: 'Ver grupo 8A' }));
    expect(await screen.findByText('DESTINO CHAT')).toBeInTheDocument();
    expect(pendingText()).toBe('Tardanzas del grupo 8A esta semana');
  });

  it('las anomalías de tipo con módulo NLU preguntan el conteo de hoy', async () => {
    dashboardApi.getInsights.mockResolvedValue({ status: 'ok', data: { insights: [
      ins('EVASION_ANOMALY', {
        action: { label: 'Ver en consulta', target: 'consulta' },
        data: { type: 'EVASION', today: 7, mean: 2, sd: 1.2, z: 3.1 },
      }),
    ] } });
    const user = userEvent.setup();
    renderInsights();
    await user.click(await screen.findByRole('button', { name: 'Ver en consulta' }));
    expect(await screen.findByText('DESTINO CHAT')).toBeInTheDocument();
    expect(pendingText()).toBe('¿Cuántas evasiones van hoy?');
  });

  it('WEEKLY_DIGEST pregunta por el delta principal de la semana', async () => {
    dashboardApi.getInsights.mockResolvedValue({ status: 'ok', data: { insights: [
      ins('WEEKLY_DIGEST', {
        action: { label: 'Ver detalle', target: 'consulta' },
        data: { deltas: [{ type: 'LATE_ARRIVAL', cur: 12, prev: 5, rel: 1.4 }] },
      }),
    ] } });
    const user = userEvent.setup();
    renderInsights();
    await user.click(await screen.findByRole('button', { name: 'Ver detalle' }));
    expect(await screen.findByText('DESTINO CHAT')).toBeInTheDocument();
    expect(pendingText()).toBe('¿Cuántas tardanzas van esta semana?');
  });

  it('insight sin tipo resoluble cae al resumen de jornada (no al vacío)', async () => {
    dashboardApi.getInsights.mockResolvedValue({ status: 'ok', data: { insights: [
      ins('REAP_ANOMALY', {
        action: { label: 'Ver en consulta', target: 'consulta' },
        data: { type: 'REAPARICION_TARDIA', today: 4 },
      }),
    ] } });
    const user = userEvent.setup();
    renderInsights();
    await user.click(await screen.findByRole('button', { name: 'Ver en consulta' }));
    expect(pendingText()).toBe('¿Cómo va la jornada hoy?');
  });

  it('«Derivar a seguimiento» solo aparece con un solo caso sin respuesta', async () => {
    dashboardApi.getInsights.mockResolvedValue({ status: 'ok', data: { insights: [
      ins('UNREPLIED_ABSENCE', {
        action: { label: 'Derivar a seguimiento', target: 'casos' },
        data: { unreplied: 1, escalated: 0 },
      }),
    ] } });
    const user = userEvent.setup();
    renderInsights();
    await user.click(await screen.findByRole('button', { name: 'Derivar a seguimiento' }));
    expect(await screen.findByText('DESTINO CASOS')).toBeInTheDocument();
  });

  it('con varios estudiantes el chip se relé «Ver casos» — nunca «Derivar a seguimiento»', async () => {
    dashboardApi.getInsights.mockResolvedValue({ status: 'ok', data: { insights: [
      ins('UNREPLIED_ABSENCE', {
        action: { label: 'Derivar a seguimiento', target: 'casos' },
        data: { unreplied: 4, escalated: 1 },
      }),
    ] } });
    const user = userEvent.setup();
    renderInsights();
    expect(await screen.findByRole('button', { name: 'Ver casos' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Derivar a seguimiento' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Ver casos' }));
    expect(await screen.findByText('DESTINO CASOS')).toBeInTheDocument();
  });

  it('target «dispositivos» navega simple sin dejar prompt pendiente', async () => {
    dashboardApi.getInsights.mockResolvedValue({ status: 'ok', data: { insights: [
      ins('NODE_OFFLINE', {
        action: { label: 'Ver dispositivos', target: 'dispositivos' },
        data: { count: 2, worst_hours: 1.2 },
      }),
    ] } });
    const user = userEvent.setup();
    renderInsights();
    await user.click(await screen.findByRole('button', { name: 'Ver dispositivos' }));
    expect(await screen.findByText('DESTINO DISPOSITIVOS')).toBeInTheDocument();
    expect(sessionStorage.getItem(PENDING_KEY)).toBeNull();
  });
});
