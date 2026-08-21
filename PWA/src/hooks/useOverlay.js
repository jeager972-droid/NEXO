/**
 * useOverlay / NEXO Institucional
 * Responsabilidad: contrato común de toda superficie transitoria (drawer, diálogo,
 * sheet): cierre con Escape, atrapado de foco, bloqueo de scroll del cuerpo y
 * devolución del foco al disparador.
 * Autoridad: CMP-034, CMP-036, 09_ACCESSIBILITY.md.
 */
import { useCallback, useEffect, useRef } from 'react';

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export const useOverlay = ({ open = true, onClose, initialFocus } = {}) => {
  const containerRef = useRef(null);
  const restoreRef = useRef(null);

  const focusFirst = useCallback(() => {
    const node = containerRef.current;
    if (!node) return;
    const target =
      (initialFocus?.current ?? null) ||
      node.querySelector('[data-nx-autofocus]') ||
      node.querySelector(FOCUSABLE) ||
      node;
    target.focus?.({ preventScroll: true });
  }, [initialFocus]);

  useEffect(() => {
    if (!open) return undefined;

    restoreRef.current = document.activeElement;
    document.body.dataset.nxLock = 'true';

    const raf = requestAnimationFrame(focusFirst);

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.stopPropagation();
        onClose?.();
        return;
      }
      if (event.key !== 'Tab') return;

      const node = containerRef.current;
      if (!node) return;
      const items = [...node.querySelectorAll(FOCUSABLE)].filter(
        (el) => el.offsetParent !== null || el === document.activeElement
      );
      if (items.length === 0) return;

      const first = items[0];
      const last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', onKeyDown, true);

    return () => {
      cancelAnimationFrame(raf);
      document.removeEventListener('keydown', onKeyDown, true);
      delete document.body.dataset.nxLock;
      const restore = restoreRef.current;
      if (restore instanceof HTMLElement) restore.focus?.({ preventScroll: true });
    };
  }, [open, onClose, focusFirst]);

  return containerRef;
};
