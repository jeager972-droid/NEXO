/**
 * Memoria de contexto del chatbot — sessionStorage (por pestaña).
 * Guarda la última intención exitosa + entidades para resolver frases
 * dependientes («su grupo», «cuéntame otro», «cuántas evasiones tiene»).
 * La API PHP recibe ctx transparente y marca los slots como _inherited.
 */

const KEY = 'nx_chat_ctx';
const TTL = 30 * 60 * 1000; // 30 min — una conversación, no el día entero

/** Entidades que vale la pena recordar para referencias futuras. */
const KEEP = ['student', 'group', 'module', 'days', 'from', 'to', 'range_label', 'field'];

export function saveCtx(res) {
  try {
    if (!res?.intent || res.denied) return;
    const entities = {};
    for (const k of KEEP) if (res.entities?.[k]) entities[k] = res.entities[k];
    const ctx = {
      last_intent: res.intent,
      last_reply: res.reply,
      last_cmd: res.actions?.[0]?.to?.match(/cmd=([a-z_]+)/)?.[1] ?? null,
      entities,
      ts: Date.now(),
    };
    sessionStorage.setItem(KEY, JSON.stringify(ctx));
  } catch { /* storage lleno/bloqueado — contexto se pierde sin romper nada */ }
}

export function readCtx() {
  try {
    const raw = sessionStorage.getItem(KEY);
    if (!raw) return null;
    const ctx = JSON.parse(raw);
    if (Date.now() - (ctx.ts ?? 0) > TTL) { sessionStorage.removeItem(KEY); return null; }
    return ctx;
  } catch { return null; }
}

/* ── Prompt pendiente (deep-links → chat) ──────────────────────────────
 * Botones como «Ver grupo 8A» en Insights navegan a /chat — sin este
 * mecanismo el usuario llegaba a una conversación vacía. El origen deja
 * aquí la pregunta ya formulada y Chat.jsx la envía sola al montar.
 * Vive en sessionStorage: es un traspaso dentro de la misma pestaña, no
 * estado persistente. */

const PENDING_KEY = 'nx:chat:pending';
const PENDING_TTL = 2 * 60 * 1000; // 2 min — solo cubre la navegación inmediata

/** Deja una pregunta lista para que el chat la envíe al abrirse. */
export function setPendingPrompt(text) {
  try {
    const t = String(text ?? '').trim();
    if (!t) return;
    sessionStorage.setItem(PENDING_KEY, JSON.stringify({ text: t, ts: Date.now() }));
  } catch { /* storage lleno/bloqueado — el deep-link queda en chat vacío */ }
}

/**
 * Lee Y consume el prompt pendiente — la clave se borra siempre al leerla
 * para que un prompt viejo no dispare en una visita posterior ni un
 * doble-mount envíe el mensaje dos veces. >2 min o malformado → null.
 * Devuelve máx. 500 chars (límite del endpoint /chat/message).
 */
export function takePendingPrompt() {
  try {
    const raw = sessionStorage.getItem(PENDING_KEY);
    if (!raw) return null;
    sessionStorage.removeItem(PENDING_KEY);
    const p = JSON.parse(raw);
    const text = typeof p?.text === 'string' ? p.text.trim() : '';
    if (!text || Date.now() - (p?.ts ?? 0) > PENDING_TTL) return null;
    return text.slice(0, 500);
  } catch { return null; }
}

/* ── Insight → pregunta de chat ────────────────────────────────────────
 * «Ver detalle» en un insight deja aquí la consulta equivalente ya
 * formulada — el usuario llega a /chat con el mensaje enviándose solo,
 * no a una conversación vacía. */

// Palabra de módulo que el NLU resuelve (sinónimos en nexus_nlu.php);
// «reapariciones tardías» no tiene módulo → cae al resumen de jornada.
const MODULE_WORD = {
  LATE_ARRIVAL: 'tardanzas',
  UNAUTHORIZED_ABSENCE: 'ausencias',
  INASISTENCIA: 'ausencias',
  EVASION: 'evasiones',
};

const DAY_SUMMARY_PROMPT = '¿Cómo va la jornada hoy?';

// Cada insight se convierte en una consulta que cae en un intent real del
// chat (conteo/listado por módulo + rango, resumen de jornada) — nunca en
// una burbuja vacía ni en un «no entendí».
export function insightPrompt(ins) {
  const d = ins?.data ?? {};
  if (ins?.kind === 'LATE_CLUSTER') {
    return d.group
      ? `Tardanzas del grupo ${d.group} esta semana`
      : '¿Cuántas tardanzas van esta semana?';
  }
  if (ins?.kind === 'WEEKLY_DIGEST') {
    const w = MODULE_WORD[d.deltas?.[0]?.type];
    return w ? `¿Cuántas ${w} van esta semana?` : DAY_SUMMARY_PROMPT;
  }
  if (ins?.kind?.startsWith('TREND_')) {
    const w = MODULE_WORD[d.type];
    return w ? `¿Cuántas ${w} van esta semana?` : DAY_SUMMARY_PROMPT;
  }
  if (ins?.kind?.endsWith('_ANOMALY')) {
    const w = MODULE_WORD[d.type];
    return w ? `¿Cuántas ${w} van hoy?` : DAY_SUMMARY_PROMPT;
  }
  return `Háblame de «${ins?.title}»: ${ins?.body}`;
}

/** Frase corta/dependiente → devuelve ctx para adjuntar al POST. */
export function injectCtx(text) {
  const ctx = readCtx();
  if (!ctx) return null;
  const t = text.toLowerCase().trim();
  const dependent =
    /\b(su|sus|ese|esa|el mismo|la misma)\b/.test(t) ||                        // «su grupo», «sus notas»
    /^(dame |dime |y |pero |entonces )?(otro|otra|mas|siguiente|otra vez|de nuevo|uno mas|una mas)\b/.test(t) ||
    (t.length < 42 && /^(y )?(cuant[oa]s?|que|donde|cuando|quien)/.test(t));  // «y cuántas evasiones tiene»
  return dependent ? ctx : null;
}
