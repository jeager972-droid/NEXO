/**
 * NexusInsights — "lectura de la jornada" como conversación con Nexus.
 * Reemplaza la sección Novedades: los insights de GET /dashboard/insights
 * (z-score, ventana modal, mínimos cuadrados) se muestran con el mismo
 * componente NexoChatBubble que usa el estado vacío del docente —
 * una sola voz de Nexus en toda la app.
 *
 * Chips con target 'consulta' no abren un chat vacío: dejan la pregunta
 * ya formulada en el pending-prompt (lib/chatContext) y Chat la envía
 * sola al montar — el deep-link termina en una respuesta con datos.
 */
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { dashboardApi } from '../../api/dashboard';
import { insightPrompt, setPendingPrompt } from '../../lib/chatContext';
import { Button } from '../ui/Button';
import { Surface } from '../ui/Surface';
import { NexoChatBubble, NexoChatSkeleton } from './NexoChat';

const TARGET_ROUTES = {
  consulta: '/chat', // /consulta redirige al chat — el chip llega con la pregunta lista
  casos: '/casos',
  dispositivos: '/dispositivos',
  notificaciones: '/notificaciones',
};

export const NexusInsights = () => {
  const navigate = useNavigate();
  const [insights, setInsights] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    let alive = true;
    dashboardApi.getInsights()
      .then((res) => { if (alive) setInsights(res?.data?.insights || []); })
      .catch(() => { if (alive) setError(true); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const openAction = (ins) => {
    if (ins.action?.target === 'consulta') setPendingPrompt(insightPrompt(ins));
    navigate(TARGET_ROUTES[ins.action.target]);
  };

  // «Derivar a seguimiento» solo aplica a UN caso pendiente — un insight
  // que agrega varios estudiantes no es una acción directa; se vuelve
  // navegación honesta («Ver casos») hacia el mismo destino.
  const chipLabel = (ins) =>
    ins.action?.target === 'casos' && (ins.data?.unreplied ?? 0) > 1
      ? 'Ver casos'
      : ins.action?.label;

  if (loading) return <Surface className="p-5"><NexoChatSkeleton /></Surface>;

  return (
    <Surface className="space-y-5 p-5" role="region" aria-label="Lectura de la jornada de Nexus">
      {error ? (
        <NexoChatBubble message="No pude cargar la lectura de la jornada. Intenta de nuevo más tarde." />
      ) : !insights?.length ? (
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
                onClick={() => openAction(ins)}
              >
                {chipLabel(ins)}
              </Button>
            ) : null}
          />
        ))
      )}
    </Surface>
  );
};

export default NexusInsights;
