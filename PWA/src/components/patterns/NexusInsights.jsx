/**
 * NexusInsights — "lectura de la jornada" como conversación con Nexus.
 * Reemplaza la sección Novedades: los insights de GET /dashboard/insights
 * (z-score, ventana modal, mínimos cuadrados) se muestran como burbujas
 * de chat del bot — no como texto suelto.
 */
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { dashboardApi } from '../../api/dashboard';
import { Button } from '../ui/Button';
import { Skeleton } from '../ui/Skeleton';
import { NexoAvatar } from './NexoChat';

const TARGET_ROUTES = {
  consulta: '/consulta',
  casos: '/casos',
  dispositivos: '/dispositivos',
  notificaciones: '/notificaciones',
};

const InsightBubble = ({ insight, onGo }) => (
  <div className="rounded-[16px_16px_16px_4px] border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 py-3">
    <p className="text-[14px] font-[620] text-[var(--nx-text)]">{insight.title}</p>
    <p className="mt-1 text-[13.5px] leading-relaxed text-[var(--nx-text-muted)]">{insight.body}</p>
    {insight.action?.label && TARGET_ROUTES[insight.action.target] && (
      <div className="mt-2.5">
        <Button variant="secondary" size="sm" onClick={() => onGo(insight.action.target)}>
          {insight.action.label}
        </Button>
      </div>
    )}
  </div>
);

export const NexusInsights = () => {
  const navigate = useNavigate();
  const [insights, setInsights] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let alive = true;
    dashboardApi.getInsights()
      .then((res) => { if (alive) setInsights(res?.data?.insights || []); })
      .catch(() => { if (alive) setInsights([]); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const go = (target) => navigate(TARGET_ROUTES[target] || '/');

  if (loading) {
    return (
      <div className="flex items-start gap-4">
        <NexoAvatar size={48} />
        <div className="min-w-0 flex-1 space-y-3">
          <Skeleton className="h-4 w-2/3" />
          <Skeleton className="h-4 w-1/2" />
          <Skeleton className="h-4 w-3/5" />
        </div>
      </div>
    );
  }

  return (
    <section aria-label="Lectura de la jornada de Nexus" className="flex items-start gap-4">
      <NexoAvatar size={48} />
      <div className="min-w-0 flex-1 space-y-3">
        <p className="text-caption font-semibold tracking-wide text-[var(--nx-accent)]">NEXUS</p>
        {!insights?.length ? (
          <div className="rounded-[16px_16px_16px_4px] border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 py-3">
            <p className="text-[14px] leading-relaxed text-[var(--nx-text)]">
              Todo dentro de lo normal — la jornada sigue su patrón habitual. Te aviso si algo cambia.
            </p>
          </div>
        ) : (
          insights.map((ins) => <InsightBubble key={ins.kind} insight={ins} onGo={go} />)
        )}
      </div>
    </section>
  );
};

export default NexusInsights;
