/**
 * NodusGuide — el guía de Nodus (CMP-NEXO, patrón del sistema).
 *
 * Nodus es el sistema y también el asistente: aparece en la esquina
 * inferior derecha y habla UNA burbuja a la vez; el usuario avanza
 * por clic. Aparece y desaparece según contexto — nunca bloquea:
 *  - al terminar el guion, el bot se retira (no queda fijo tapando UI)
 *  - arrastrándolo hacia el centro aparece una X y se descarta
 *  - un nuevo guion lo vuelve a traer
 *
 * script: [{ text, chips?: [{label, action}] }]
 *   action: 'next' | fn()
 *   chips[0] se dibuja como acción primaria; el resto son quiet
 * onStepChange(i) — para spotlight de bloques
 * active — si false, el bot no se muestra (pantalla de bienvenida)
 */
import { useEffect, useRef, useState } from 'react';
import { motion, AnimatePresence, useReducedMotion } from 'framer-motion';
import { ChevronRight, X } from 'lucide-react';
import { clsx } from 'clsx';

const EASE = [0.22, 1, 0.36, 1];
const DISMISS_DIST = 90; // px arrastrados hacia el centro para descartar

export const NodusGuide = ({ script = [], active = true, celebrate = false, onStepChange, onDismiss }) => {
  const [idx, setIdx] = useState(-1);
  const [shown, setShown] = useState('');
  const [dismissed, setDismissed] = useState(false);
  const [dragging, setDragging] = useState(false);
  const [navLift, setNavLift] = useState(0);
  const reduceMotion = useReducedMotion();
  const typingRef = useRef(null);
  const msg = script[idx];

  // La barra inferior de navegación del Layout (~68 px) quedaría tapada
  // por el bot si se anclara al borde: se mide su altura real y el
  // conjunto bot+burbuja se asienta encima. Sin barra (LegalGate,
  // onboarding, desktop con sidebar) vuelve al margen de siempre.
  useEffect(() => {
    const measure = () => {
      const nav = document.querySelector('nav[aria-label="Acciones principales"]');
      setNavLift(nav?.offsetHeight || 0);
    };
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, []);

  // typewriter
  useEffect(() => {
    if (!msg) { setShown(''); return; }
    clearInterval(typingRef.current);
    const tmp = document.createElement('div');
    tmp.innerHTML = msg.text;
    const full = tmp.textContent || '';
    let i = 0;
    setShown('');
    typingRef.current = setInterval(() => {
      i += 2;
      setShown(full.slice(0, i));
      if (i >= full.length) clearInterval(typingRef.current);
    }, 18);
    return () => clearInterval(typingRef.current);
  }, [idx, msg]);

  // reinicia cuando cambia el CONTENIDO del guion (nuevo paso / nuevo
  // contexto) y levanta el descarte — un mensaje nuevo justifica reaparecer.
  // Se compara por llaves/texto, no por identidad del array: el polling de
  // notificaciones reconstruye un array equivalente cada minuto y sin esta
  // comparación la burbuja se reiniciaría a mitad de lectura.
  const prevScriptKey = useRef(null);
  useEffect(() => {
    const key = script.map((m) => m?.dismissKey || m?.text || '').join('|');
    if (key === prevScriptKey.current) return;
    prevScriptKey.current = key;
    setIdx(script.length ? 0 : -1);
    setDismissed(false);
  }, [script]);

  // Avisa qué mensaje está en pantalla — por cambio de CONTENIDO, no solo
  // de índice: cuando un mensaje priorizado se resuelve, el siguiente entra
  // como msg[0] sin que idx se mueva, y aun así hay que notificarlo (el
  // anunciador marca «mostrado» desde aquí).
  const prevMsgKey = useRef(null);
  useEffect(() => {
    const k = msg ? (msg.dismissKey || msg.text) : null;
    if (k === prevMsgKey.current) return;
    prevMsgKey.current = k;
    onStepChange?.(idx);
  }, [idx, msg, onStepChange]);

  useEffect(() => () => clearInterval(typingRef.current), []);

  const typingDone = msg ? shown.length >= (msg.text || '').replace(/<[^>]+>/g, '').length : true;
  const finished = idx >= script.length;
  const botVisible = active && !dismissed && !finished;
  const bubbleVisible = botVisible && !!msg;

  const dismiss = () => {
    setDismissed(true);
    // Se reporta la llave del evento (dismissKey si la hay, si no el texto)
    // para que el emisor decida cuándo un evento nuevo sí reaparece.
    onDismiss?.(script.map((m) => m?.dismissKey || m?.text).filter(Boolean));
  };

  const onDragEnd = (_, info) => {
    setDragging(false);
    // descartar exige soltar el bot SOBRE la X centrada abajo — no basta
    // con arrastrar un poco hacia el centro (evita descartes accidentales)
    const cx = window.innerWidth / 2;
    const cy = window.innerHeight - 96 - 32; // bottom-24 + mitad del círculo
    const d = Math.hypot(info.point.x - cx, info.point.y - cy);
    if (d < DISMISS_DIST + 24) dismiss();
  };

  return (
    <>
      {/* zona de descarte — solo visible mientras se arrastra */}
      <AnimatePresence>
        {dragging && (
          <motion.div
            initial={{ opacity: 0, scale: 0.7 }}
            animate={{ opacity: 1, scale: 1 }}
            exit={{ opacity: 0, scale: 0.7 }}
            className="pointer-events-none fixed left-1/2 bottom-24 z-[60] flex -translate-x-1/2 flex-col items-center"
          >
            <div className="grid h-16 w-16 place-items-center rounded-full border-2 border-dashed border-[var(--nx-danger)] bg-[var(--nx-subtle-bg-danger)] shadow-[0_8px_24px_-8px_oklch(52%_.175_25/.35)]">
              <X size={26} className="text-[var(--nx-danger)]" />
            </div>
            <p className="mt-1 text-center text-[11px] font-semibold text-[var(--nx-danger)]">Soltar para quitar</p>
          </motion.div>
        )}
      </AnimatePresence>

      <div
        className="fixed bottom-4 right-4 sm:bottom-6 sm:right-6 z-50 flex flex-col items-end gap-3"
        style={navLift ? { bottom: navLift + 12 } : undefined}
        aria-live="polite"
      >
        <AnimatePresence>
          {bubbleVisible && (
            <motion.div
              key={idx}
              // con chips la burbuja no es clickeable — solo los botones;
              // sin chips toda la burbuja avanza (role=button + teclado)
              role={msg.chips ? undefined : 'button'}
              tabIndex={msg.chips ? undefined : 0}
              onClick={() => !msg.chips && setIdx(Math.min(idx + 1, script.length))}
              onKeyDown={(e) => { if (!msg.chips && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); setIdx(Math.min(idx + 1, script.length)); } }}
              initial={{ opacity: 0, y: 8, scale: 0.96 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: 4 }}
              transition={{ duration: reduceMotion ? 0 : 0.2, ease: EASE }}
              className={clsx(
                'w-[min(320px,calc(100vw-64px))] text-left',
                !msg.chips && 'cursor-pointer',
                'rounded-[16px_16px_4px_16px] border border-[var(--nx-border)] bg-[var(--nx-surface)] px-4 pt-3 pb-3',
                'shadow-[var(--nx-shadow-medium)] relative'
              )}
            >
              {/* cola centrada sobre el bot (80 px / sm:96 px de diámetro) */}
              <div className="absolute -bottom-[7px] right-[34px] sm:right-[42px] h-3 w-3 rotate-45 border-b border-r border-[var(--nx-border)] bg-[var(--nx-surface)]" />
              <div className="flex items-center gap-2">
                <p className="text-caption font-semibold tracking-wide text-[var(--nx-accent)]">NODUS</p>
                {script.length > 1 && (
                  <span className="text-[11px] tabular-nums text-[var(--nx-text-muted)]" aria-label={`Mensaje ${idx + 1} de ${script.length}`}>
                    {idx + 1} / {script.length}
                  </span>
                )}
                <button
                  type="button"
                  aria-label="Descartar"
                  onClick={(e) => { e.stopPropagation(); dismiss(); }}
                  className="-mr-1 -mt-1 ml-auto rounded-full p-1 text-[var(--nx-text-muted)] transition-colors hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]"
                >
                  <X size={13} />
                </button>
              </div>
              <p className="mt-1.5 min-h-[42px] text-[13.5px] leading-[1.5] text-[var(--nx-text)]">
                {typingDone ? (
                  // al terminar se renderiza el HTML del mensaje (negrillas y
                  // demás), igual que en el prototipo
                  <span dangerouslySetInnerHTML={{ __html: msg.text }} />
                ) : (
                  <>
                    {shown}
                    <span className="ml-0.5 inline-block h-[14px] w-[7px] animate-pulse rounded-[2px] bg-[var(--nx-accent)] align-[-2px]" aria-hidden />
                  </>
                )}
              </p>
              {msg.chips && typingDone ? (
                // chips = botones de verdad: el primero es la acción
                // principal (sólida); los demás quedan quiet — «Ignorar»
                // no compite con «Revisar»
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  {msg.chips.map((c, ci) => (
                    <button
                      key={c.label}
                      type="button"
                      onClick={(e) => { e.stopPropagation(); typeof c.action === 'function' ? c.action() : setIdx(idx + 1); }}
                      className={clsx(
                        'h-9 rounded-control px-3.5 text-[13px] font-semibold transition-colors',
                        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--nx-accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--nx-surface)]',
                        ci === 0
                          ? 'bg-[var(--nx-accent)] text-[var(--nx-accent-text)] shadow-low hover:bg-[var(--nx-accent-strong)]'
                          : 'text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]'
                      )}
                    >
                      {c.label}
                    </button>
                  ))}
                </div>
              ) : !msg.chips ? (
                // sin chips la burbuja entera avanza — la cola es solo un
                // indicio discreto, no un segundo botón junto al contador
                <div className="mt-2.5 flex items-center justify-end" aria-hidden>
                  <span className="inline-flex items-center gap-0.5 text-[12px] font-semibold text-[var(--nx-accent)]">
                    {idx === script.length - 1 ? 'Listo' : 'Siguiente'}
                    <ChevronRight size={13} />
                  </span>
                </div>
              ) : null}
            </motion.div>
          )}
        </AnimatePresence>

        <AnimatePresence>
          {botVisible && (
            <motion.button
              type="button"
              aria-label="Nodus, tu asistente — toca para repetir la explicación o arrastra al centro para quitarla"
              drag
              dragSnapToOrigin
              onDragStart={() => setDragging(true)}
              onDragEnd={onDragEnd}
              onClick={() => { if (idx < 0 || idx >= script.length) setIdx(0); }}
              whileTap={{ scale: 0.92 }}
              initial={{ opacity: 0, y: 40, scale: 0.6 }}
              animate={{
                opacity: 1, y: 0, scale: 1,
                ...(celebrate && !reduceMotion ? { rotate: [0, -6, 4, 0] } : {}),
              }}
              exit={{ opacity: 0, scale: 0.5, y: 24 }}
              transition={{ duration: reduceMotion ? 0 : 0.35, ease: EASE }}
              className={clsx(
                'relative h-20 w-20 sm:h-24 sm:w-24 rounded-full cursor-grab active:cursor-grabbing',
                'drop-shadow-[0_8px_16px_oklch(30%_.08_245/.25)]',
                'nx-bot-float touch-none'
              )}
              style={reduceMotion ? undefined : { animation: 'nx-float 3.2s ease-in-out infinite' }}
            >
              <img src="/imagenbot.png" alt="" className="h-full w-full rounded-full object-contain" draggable={false} />
              <span
                className="nx-bot-ring absolute -inset-1.5 rounded-full border-2 border-[var(--nx-border-accent)]"
                style={{ animation: 'nx-pulse 2.4s var(--nx-ease-out, cubic-bezier(.22,1,.36,1)) infinite' }}
                aria-hidden
              />
              {msg && <span className="absolute right-0.5 top-0.5 h-4 w-4 rounded-full border-[3px] border-[var(--nx-surface)] bg-[var(--nx-accent)]" aria-hidden />}
            </motion.button>
          )}
        </AnimatePresence>
      </div>
    </>
  );
};

export default NodusGuide;
