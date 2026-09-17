/**
 * PREVIEW DESECHABLE — solo dev (onboarding-preview.html).
 * Monta OnboardingFlow aislado: sin AuthContext, sin NotificationProvider,
 * sin router, sin llamadas reales al API (simulate=true).
 * ?role=rector|coordinator|teacher — reinicia al terminar.
 * Borrar este archivo y onboarding-preview.html tras validar.
 */
import '@fontsource-variable/inter';
import React from 'react';
import ReactDOM from 'react-dom/client';
import '../index.css';
import OnboardingFlow from '../pages/onboarding/OnboardingFlow';
import { ThemeProvider } from '../context/ThemeContext';
import { ROLES } from '../config/roles';

const ROLE_MAP = {
  rector: ROLES.RECTOR,
  coordinator: ROLES.COORDINADOR,
  teacher: ROLES.DOCENTE,
};

function Preview() {
  const role = ROLE_MAP[(new URLSearchParams(window.location.search).get('role') || 'rector').toLowerCase()] || ROLES.RECTOR;
  const [cycle, setCycle] = React.useState(0);
  return (
    <OnboardingFlow
      key={`${role}-${cycle}`}
      role={role}
      missing={role === ROLES.DOCENTE ? {} : { schedule: true, groups: true, risk: true }}
      onAllDone={() => setCycle((c) => c + 1)}
      simulate
    />
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <ThemeProvider>
      <Preview />
    </ThemeProvider>
  </React.StrictMode>
);
