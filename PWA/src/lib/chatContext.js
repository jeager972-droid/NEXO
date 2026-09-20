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
