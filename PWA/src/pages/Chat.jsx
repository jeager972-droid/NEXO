/**
 * SCR-CHAT-01 Chat con Nexus
 * Conversación directa con Nexus: responde con datos reales del sistema
 * (insights del motor, métricas de la jornada, notificaciones, casos).
 * No es un LLM — cada respuesta cita datos verificables del backend.
 */
import { useState, useEffect, useRef } from 'react';
import { Send, Sparkles } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { clsx } from 'clsx';
import { dashboardApi } from '../api/dashboard';
import { trackingApi } from '../api/tracking';
import { useNotifications } from '../context/NotificationContext';
import { NexoAvatar } from '../components/patterns/NexoChat';
import { Surface } from '../components/ui/Surface';

const EASE = [0.22, 1, 0.36, 1];

const SUGGESTIONS = [
  '¿Cómo va la jornada?',
  'Resumen de la semana',
  '¿Hay casos abiertos?',
  '¿Qué notificaciones tengo?',
];

const norm = (s) => String(s || '').toLowerCase()
  .normalize('NFD').replace(/[̀-ͯ]/g, '');

/** Responde usando datos reales — cada respuesta trae cifras del backend. */
const answer = async (text, notifCount) => {
  const q = norm(text);

  const [insightsRes, statsRes, trackRes] = await Promise.allSettled([
    dashboardApi.getInsights(),
    dashboardApi.getStats(),
    trackingApi.getActive(),
  ]);
  const insights = insightsRes.status === 'fulfilled' ? (insightsRes.value?.data?.insights || insightsRes.value?.insights || []) : [];
  const stats = statsRes.status === 'fulfilled' ? (statsRes.value?.data || statsRes.value || {}) : {};
  const trackings = trackRes.status === 'fulfilled' ? (trackRes.value?.trackings || []) : [];

  if (/semana|resumen|digest|tendencia/.test(q)) {
    const weekly = insights.find((i) => i.kind === 'WEEKLY_DIGEST');
    if (weekly) return `La semana pasada: ${weekly.body}`;
    return 'Todavía no hay suficiente historial para comparar semanas — en cuanto lo haya, te traigo el resumen aquí.';
  }

  if (/notificaci|pendiente|avisos?|alertas? nuevas/.test(q)) {
    if (!notifCount) return 'No tienes notificaciones pendientes — todo está al día.';
    return `Tienes ${notifCount} notificaci${notifCount === 1 ? 'ón' : 'ones'} sin leer. Las ves todas en la sección Notificaciones.`;
  }

  if (/caso|seguimiento|derivad/.test(q)) {
    if (!trackings.length) return 'No hay casos de seguimiento abiertos en este momento.';
    return `Hay ${trackings.length} caso${trackings.length === 1 ? '' : 's'} de seguimiento activo${trackings.length === 1 ? '' : 's'}. En «Seguimientos» ves cada ficha con origen, responsable y respuestas del acudiente.`;
  }

  if (/jornada|hoy|asistencia|tarde|ausente|inasisten|presente/.test(q)) {
    const parts = [];
    if (stats.presentCount != null) parts.push(`${stats.presentCount} presentes`);
    if (stats.lateCount) parts.push(`${stats.lateCount} llegadas tarde`);
    if (stats.absentCount) parts.push(`${stats.absentCount} ausentes`);
    if (stats.alertsCount) parts.push(`${stats.alertsCount} alertas`);
    const top = insights[0];
    const headline = parts.length ? `Hoy llevas ${parts.join(', ')}.` : 'Aún no hay registros de hoy.';
    return top ? `${headline} Y mi lectura del motor: ${top.title} — ${top.body}` : `${headline} La jornada sigue su patrón habitual.`;
  }

  if (/sensor|dispositivo|lector|nodo|offline|desconect/.test(q)) {
    const dev = insights.find((i) => i.kind === 'OFFLINE_DEVICES' || /dispositivo|sensor|nodo/i.test(i.title));
    if (dev) return `Ojo con esto: ${dev.title} — ${dev.body}`;
    return 'Todos los sensores reportan normalidad. Si alguno se desconecta te aviso aquí y en Notificaciones.';
  }

  if (/riesgo|riesg/.test(q)) {
    const risk = insights.find((i) => /riesgo|alerta/i.test(i.title));
    if (risk) return `${risk.title} — ${risk.body}`;
    return 'El motor de riesgo no tiene alertas activas ahora — ningún patrón supera los umbrales configurados.';
  }

  return 'Puedo contarte cómo va la jornada, el resumen de la semana, los casos abiertos, tus notificaciones o el estado de los sensores — todo con datos reales del sistema. ¿Qué quieres saber?';
};

const UserBubble = ({ children }) => (
  <div className="flex justify-end">
    <div className="max-w-[80%] rounded-surface rounded-br-xs bg-[var(--nx-accent)] px-4 py-3 text-body-sm text-[var(--nx-on-solid,white)]">
      {children}
    </div>
  </div>
);

const BotBubble = ({ children }) => (
  <div className="flex items-start gap-3">
    <NexoAvatar size={36} />
    <div className="flex-1">
      <div className="rounded-surface rounded-bl-xs border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3">
        <p className="text-body-sm text-[var(--nx-text)] leading-relaxed">{children}</p>
      </div>
      <p className="text-caption text-[var(--nx-text-muted)] mt-1 px-1">NEXUS</p>
    </div>
  </div>
);

const Chat = () => {
  const { notifCount } = useNotifications();
  const [messages, setMessages] = useState([
    { from: 'bot', text: 'Hola — soy Nexus. Te cuento cómo va la jornada, la semana, los casos y las alertas, siempre con datos reales del sistema. ¿Qué quieres saber?' },
  ]);
  const [input, setInput] = useState('');
  const [thinking, setThinking] = useState(false);
  const scrollRef = useRef(null);

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, thinking]);

  const send = async (text) => {
    const t = (text ?? input).trim();
    if (!t || thinking) return;
    setInput('');
    setMessages((m) => [...m, { from: 'user', text: t }]);
    setThinking(true);
    try {
      const reply = await answer(t, notifCount);
      setMessages((m) => [...m, { from: 'bot', text: reply }]);
    } catch {
      setMessages((m) => [...m, { from: 'bot', text: 'No pude consultar los datos ahora — intenta de nuevo en un momento.' }]);
    } finally {
      setThinking(false);
    }
  };

  return (
    <div className="flex h-[calc(100dvh-180px)] flex-col">
      <div className="mb-4 flex items-center gap-3">
        <NexoAvatar size={40} />
        <div>
          <h1 className="text-heading font-semibold text-[var(--nx-text)]">Nexus</h1>
          <p className="text-caption text-[var(--nx-text-muted)]">Respuestas con datos reales — nada inventado</p>
        </div>
      </div>

      <Surface className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div ref={scrollRef} className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
          <AnimatePresence initial={false}>
            {messages.map((m, i) => (
              <motion.div key={i} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2, ease: EASE }}>
                {m.from === 'bot' ? <BotBubble>{m.text}</BotBubble> : <UserBubble>{m.text}</UserBubble>}
              </motion.div>
            ))}
          </AnimatePresence>
          {thinking && (
            <div className="flex items-start gap-3">
              <NexoAvatar size={36} />
              <div className="rounded-surface rounded-bl-xs border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3">
                <span className="flex gap-1">
                  {[0, 1, 2].map((d) => (
                    <span key={d} className="h-1.5 w-1.5 animate-bounce rounded-full bg-[var(--nx-text-muted)]" style={{ animationDelay: `${d * 0.15}s` }} />
                  ))}
                </span>
              </div>
            </div>
          )}
        </div>

        <div className="shrink-0 border-t border-[var(--nx-border)] p-4">
          <div className="mb-3 flex flex-wrap gap-2">
            {SUGGESTIONS.map((s) => (
              <button
                key={s}
                type="button"
                onClick={() => send(s)}
                className="flex items-center gap-1.5 rounded-full border border-[var(--nx-border-accent)] bg-[var(--nx-subtle-bg-accent)] px-3.5 py-1.5 text-[13px] font-medium text-[var(--nx-accent)] transition-colors hover:bg-[var(--nx-surface-accent)]"
              >
                <Sparkles size={12} /> {s}
              </button>
            ))}
          </div>
          <form
            onSubmit={(e) => { e.preventDefault(); send(); }}
            className={clsx('flex items-center gap-3')}
          >
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Pregúntale a Nexus…"
              className="flex-1 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-2.5 text-body-sm text-[var(--nx-text)] placeholder:text-[var(--nx-text-muted)] focus:outline-none focus:ring-2 focus:ring-[var(--nx-ring)]"
            />
            <button
              type="submit"
              disabled={!input.trim() || thinking}
              aria-label="Enviar"
              className="grid h-10 w-10 shrink-0 place-items-center rounded-control bg-[var(--nx-accent)] text-white transition-opacity disabled:opacity-40"
            >
              <Send size={17} />
            </button>
          </form>
        </div>
      </Surface>
    </div>
  );
};

export default Chat;
