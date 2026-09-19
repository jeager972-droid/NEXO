/**
 * NexusGuide — el guía de Nexus (CMP-NEXO, patrón del sistema).
 *
 * Nexus es el sistema y también el asistente: aparece en la esquina
 * inferior derecha y habla UNA burbuja a la vez; el usuario avanza
 * por clic. Aparece y desaparece según contexto — nunca bloquea:
 *  - al terminar el guion, el bot se retira (no queda fijo tapando UI)
 *  - arrastrándolo hacia el centro aparece una X y se descarta
 *  - un nuevo guion lo vuelve a traer
 *
 * script: [{ text, chips?: [{label, action}] }]
 *   action: 'next' | fn()
 * onStepChange(i) — para spotlight de bloques
 * active — si false, el bot no se muestra (pantalla de bienvenida)
 */
import { useEffect, useRef, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { X } from 'lucide-react';
import { clsx } from 'clsx';

const EASE = [0.22, 1, 0.36, 1];
const DISMISS_DIST = 90; // px arrastrados hacia el centro para descartar

export const NexusGuide = ({ script = [], active = true, celebrate = false, onStepChange, onDismiss }) => {
  const [idx, setIdx] = useState(-1);
  const [shown, setShown] = useState('');
  const [dismissed, setDismissed] = useState(false);
  const [dragging, setDragging] = useState(false);
  const typingRef = useRef(null);
  const msg = script[idx];

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

  // reinicia cuando cambia el guion (nuevo paso / nuevo contexto) y
  // levanta el descarte — un mensaje nuevo justifica reaparecer
  useEffect(() => {
    setIdx(script.length ? 0 : -1);
    setDismissed(false);
  }, [script]);

  useEffect(() => { onStepChange?.(idx); }, [idx, onStepChange]);

  useEffect(() => () => clearInterval(typingRef.current), []);

  const typingDone = msg ? shown.length >= (msg.text || '').replace(/<[^>]+>/g, '').length : true;
  const finished = idx >= script.length;
  const botVisible = active && !dismissed && !finished;
  const bubbleVisible = botVisible && !!msg;

  const dismiss = () => {
    setDismissed(true);
    // El avisado decide qué significa descartar (p.ej. no repetir este
    // mensaje en la sesión) — NexusGuide solo reporta el texto mostrado.
    onDismiss?.(script.map((m) => m?.text).filter(Boolean));
  };

  const onDragEnd = (_, info) => {
    setDragging(false);
    // arrastrar hacia el centro (izquierda/arriba) descarta al bot
    const dist = Math.hypot(Math.max(0, -info.offset.x), Math.max(0, -info.offset.y));
    if (dist > DISMISS_DIST) dismiss();
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
            className="pointer-events-none fixed left-1/2 bottom-24 z-[60] -translate-x-1/2"
          >
            <div className="grid h-16 w-16 place-items-center rounded-full border-2 border-dashed border-[var(--nx-danger)] bg-[var(--nx-subtle-bg-danger)]">
              <X size={26} className="text-[var(--nx-danger)]" />
            </div>
            <p className="mt-1 text-center text-[11px] font-semibold text-[var(--nx-danger)]">Soltar para quitar</p>
          </motion.div>
        )}
      </AnimatePresence>

      <div className="fixed bottom-4 right-4 sm:bottom-6 sm:right-6 z-50 flex flex-col items-end gap-3" aria-live="polite">
        <AnimatePresence>
          {bubbleVisible && (
            <motion.div
              key={idx}
              role="button"
              tabIndex={0}
              onClick={() => !msg.chips && setIdx(Math.min(idx + 1, script.length))}
              onKeyDown={(e) => { if ((e.key === 'Enter' || e.key === ' ') && !msg.chips) setIdx(Math.min(idx + 1, script.length)); }}
              initial={{ opacity: 0, y: 8, scale: 0.96 }}
              animate={{ opacity: 1, y: 0, scale: 1 }}
              exit={{ opacity: 0, y: 4 }}
              transition={{ duration: 0.2, ease: EASE }}
              className={clsx(
                'w-[min(340px,calc(100vw-130px))] cursor-pointer text-left',
                'rounded-[16px_16px_4px_16px] border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4 pb-3',
                'shadow-[0_6px_18px_-6px_oklch(30%_.08_245/.2)] relative'
              )}
            >
              <div className="absolute -bottom-[7px] right-[22px] h-3 w-3 rotate-45 border-b border-r border-[var(--nx-border)] bg-[var(--nx-surface)]" />
              <div className="flex items-start justify-between gap-2">
                <p className="text-caption font-semibold tracking-wide text-[var(--nx-accent)]">NEXUS</p>
                <button
                  type="button"
                  aria-label="Descartar"
                  onClick={(e) => { e.stopPropagation(); dismiss(); }}
                  className="-mr-1 -mt-1 rounded-full p-1 text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]"
                >
                  <X size={13} />
                </button>
              </div>
              <p className="mt-1 min-h-[42px] text-[14.5px] leading-[1.5] text-[var(--nx-text)]">
                {shown}
                {!typingDone && <span className="ml-0.5 inline-block h-[15px] w-[7px] animate-pulse rounded-[2px] bg-[var(--nx-accent)] align-[-2px]" />}
              </p>
              {msg.chips && typingDone && (
                <div className="mt-3 flex flex-wrap gap-2">
                  {msg.chips.map((c) => (
                    <button
                      key={c.label}
                      type="button"
                      onClick={(e) => { e.stopPropagation(); typeof c.action === 'function' ? c.action() : setIdx(idx + 1); }}
                      className="rounded-full border border-[var(--nx-border-accent)] bg-[var(--nx-subtle-bg-accent)] px-4 py-1.5 text-[13px] font-semibold text-[var(--nx-accent)] transition-colors hover:bg-[var(--nx-surface-accent)]"
                    >
                      {c.label}
                    </button>
                  ))}
                </div>
              )}
              <div className="mt-3 flex items-center justify-between">
                <span className="text-caption tabular-nums text-[var(--nx-text-muted)]">{idx + 1} / {script.length}</span>
                {!msg.chips && (
                  <span className="rounded-full bg-[var(--nx-subtle-bg-accent)] px-3.5 py-1.5 text-[13px] font-semibold text-[var(--nx-accent)]">
                    {idx === script.length - 1 ? 'Listo' : 'Siguiente'}
                  </span>
                )}
              </div>
            </motion.div>
          )}
        </AnimatePresence>

        <AnimatePresence>
          {botVisible && (
            <motion.button
              type="button"
              aria-label="Nexus, tu asistente — toca para repetir la explicación o arrastra al centro para quitarla"
              drag
              dragSnapToOrigin
              onDragStart={() => setDragging(true)}
              onDragEnd={onDragEnd}
              onClick={() => { if (idx < 0 || idx >= script.length) setIdx(0); }}
              whileTap={{ scale: 0.92 }}
              initial={{ opacity: 0, y: 40, scale: 0.6 }}
              animate={{
                opacity: 1, y: 0, scale: 1,
                ...(celebrate ? { rotate: [0, -6, 4, 0] } : {}),
              }}
              exit={{ opacity: 0, scale: 0.5, y: 24 }}
              transition={{ duration: 0.35, ease: EASE }}
              className={clsx(
                'relative h-20 w-20 sm:h-24 sm:w-24 rounded-full cursor-grab active:cursor-grabbing',
                'drop-shadow-[0_8px_16px_oklch(30%_.08_245/.25)]',
                'nx-bot-float touch-none'
              )}
              style={{ animation: 'nx-float 3.2s ease-in-out infinite' }}
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

export default NexusGuide;
