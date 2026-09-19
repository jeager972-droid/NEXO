/**
 * NexusInsights — "lectura de la jornada" como conversación con Nexus.
 * Reemplaza la sección Novedades: los insights de GET /dashboard/insights
 * (z-score, ventana modal, mínimos cuadrados) se muestran con el mismo
 * componente NexoChatBubble que usa el estado vacío del docente —
 * una sola voz de Nexus en toda la app.
 */
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { dashboardApi } from '../../api/dashboard';
import { Button } from '../ui/Button';
import { NexoChatBubble, NexoChatSkeleton } from './NexoChat';

const TARGET_ROUTES = {
  consulta: '/consulta',
  casos: '/casos',
  dispositivos: '/dispositivos',
  notificaciones: '/notificaciones',
};

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

  if (loading) return <NexoChatSkeleton />;

  return (
    <section aria-label="Lectura de la jornada de Nexus" className="space-y-4">
      {!insights?.length ? (
        <NexoChatBubble message="Todo dentro de lo normal — la jornada sigue su patrón habitual. Te aviso si algo cambia." />
      ) : (
        insights.map((ins) => (
          <NexoChatBubble
            key={ins.kind}
            timestamp="Ahora"
            message={<><b className="font-[620]">{ins.title}.</b> {ins.body}</>}
            action={ins.action?.label && TARGET_ROUTES[ins.action.target] ? (
              <Button
                variant="secondary"
                size="sm"
                onClick={() => navigate(TARGET_ROUTES[ins.action.target])}
              >
                {ins.action.label}
              </Button>
            ) : null}
          />
        ))
      )}
    </section>
  );
};

export default NexusInsights;
