import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';

// useAuth solo se usa para logout (botón X cuando no hay onCancel)
vi.mock('@/hooks/useAuth', () => ({
  useAuth: () => ({ logout: vi.fn() }),
}));

vi.mock('@/api/school', () => ({
  schoolApi: {
    completeOnboarding: vi.fn().mockResolvedValue({}),
    completeGroupsOnboarding: vi.fn().mockResolvedValue({}),
    completeRiskConfig: vi.fn().mockResolvedValue({}),
    getTeachers: vi.fn().mockResolvedValue({ teachers: [] }),
  },
}));

vi.mock('@/api/risk', () => ({
  riskApi: {
    getPolicy: vi.fn().mockResolvedValue({ data: { config: { rules: [], mapping: [] } } }),
    createPolicyVersion: vi.fn().mockResolvedValue({}),
  },
}));

vi.mock('@/api/teacher', () => ({
  teacherApi: {
    getAlertRules: vi.fn().mockResolvedValue({ rules: [] }),
    createAlertRule: vi.fn().mockResolvedValue({}),
    updateAlertRule: vi.fn().mockResolvedValue({}),
    completeOnboarding: vi.fn().mockResolvedValue({}),
  },
}));

vi.mock('@/api/chat', () => ({
  chatApi: {
    policies: vi.fn().mockResolvedValue({}),
    savePolicies: vi.fn().mockResolvedValue({}),
  },
}));

import OnboardingFlow from '@/pages/onboarding/OnboardingFlow';
import { chatApi } from '@/api/chat';

describe('OnboardingFlow — modo inicial', () => {
  beforeEach(() => vi.clearAllMocks());

  it('muestra la bienvenida antes del primer paso real', () => {
    render(<OnboardingFlow role="RECTOR" missing={{ schedule: true }} onAllDone={vi.fn()} />);
    expect(screen.getByText('Bienvenid@')).toBeInTheDocument();
    expect(screen.getByText('Comenzar')).toBeInTheDocument();
    expect(screen.queryByText('¿Qué jornadas tiene el colegio?')).not.toBeInTheDocument();
  });
});

describe('OnboardingFlow — modo actualización', () => {
  beforeEach(() => vi.clearAllMocks());

  it('arranca directo en el paso real — sin pantalla de bienvenida', () => {
    render(
      <OnboardingFlow role="RECTOR" mode="update"
        missing={{ chat: true }} onCancel={vi.fn()} onAllDone={vi.fn()} />
    );
    expect(screen.getByText('Qué puede hacer el asistente')).toBeInTheDocument();
    expect(screen.queryByText('Bienvenid@')).not.toBeInTheDocument();
    expect(screen.queryByText('Comenzar')).not.toBeInTheDocument();
    // contador correcto: un solo paso real
    expect(screen.getAllByText('Paso 1 de 1').length).toBeGreaterThan(0);
  });

  it('docente en actualización va directo a sus criterios de aviso', () => {
    render(
      <OnboardingFlow role="TEACHER" mode="update"
        missing={{ rules: true }} onCancel={vi.fn()} onAllDone={vi.fn()} />
    );
    expect(screen.getByText('Tus criterios de aviso')).toBeInTheDocument();
    expect(screen.queryByText('Bienvenid@')).not.toBeInTheDocument();
    expect(screen.getByText('Paso 1 de 1')).toBeInTheDocument();
  });

  it('el botón de salir (X) invoca onCancel', () => {
    const onCancel = vi.fn();
    render(
      <OnboardingFlow role="RECTOR" mode="update"
        missing={{ chat: true }} onCancel={onCancel} onAllDone={vi.fn()} />
    );
    fireEvent.click(screen.getByLabelText('Salir sin cambios'));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });
});

describe('OnboardingFlow — paso de políticas del asistente', () => {
  beforeEach(() => vi.clearAllMocks());

  const renderChatPol = (props = {}) =>
    render(
      <OnboardingFlow role="RECTOR" mode="update"
        missing={{ chat: true }} onCancel={vi.fn()} onAllDone={vi.fn()} {...props} />
    );

  it('muestra la burbuja de Nexus como en los demás pasos', async () => {
    renderChatPol();
    // La etiqueta NEXUS solo existe dentro de la burbuja del NexusGuide
    expect(await screen.findByText('NEXUS')).toBeInTheDocument();
    expect(await screen.findByText('1 / 3')).toBeInTheDocument();
  });

  it('si el guardado falla muestra el error y NO avanza', async () => {
    chatApi.savePolicies.mockRejectedValueOnce({ response: { status: 500, data: {} } });
    renderChatPol();
    fireEvent.click(screen.getByText('Guardar políticas'));
    await waitFor(() => {
      expect(screen.getByRole('alert')).toHaveTextContent(/no pudo completar la operación/i);
    });
    // sigue en el paso — nunca llegó a "Cambios guardados"
    expect(screen.queryByText('Cambios guardados')).not.toBeInTheDocument();
    expect(screen.getByText('Guardar políticas')).toBeInTheDocument();
  });

  it('si el guardado tiene éxito avanza a la pantalla final', async () => {
    const onAllDone = vi.fn();
    renderChatPol({ onAllDone });
    fireEvent.click(screen.getByText('Guardar políticas'));
    const backBtn = await screen.findByText('Volver a Configuración');
    expect(screen.getByText('Cambios guardados')).toBeInTheDocument();
    fireEvent.click(backBtn);
    expect(onAllDone).toHaveBeenCalledTimes(1);
  });
});
