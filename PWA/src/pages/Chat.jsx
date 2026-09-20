/**
 * SCR-CHAT-01 Pregúntale a Nexus
 * Chatbot intent-based con NLU estadístico (TF-IDF + regresión logística en
 * backend). Cada respuesta viene del backend con intent + confianza + cards +
 * actions — el texto libre nunca ejecuta operaciones; las acciones navegan a
 * /operacion con el formulario precargado y su confirmación propia.
 */
import { useState, useEffect, useRef, useCallback } from 'react';
import { useNavigate } from 'react-router-dom';
import { Send, Sparkles, ArrowRight, RotateCcw, MessageSquare, X } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { chatApi } from '../api/chat';
import { saveCtx, injectCtx } from '../lib/chatContext';
import { NexoAvatar } from '../components/patterns/NexoChat';
import { Surface } from '../components/ui/Surface';

const EASE = [0.22, 1, 0.36, 1];

const SUGGESTIONS = [
  '¿Cómo va la jornada?',
  '¿Quiénes llegaron tarde hoy?',
  '¿Hay estudiantes en riesgo?',
  'Cuéntame un chiste',
];

/** Render markdown-lite del bot: **negrilla**, saltos de línea, • listas. */
const RichText = ({ text }) => (
  <>
    {String(text).split('\n').map((line, i) => {
      const parts = line.split(/(\*[^*]+\*)/g).map((p, j) =>
        p.startsWith('*') && p.endsWith('*')
          ? <strong key={j} className="font-semibold text-[var(--nx-text)]">{p.slice(1, -1)}</strong>
          : p
      );
      const isList = line.trim().startsWith('•');
      return (
        <span key={i} className={isList ? 'block pl-1' : undefined}>
          {parts}{i < text.split('\n').length - 1 && <br />}
        </span>
      );
    })}
  </>
);

const UserBubble = ({ children }) => (
  <div className="flex justify-end">
    <div className="max-w-[82%] rounded-surface rounded-br-xs bg-[var(--nx-accent)] px-4 py-3 text-body-sm text-[var(--nx-on-solid,white)] shadow-[var(--nx-shadow-low)]">
      {children}
    </div>
  </div>
);

const DataCard = ({ card }) => (
  <div className="mt-3 overflow-hidden rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)]">
    {card.title && (
      <p className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-3 py-1.5 text-caption font-semibold uppercase tracking-wide text-[var(--nx-text-muted)]">
        {card.title}
      </p>
    )}
    <div className="overflow-x-auto">
      <table className="w-full text-left text-[12.5px]">
        <thead>
          <tr className="border-b border-[var(--nx-border)]">
            {card.columns.map((c, i) => (
              <th key={i} className="px-3 py-1.5 font-semibold text-[var(--nx-text-muted)]">{c}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {card.rows.map((r, i) => (
            <tr key={i} className="border-b border-[var(--nx-border)] last:border-0">
              {r.map((cell, j) => (
                <td key={j} className="px-3 py-1.5 text-[var(--nx-text)]">{cell}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  </div>
);

const BotBubble = ({ msg, onAction }) => (
  <div className="flex items-start gap-3">
    <NexoAvatar size={36} />
    <div className="min-w-0 flex-1">
      <div className="rounded-surface rounded-bl-xs border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3 shadow-[var(--nx-shadow-low)]">
        <p className="text-body-sm leading-relaxed text-[var(--nx-text)]"><RichText text={msg.text} /></p>
        {msg.cards?.map((c, i) => <DataCard key={i} card={c} />)}
        {msg.actions?.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-2">
            {msg.actions.map((a, i) => (
              <button
                key={i}
                type="button"
                onClick={() => onAction(a)}
                className="flex items-center gap-1.5 rounded-full border border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)] px-3.5 py-1.5 text-[13px] font-semibold text-[var(--nx-accent)] transition-colors hover:bg-[var(--nx-surface-accent)]"
              >
                {a.label} <ArrowRight size={12} />
              </button>
            ))}
          </div>
        )}
      </div>
      <p className="mt-1 px-1 text-caption text-[var(--nx-text-muted)]">NEXUS</p>
    </div>
  </div>
);

const Typing = () => (
  <div className="flex items-start gap-3">
    <NexoAvatar size={36} />
    <div className="rounded-surface rounded-bl-xs border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3">
      <span className="flex items-center gap-1">
        {[0, 1, 2].map((d) => (
          <span key={d} className="h-1.5 w-1.5 animate-bounce rounded-full bg-[var(--nx-accent)]" style={{ animationDelay: `${d * 0.15}s` }} />
        ))}
        <span className="ml-1 inline-block h-[13px] w-[6px] animate-pulse rounded-[2px] bg-[var(--nx-accent)]" />
      </span>
    </div>
  </div>
);

const WELCOME = {
  from: 'bot',
  text: 'Hola — soy Nexus, el sistema de tu institución. Puedo contarte la jornada, buscar estudiantes, darte conteos por grupo o por días, avisarte de riesgos… o simplemente charlar. ¿Qué necesitas?',
};

const newSession = () => crypto.randomUUID();

const Chat = () => {
  const navigate = useNavigate();
  const [messages, setMessages] = useState([WELCOME]);
  const [input, setInput] = useState('');
  const [thinking, setThinking] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [sessionId, setSessionId] = useState(newSession);
  const [sessions, setSessions] = useState([]);
  const [drawer, setDrawer] = useState(false);
  const scrollRef = useRef(null);

  useEffect(() => {
    // la sesión más reciente reanuda el hilo; el resto vive en la barra lateral
    chatApi.sessions()
      .then((rows) => {
        setSessions(rows);
        if (rows.length) {
          setSessionId(rows[0].session_id);
          return chatApi.history(rows[0].session_id);
        }
        return [];
      })
      .then((rows) => { if (rows.length) setMessages(rows.map((r) => ({ from: r.from, text: r.text, cards: r.cards, actions: r.actions }))); })
      .catch(() => {})
      .finally(() => setLoaded(true));
  }, []);

  const openSession = (sid) => {
    setDrawer(false);
    if (sid === sessionId) return;
    setSessionId(sid);
    setMessages([WELCOME]);
    chatApi.history(sid)
      .then((rows) => { if (rows.length) setMessages(rows.map((r) => ({ from: r.from, text: r.text, cards: r.cards, actions: r.actions }))); })
      .catch(() => {});
  };

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, thinking]);

  const send = useCallback(async (text) => {
    const t = (text ?? input).trim();
    if (!t || thinking) return;
    setInput('');
    setMessages((m) => [...m, { from: 'user', text: t }]);
    setThinking(true);
    try {
      const res = await chatApi.send(t, sessionId, injectCtx(t));
      saveCtx(res);
      if (res.session_id && res.session_id !== sessionId) setSessionId(res.session_id);
      setMessages((m) => [...m, { from: 'bot', text: res.reply, cards: res.cards, actions: res.actions, denied: res.denied }]);
      chatApi.sessions().then(setSessions).catch(() => {});
    } catch {
      setMessages((m) => [...m, { from: 'bot', text: 'No pude procesar eso ahora — intenta de nuevo en un momento.' }]);
    } finally {
      setThinking(false);
    }
  }, [input, thinking, sessionId]);

  const onAction = (a) => {
    if (a.kind === 'nav' && a.to) navigate(a.to);
  };

  return (
    <div className="flex h-[calc(100dvh-180px)] flex-col">
      <div className="mb-4 flex items-center justify-between">
        <div className="flex items-center gap-3">
          <NexoAvatar size={40} />
          <div>
            <h1 className="text-heading font-semibold text-[var(--nx-text)]">Pregúntale a Nexus</h1>
            <p className="text-caption text-[var(--nx-text-muted)]">Lenguaje natural · datos reales · nada inventado</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={() => setDrawer(true)}
            className="flex items-center gap-1.5 rounded-full border border-[var(--nx-border)] px-3 py-1.5 text-caption font-medium text-[var(--nx-text-muted)] transition-colors hover:text-[var(--nx-text)]"
          >
            <MessageSquare size={12} /> Conversaciones
          </button>
          {loaded && messages.length > 1 && (
            <button
              type="button"
              onClick={() => { setMessages([WELCOME]); setSessionId(newSession()); }}
              className="flex items-center gap-1.5 rounded-full border border-[var(--nx-border)] px-3 py-1.5 text-caption font-medium text-[var(--nx-text-muted)] transition-colors hover:text-[var(--nx-text)]"
            >
              <RotateCcw size={12} /> Nueva
            </button>
          )}
        </div>
      </div>

      <Surface className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div ref={scrollRef} className="min-h-0 flex-1 space-y-5 overflow-y-auto p-4 sm:p-5">
          <AnimatePresence initial={false}>
            {messages.map((m, i) => (
              <motion.div key={i} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2, ease: EASE }}>
                {m.from === 'bot' ? <BotBubble msg={m} onAction={onAction} /> : <UserBubble>{m.text}</UserBubble>}
              </motion.div>
            ))}
          </AnimatePresence>
          {thinking && <Typing />}
        </div>

        <div className="shrink-0 border-t border-[var(--nx-border)] p-4">
          {messages.length <= 2 && (
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
          )}
          <form onSubmit={(e) => { e.preventDefault(); send(); }} className="flex items-center gap-3">
            <input
              value={input}
              onChange={(e) => setInput(e.target.value)}
              placeholder="Pregúntale a Nexus…"
              maxLength={500}
              aria-label="Mensaje para Nexus"
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

      {/* Barra de conversaciones */}
      <AnimatePresence>
        {drawer && (
          <>
            <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}
              className="fixed inset-0 z-40 bg-black/30" onClick={() => setDrawer(false)} />
            <motion.aside
              initial={{ x: -280 }} animate={{ x: 0 }} exit={{ x: -280 }} transition={{ type: 'spring', damping: 26, stiffness: 300 }}
              className="fixed inset-y-0 left-0 z-50 w-72 border-r border-[var(--nx-border)] bg-[var(--nx-surface)] p-4"
            >
              <div className="mb-4 flex items-center justify-between">
                <h2 className="text-body-sm font-semibold text-[var(--nx-text)]">Conversaciones</h2>
                <button onClick={() => setDrawer(false)} className="p-1 text-[var(--nx-text-muted)]" aria-label="Cerrar"><X size={16} /></button>
              </div>
              <button
                type="button"
                onClick={() => { setDrawer(false); setMessages([WELCOME]); setSessionId(newSession()); }}
                className="mb-3 flex w-full items-center gap-2 rounded-control border border-dashed border-[var(--nx-border-accent)] px-3 py-2 text-caption font-medium text-[var(--nx-accent)]"
              >
                <RotateCcw size={12} /> Nueva conversación
              </button>
              <div className="space-y-1 overflow-y-auto">
                {sessions.map((s) => (
                  <button key={s.session_id} type="button" onClick={() => openSession(s.session_id)}
                    className={`block w-full truncate rounded-control px-3 py-2 text-left text-[13px] transition-colors ${s.session_id === sessionId ? 'bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)] font-medium' : 'text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)]'}`}
                  >
                    {s.first_msg?.slice(0, 48) || 'Conversación'}
                    <span className="block text-[11px] opacity-60">{new Date(s.last_at).toLocaleDateString()} · {s.n} mensajes</span>
                  </button>
                ))}
                {!sessions.length && <p className="px-3 py-2 text-caption text-[var(--nx-text-muted)]">Sin conversaciones anteriores.</p>}
              </div>
            </motion.aside>
          </>
        )}
      </AnimatePresence>
    </div>
  );
};

export default Chat;
