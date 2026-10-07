/**
 * NodusBrief — lectura en vivo de las métricas bajo las tarjetas del home.
 *
 * GET /dashboard/brief compara el día contra la media de ~20 días hábiles:
 * desvíos ≥10% en cualquier métrica, rachas de días seguidos subiendo o
 * bajando y «grupo sin ingresos». Una sola burbuja: el estado del día, no
 * novedades — esas salen por el bot flotante (NodusBotAnnouncer en Layout).
 *
 * Docente: recibe groupName (grupo seleccionado) y habla de ese grupo;
 * sin grupo habla de todos los suyos. Roles globales: la institución (o
 * la jornada del coordinador). Se refresca cada 60 s junto al pulso de
 * notificaciones; si un refetch falla conserva la última lectura buena.
 */
import { useEffect, useState } from 'react';
import { dashboardApi } from '../../api/dashboard';
import { Surface } from '../ui/Surface';
import { NexoChatBubble, NexoChatSkeleton } from './NexoChat';

const REFRESH_MS = 60000;

export const NodusBrief = ({ groupName = '' }) => {
  const [brief, setBrief] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    let alive = true;
    const load = (initial) => {
      dashboardApi.getBrief(groupName)
        .then((res) => {
          if (!alive) return;
          const b = res?.data?.brief ?? null;
          if (b) { setBrief(b); setError(false); }
          else if (initial) setError(true);
        })
        .catch(() => { if (alive && initial) setError(true); })
        .finally(() => { if (alive) setLoading(false); });
    };
    setLoading(true);
    load(true);
    const id = setInterval(() => load(false), REFRESH_MS);
    return () => { alive = false; clearInterval(id); };
  }, [groupName]);

  if (loading) return <Surface className="p-5"><NexoChatSkeleton /></Surface>;

  return (
    <Surface className="p-5" role="region" aria-label="Lectura de métricas de Nodus">
      {error || !brief ? (
        <NexoChatBubble message="No pude leer el estado de las métricas. Intenta de nuevo más tarde." />
      ) : (
        <NexoChatBubble message={brief.text} unread={brief.tone === 'warn'} />
      )}
    </Surface>
  );
};

export default NodusBrief;
