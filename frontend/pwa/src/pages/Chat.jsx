/**
 * SCR-CHAT-01 Pregúntale a Nodus — chat puro, estilo ChatGPT.
 * Chatbot intent-based con NLU estadístico (TF-IDF + regresión logística en
 * backend). Cada respuesta viene del backend con intent + confianza + cards +
 * actions — el texto libre nunca ejecuta operaciones; las acciones navegan a
 * /operacion con el formulario precargado y su confirmación propia.
 *
 * Layout: el hilo se monta en un frame `fixed` vía portal a document.body,
 * no en el flujo de <main>. Motivo: <main> scrollea con padding propio y el
 * motion.div de la transición de ruta aplica transform — un hijo `fixed`
 * dentro quedaría anclado al div animado (salto visible al montar) y un alto
 * calculado dependería de paddings internos del layout que pueden cambiar.
 * El portal deja al chat ocupando exactamente el hueco entre la topbar (72px)
 * y la barra inferior: sin scroll-escape, sin saltos, sin cuentas de padding.
 */
import { useState, useEffect, useRef, useCallback, useId } from 'react';
import { createPortal } from 'react-dom';
import { useNavigate } from 'react-router-dom';
import { Send, ArrowRight, Mic, MicOff, Flag } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import { chatApi } from '../api/chat';
import { saveCtx, injectCtx, takePendingPrompt } from '../lib/chatContext';
import { NexoAvatar } from '../components/patterns/NexoChat';
import { EXPORT_FORMATS } from '../utils/exporters';
import { useSpeechInput } from '../hooks/useSpeechInput';
import { useAuth } from '../hooks/useAuth';
import { ROLES } from '../config/roles';

const EASE = [0.22, 1, 0.36, 1];

/** Render markdown-lite del bot: **negrilla**, saltos de línea, • listas. */
const RichText = ({ text }) => {
  const lines = String(text).split('\n');
  return (
    <>
      {lines.map((line, i) => {
        const parts = line.split(/(\*[^*]+\*)/g).map((p, j) =>
          p.startsWith('*') && p.endsWith('*')
            ? <strong key={j} className="font-semibold text-[var(--nx-text)]">{p.slice(1, -1)}</strong>
            : p
        );
        const isList = line.trim().startsWith('•');
        return (
          <span key={i} className={isList ? 'block pl-1' : undefined}>
            {parts}{i < lines.length - 1 && <br />}
          </span>
        );
      })}
    </>
  );
};

const UserBubble = ({ children }) => (
  <div className="flex justify-end">
    <div className="max-w-[85%] rounded-surface rounded-br-xs bg-[var(--nx-accent)] px-4 py-3 text-body-sm text-[var(--nx-on-solid,white)] shadow-[var(--nx-shadow-low)] sm:max-w-[75%]">
      {children}
    </div>
  </div>
);

/** Las acciones export descargan la card del mensaje con los exporters del cliente. */
const runExportAction = (a, msg) => {
  const card = msg?.cards?.[a.card ?? 0];
  if (!card?.rows?.length) return;
  const columns = card.columns?.length
    ? card.columns
    : card.rows[0]?.map((_, i) => `col_${i + 1}`);
  const spec = {
    title: a.title || card.title || 'Exportación NEXO',
    // las cards llevan filas planas → objetos indexados por nombre de columna
    rows: card.rows.map((r) =>
      Array.isArray(r) ? Object.fromEntries(columns.map((c, i) => [c, r[i]])) : r),
    columns,
    from: a.from,
    to: a.to,
  };
  EXPORT_FORMATS.find((f) => f.id === a.format)?.run(spec);
};

const CARD_PAGE = 10;
const cellText = (v) => (v === null || v === undefined ? '—' : String(v));

export const DataCard = ({ card }) => {
  const tableId = useId();
  const [page, setPage] = useState(0);
  // una tarjeta reemplazada reinicia su paginación — el set cambió, la
  // posición previa dejó de tener significado
  useEffect(() => { setPage(0); }, [card]);
  const title = card.title || 'Datos de Nodus';
  const rows = Array.isArray(card.rows) ? card.rows : [];
  const cols = Array.isArray(card.columns) ? card.columns.length : 0;
  const total = rows.length;
  const pages = Math.ceil(total / CARD_PAGE);
  const from = total === 0 ? 0 : page * CARD_PAGE + 1;
  const to = Math.min((page + 1) * CARD_PAGE, total);
  const visible = rows.slice(page * CARD_PAGE, page * CARD_PAGE + CARD_PAGE);
  return (
    <div className="mt-3 overflow-hidden rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)]">
      {card.title && (
        <p className="border-b border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-3 py-1.5 text-caption font-semibold uppercase tracking-wide text-[var(--nx-text-muted)]">
          {card.title}
        </p>
      )}
      <div className="overflow-x-auto">
        <table id={tableId} aria-label={title} className="w-full text-left text-[12.5px]">
          {card.title && <caption className="sr-only">{card.title}</caption>}
          <thead>
            <tr className="border-b border-[var(--nx-border)]">
              {(card.columns ?? []).map((c, i) => (
                <th key={i} scope="col" className="px-3 py-1.5 font-semibold text-[var(--nx-text-muted)]">{c}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {visible.map((r, i) => {
              const cells = Array.isArray(r) ? r : [];
              const span = Math.max(cols, cells.length);
              return (
                <tr key={i} className="border-b border-[var(--nx-border)] last:border-0">
                  {Array.from({ length: span }, (_, j) => (
                    <td key={j} className="px-3 py-1.5 text-[var(--nx-text)]">{cellText(cells[j])}</td>
                  ))}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between gap-2 border-t border-[var(--nx-border)] px-3 py-1.5">
        <p role="status" aria-live="polite" className="text-caption text-[var(--nx-text-muted)]">
          Filas {from}–{to} de {total}
        </p>
        {pages > 1 && (
          <nav aria-label={`Paginación de ${title}`} className="flex items-center gap-2">
            <button
              type="button" disabled={page === 0} aria-controls={tableId}
              onClick={() => setPage((p) => Math.max(0, p - 1))}
              className="rounded-full border border-[var(--nx-border)] px-2.5 py-1 text-caption font-medium text-[var(--nx-text-muted)] transition-colors hover:text-[var(--nx-text)] disabled:opacity-40"
            >Anterior</button>
            <select
              aria-label="Página" value={page} onChange={(e) => setPage(Number(e.target.value))}
              className="rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] px-2 py-1 text-caption text-[var(--nx-text)]"
            >
              {Array.from({ length: pages }, (_, i) => (
                <option key={i} value={i}>Página {i + 1} de {pages}</option>
              ))}
            </select>
            <button
              type="button" disabled={page >= pages - 1} aria-controls={tableId}
              onClick={() => setPage((p) => Math.min(pages - 1, p + 1))}
              className="rounded-full border border-[var(--nx-border)] px-2.5 py-1 text-caption font-medium text-[var(--nx-text-muted)] transition-colors hover:text-[var(--nx-text)] disabled:opacity-40"
            >Siguiente</button>
          </nav>
        )}
      </div>
    </div>
  );
};

const BotBubble = ({ msg, onAction, onReport }) => (
  <div className="group flex items-start gap-3">
    <NexoAvatar size={32} />
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
                onClick={() => onAction(a, msg)}
                className="flex items-center gap-1.5 rounded-full border border-[var(--nx-accent)] bg-[var(--nx-subtle-bg-accent)] px-3.5 py-1.5 text-[13px] font-semibold text-[var(--nx-accent)] transition-colors hover:bg-[var(--nx-surface-accent)]"
              >
                {a.label} <ArrowRight size={12} />
              </button>
            ))}
          </div>
        )}
      </div>
      <div className="mt-1 flex items-center gap-2 px-1">
        <p className="text-caption text-[var(--nx-text-muted)]">NODUS</p>
        {/* Reportar respuesta: discreto, solo cuando el mensaje tiene
            message_id (persistido) — el idempotente es UNIQUE(user,msg). */}
        {msg.message_id && !msg.reported && (
          <button
            type="button"
            onClick={() => onReport(msg)}
            aria-label="Reportar esta respuesta"
            title="Reportar esta respuesta"
            className="grid h-6 w-6 place-items-center rounded-full text-[var(--nx-text-muted)] transition-all duration-fast hover:bg-[var(--nx-subtle-bg-danger)] hover:text-[var(--nx-danger)] sm:opacity-0 sm:focus-visible:opacity-100 sm:group-hover:opacity-100"
          >
            <Flag size={11} />
          </button>
        )}
        {msg.reported && (
          <span className="flex items-center gap-1 text-[11px] text-[var(--nx-text-muted)]">
            <Flag size={10} /> Reportada — gracias
          </span>
        )}
      </div>
    </div>
  </div>
);

const Typing = () => (
  <div className="flex items-start gap-3" aria-label="Nodus está escribiendo">
    <NexoAvatar size={32} />
    <div className="rounded-surface rounded-bl-xs border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3">
      <span className="flex items-center gap-1" aria-hidden="true">
        {[0, 1, 2].map((d) => (
          <span key={d} className="h-1.5 w-1.5 animate-bounce rounded-full bg-[var(--nx-accent)]" style={{ animationDelay: `${d * 0.15}s` }} />
        ))}
      </span>
    </div>
  </div>
);

const WELCOME = {
  from: 'bot',
  text: 'Hola, soy Nodus — ¿qué necesitas saber de la jornada?',
};

const newSession = () => crypto.randomUUID();

// El hilo vive en sessionStorage (no localStorage): sobrevive a cambiar de
// sección dentro de la misma pestaña y desaparece al cerrar la app — es la
// conversación en curso, no un historial.
const SESSION_KEY = 'nx:chat:session';

const readSaved = () => {
  try {
    const data = JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null');
    const msgs = Array.isArray(data?.messages)
      ? data.messages.filter((m) => m && (m.from === 'bot' || m.from === 'user') && typeof m.text === 'string')
      : [];
    if (!msgs.length) return null;
    return {
      session_id: typeof data.session_id === 'string' ? data.session_id : newSession(),
      messages: msgs,
    };
  } catch { return null; }
};

// Layout.jsx: estos roles usan la barra inferior como única navegación — se
// muestra en TODOS los tamaños (sin lg:hidden) y nunca tienen sidebar.
const NAV_ALWAYS = [ROLES.DOCENTE, ROLES.PORTERO, ROLES.AUXILIAR];

const Chat = () => {
  const navigate = useNavigate();
  const { user } = useAuth();
  // Restaura el hilo una sola vez — useState con función = lazy initializer
  const [saved] = useState(readSaved);
  const [messages, setMessages] = useState(() => saved?.messages ?? [WELCOME]);
  const [input, setInput] = useState('');
  const [thinking, setThinking] = useState(false);
  const [sessionId, setSessionId] = useState(() => saved?.session_id ?? newSession());
  const scrollRef = useRef(null);

  // Dictado → el texto cae al input para revisión antes de enviar. La base
  // se fija al empezar la sesión de voz para no pisar lo ya escrito a mano.
  const dictationBase = useRef('');
  const speech = useSpeechInput({
    onResult: (t) => setInput(dictationBase.current ? `${dictationBase.current} ${t}` : t),
  });

  useEffect(() => {
    try {
      sessionStorage.setItem(SESSION_KEY, JSON.stringify({ session_id: sessionId, messages, saved_at: Date.now() }));
    } catch { /* storage lleno/bloqueado — el hilo sigue vivo en memoria */ }
  }, [messages, sessionId]);

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, thinking]);

  const send = useCallback(async (text) => {
    const t = (text ?? input).trim();
    if (!t || thinking) return;
    speech.stop(); // un dictado abierto no debe seguir escribiendo tras el envío
    setInput('');
    setMessages((m) => [...m, { from: 'user', text: t }]);
    setThinking(true);
    try {
      const res = await chatApi.send(t, sessionId, injectCtx(t));
      saveCtx(res);
      if (res.session_id && res.session_id !== sessionId) setSessionId(res.session_id);
      setMessages((m) => [...m, { from: 'bot', text: res.reply, cards: res.cards, actions: res.actions, denied: res.denied, message_id: res.message_id }]);
    } catch {
      setMessages((m) => [...m, { from: 'bot', text: 'No pude procesar eso ahora — intenta de nuevo en un momento.' }]);
    } finally {
      setThinking(false);
    }
  }, [input, thinking, sessionId, speech]);

  // Prompt pendiente de un deep-link (p.ej. «Ver grupo 8A» en Insights
  // escribe la pregunta y abre /chat). Se consume UNA sola vez al montar:
  // entra como mensaje nuevo sobre el hilo restaurado — nunca lo reemplaza.
  // El ref evita re-envíos cuando `send` se recrea (thinking/input cambian).
  const pendingConsumed = useRef(false);
  useEffect(() => {
    if (pendingConsumed.current) return;
    pendingConsumed.current = true;
    const pending = takePendingPrompt();
    if (pending) send(pending);
  }, [send]);

  const onAction = (a, msg) => {
    if (a.kind === 'nav' && a.to) navigate(a.to);
    if (a.kind === 'export') runExportAction(a, msg);
  };

  // Reportar respuesta de Nodus — marca local tras éxito; el backend es
  // idempotente (un reporte por mensaje y usuario).
  const onReport = async (msg) => {
    try {
      await chatApi.report(msg.message_id, 'incorrecta');
      setMessages((m) => m.map((x) => (x.message_id === msg.message_id ? { ...x, reported: true } : x)));
    } catch { /* el flag no se marca; el usuario puede reintentar */ }
  };

  const toggleMic = () => {
    if (!speech.listening) dictationBase.current = input.trim();
    speech.toggle();
  };

  // NAV_ALWAYS: barra inferior en todos los tamaños y nunca sidebar (Layout).
  // 68px ≈ alto real de la barra (icono 22 + caption + paddings) — el
  // composer nace justo encima y nunca queda tapado.
  const navAlways = NAV_ALWAYS.includes(user?.role);
  const frame = navAlways
    ? 'fixed inset-x-0 top-[72px] bottom-[68px] z-20'
    : 'fixed inset-x-0 top-[72px] bottom-[68px] z-20 lg:bottom-0 lg:left-[220px]';

  return createPortal(
    <div className={`flex flex-col bg-[var(--nx-canvas)] ${frame}`}>
      {/* Hilo — scrollea solo él; overscroll-contain corta el encadenado
          de scroll hacia los ancestros (el antiguo "salirse del chat"). */}
      <div
        ref={scrollRef}
        role="log"
        aria-label="Conversación con Nodus"
        className="min-h-0 flex-1 overflow-y-auto overscroll-contain"
      >
        <div className="mx-auto w-full max-w-3xl space-y-4 px-3 py-4 sm:space-y-5 sm:px-4 lg:py-6">
          <AnimatePresence initial={false}>
            {messages.map((m, i) => (
              <motion.div key={i} initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} transition={{ duration: 0.2, ease: EASE }}>
                {m.from === 'bot' ? <BotBubble msg={m} onAction={onAction} onReport={onReport} /> : <UserBubble>{m.text}</UserBubble>}
              </motion.div>
            ))}
          </AnimatePresence>
          {thinking && <Typing />}
        </div>
      </div>

      {/* Composer pill — anclado al fondo del frame, centrado como el hilo */}
      <div className="shrink-0 px-3 pb-3 pt-2 sm:px-4 lg:pb-6">
        <form
          onSubmit={(e) => { e.preventDefault(); send(); }}
          className="mx-auto flex w-full max-w-3xl items-center gap-1 rounded-full border border-[var(--nx-border)] bg-[var(--nx-surface)] py-1.5 pl-4 pr-1.5 shadow-[var(--nx-shadow-low)] transition-[border-color,box-shadow] duration-fast focus-within:border-[var(--nx-accent)] focus-within:shadow-[var(--nx-ring)]"
        >
          <input
            value={input}
            onChange={(e) => setInput(e.target.value)}
            placeholder={speech.listening ? 'Escuchando…' : 'Pregúntale a Nodus…'}
            maxLength={500}
            enterKeyHint="send"
            aria-label="Mensaje para Nodus"
            className="min-w-0 flex-1 bg-transparent py-2 text-body-sm text-[var(--nx-text)] placeholder:text-[var(--nx-text-muted)] focus:outline-none"
          />
          {speech.supported && (
            <button
              type="button"
              onClick={toggleMic}
              aria-label={speech.listening ? 'Detener dictado' : 'Dictar por voz'}
              aria-pressed={speech.listening}
              title={speech.listening ? 'Detener dictado' : 'Dictar por voz'}
              className={`grid h-9 w-9 shrink-0 place-items-center rounded-full transition-colors duration-fast ${
                speech.listening
                  ? 'animate-pulse bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)]'
                  : 'text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]'
              }`}
            >
              {speech.listening ? <MicOff size={16} /> : <Mic size={16} />}
            </button>
          )}
          <button
            type="submit"
            disabled={!input.trim() || thinking}
            aria-label="Enviar"
            className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[var(--nx-accent)] text-[var(--nx-on-solid,white)] transition-opacity duration-fast disabled:opacity-40"
          >
            <Send size={15} />
          </button>
        </form>
        <p className="mx-auto mt-2 w-full max-w-3xl px-2 text-center text-caption text-[var(--nx-text-muted)]">
          Nodus responde con datos del sistema — verifica lo crítico antes de actuar.
        </p>
      </div>

      {/* Sin botón de refrescar: el hilo vive en sessionStorage y «Nueva
          conversación» reiniciaba sin motivo — se retiró por pedido. */}
    </div>,
    document.body
  );
};

export default Chat;
