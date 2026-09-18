/**
 * NexusInsights — "Nexus · lectura de la jornada".
 * Reemplaza la sección Novedades del dashboard: en vez de un stream de
 * eventos crudos, muestra los insights que computa GET /dashboard/insights
 * (z-score, ventana modal, mínimos cuadrados sobre datos reales).
 * Diseño: avatar del bot + texto + botón de acción. Sin barras de color.
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

const InsightItem = ({ insight, onGo }) => (
  <div className="border-t border-[var(--nx-border)] pt-4 first:border-t-0 first:pt-0">
    <p className="text-[14.5px] font-[620] text-[var(--nx-text)]">{insight.title}</p>
    <p className="mt-1 text-[13.5px] leading-relaxed text-[var(--nx-text-muted)]">{insight.body}</p>
    {insight.action?.label && TARGET_ROUTES[insight.action.target] && (
      <div className="mt-3">
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

  return (
    <section aria-label="Lectura de la jornada de Nexus">
      <div className="border-b border-[var(--nx-border)] pb-3">
        <div className="border-l-2 border-[var(--nx-accent)] pl-3">
          <p className="text-label text-[var(--nx-text)]">Nexus · lectura de la jornada</p>
        </div>
      </div>
      <div className="mt-4 flex items-start gap-4">
        <NexoAvatar size={48} />
        <div className="min-w-0 flex-1">
          {loading ? (
            <div className="space-y-3">
              <Skeleton className="h-4 w-2/3" />
              <Skeleton className="h-4 w-1/2" />
              <Skeleton className="h-4 w-3/5" />
            </div>
          ) : !insights?.length ? (
            <p className="text-[14px] leading-relaxed text-[var(--nx-text)]">
              Todo dentro de lo normal — la jornada sigue su patrón habitual. Te aviso si algo cambia.
            </p>
          ) : (
            <div className="space-y-4">
              {insights.map((ins) => (
                <InsightItem key={ins.kind} insight={ins} onGo={go} />
              ))}
            </div>
          )}
        </div>
      </div>
    </section>
  );
};

export default NexusInsights;
