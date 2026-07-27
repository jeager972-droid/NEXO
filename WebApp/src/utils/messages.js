/**
 * messages / NEXO Institucional
 * Responsabilidad: normalizar cualquier mensaje de error (axios, Error, string) a
 * lenguaje institucional. Autoridad: 01_PRODUCT_DESIGN_PHILOSOPHY.md §3 — "nunca
 * Error 500", "nunca códigos internos", "explica causa y siguiente paso".
 * No modifica el backend: es una capa de presentación (DEC-FE-02).
 */

/** Ruido técnico que nunca debe llegar a la interfaz. */
const NOISE_PATTERNS = [
  /^request failed with status code \d+$/i,
  /^network error$/i,
  /^timeout of \d+ms exceeded$/i,
  /^(axios)?error:?\s*$/i,
  /^\s*$/,
  /^internal server error$/i,
  /^bad gateway$/i,
  /^service unavailable$/i,
  /^unauthorized$/i,
  /^forbidden$/i,
  /^not found$/i,
];

/** Señales de que el texto es una traza y no un mensaje para personas. */
const TRACE_SIGNALS = [
  /sqlstate/i,
  /\bexception\b/i,
  /\bstack\b/i,
  /\bat [\w$.]+\(/,
  /\/(var|home|usr|srv)\//,
  /\.php(:\d+)?\b/i,
  /\bPDO\b/,
  /undefined (index|variable|method)/i,
  /^\s*[{[]/,
];

/** Copy por estado HTTP. Cada uno explica causa y siguiente paso. */
const BY_STATUS = {
  0:   'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.',
  400: 'La solicitud tiene datos incompletos o inválidos. Revisa el formulario.',
  401: 'Correo o contraseña no coinciden. Verifica ambos campos.',
  403: 'Tu rol no tiene permiso para esta acción.',
  404: 'No encontramos lo que buscabas. Puede haber cambiado o haberse cerrado.',
  408: 'La operación tardó más de lo esperado. No sabemos si se completó: consulta antes de repetirla.',
  409: 'Alguien más modificó esta información mientras trabajabas. Recarga para ver el estado actual.',
  422: 'Algunos datos no cumplen las reglas institucionales. Revisa los campos marcados.',
  429: 'Demasiados intentos seguidos. Espera un momento antes de volver a intentar.',
  500: 'El servidor no pudo completar la operación. Vuelve a intentarlo; si persiste, avisa a soporte institucional.',
  502: 'El servicio no responde en este momento. Reintenta en unos segundos.',
  503: 'El servicio está temporalmente fuera de línea. Reintenta en unos minutos.',
  504: 'El servidor tardó demasiado en responder. Reintenta la operación.',
};

/**
 * Limpia un mensaje del backend: quita códigos entre paréntesis al final,
 * prefijos de error, comillas sobrantes y puntuación duplicada.
 */
export const cleanServerMessage = (raw) => {
  if (typeof raw !== 'string') return '';
  let text = raw.trim();
  if (!text) return '';

  if (TRACE_SIGNALS.some((re) => re.test(text))) return '';

  // "Credenciales incorrectas (P)" · "... (U1)" · "... (ERR_42)" · "... (500)"
  text = text.replace(/\s*\(\s*[A-Za-z]{0,6}[-_]?\d{0,4}\s*\)\s*$/, '');
  // "Error: ..." · "Error 500: ..." · "[AUTH] ..."
  text = text.replace(/^\s*(error|aviso|warning)\s*\d*\s*[:\-–]\s*/i, '');
  text = text.replace(/^\s*\[[^\]]{1,24}\]\s*/, '');
  text = text.replace(/\s{2,}/g, ' ').trim();
  text = text.replace(/^["'`]|["'`]$/g, '').trim();

  if (NOISE_PATTERNS.some((re) => re.test(text))) return '';
  // Un mensaje útil tiene al menos dos palabras.
  if (text.split(/\s+/).length < 2) return '';

  if (!/[.!?…]$/.test(text)) text += '.';
  return text.charAt(0).toUpperCase() + text.slice(1);
};

/**
 * Convierte cualquier error en una frase institucional.
 * @param {unknown} error error de axios, Error nativo o string
 * @param {string} [fallback] copy de dominio cuando no hay nada mejor
 */
export const humanizeError = (error, fallback = 'No pudimos completar la acción. Inténtalo de nuevo.') => {
  if (!error) return fallback;

  if (typeof error === 'string') return cleanServerMessage(error) || fallback;

  const status = error?.response?.status ?? (error?.code === 'ECONNABORTED' ? 408 : undefined);
  const payload = error?.response?.data;

  const candidates = [
    payload?.message,
    payload?.error,
    payload?.detail,
    typeof payload === 'string' ? payload : '',
  ];

  for (const candidate of candidates) {
    const clean = cleanServerMessage(candidate);
    if (clean) return clean;
  }

  if (status !== undefined && BY_STATUS[status]) return BY_STATUS[status];
  if (status >= 500) return BY_STATUS[500];

  if (error?.code === 'ERR_NETWORK' || error?.message === 'Network Error') return BY_STATUS[0];
  if (error?.code === 'ECONNABORTED') return BY_STATUS[408];

  return cleanServerMessage(error?.message) || fallback;
};

/**
 * Copy de estado vacío: distingue "no hay registros" de "los filtros no coinciden".
 * FLOW-QRY-01.
 */
export const emptyCopy = ({ filtered, subject = 'registros' }) =>
  filtered
    ? {
        title: `Ningún ${subject.replace(/s$/, '')} coincide con los filtros`,
        description: 'Amplía el rango de fechas o quita algún filtro para ver más resultados.',
      }
    : {
        title: `Sin ${subject}`,
        description: 'Cuando existan datos en el periodo consultado aparecerán aquí.',
      };

/** Traduce estados internos de entrega a lenguaje de usuario (CMP-105). */
export const DELIVERY_COPY = {
  queued:    { label: 'En cola',    help: 'NEXO está preparando el envío.' },
  sending:   { label: 'Enviando',   help: 'El mensaje salió hacia WhatsApp.' },
  sent:      { label: 'Enviado',    help: 'WhatsApp aceptó el mensaje.' },
  delivered: { label: 'Entregado',  help: 'El acudiente recibió el mensaje.' },
  read:      { label: 'Leído',      help: 'El acudiente abrió el mensaje.' },
  replied:   { label: 'Respondido', help: 'Hay una respuesta del acudiente.' },
  failed:    { label: 'No entregado', help: 'WhatsApp rechazó el envío. Verifica el número del acudiente.' },
  unknown:   { label: 'Sin confirmar', help: 'Aún no hay confirmación del proveedor.' },
};

export const deliveryCopy = (status) =>
  DELIVERY_COPY[String(status || '').toLowerCase()] ?? DELIVERY_COPY.unknown;
