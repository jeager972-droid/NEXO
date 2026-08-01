/**
 * CMP-034 Drawer / CMP-036 Diálogo / CMP-107 Confirmación crítica
 * Autoridad: 03_DESIGN_SYSTEM.md §3.8, 08_MICRO_INTERACTIONS.md §3.
 * Una sola implementación para todas las superficies transitorias: mismo velo,
 * misma curva, mismo contrato de teclado. La confirmación es proporcional al daño.
 */
import { motion } from 'framer-motion';
import { AlertTriangle, X } from 'lucide-react';
import { cn } from '../../utils/cn';
import { useOverlay } from '../../hooks/useOverlay';
import { Button } from './Button';
import { IconButton } from './IconButton';

const EASE = [0.22, 1, 0.36, 1];
const VEIL = 'bg-[color-mix(in_oklch,var(--nx-text)_42%,transparent)]';

const Veil = ({ onClick }) => (
  <motion.div
    initial={{ opacity: 0 }}
    animate={{ opacity: 1 }}
    exit={{ opacity: 0 }}
    transition={{ duration: 0.2, ease: EASE }}
    onClick={onClick}
    className={cn('fixed inset-0 z-40 backdrop-blur-[2px]', VEIL)}
  />
);

const widths = {
  sm: 'sm:max-w-[420px]',
  md: 'sm:max-w-[560px]',
  lg: 'sm:max-w-[720px]',
  xl: 'sm:max-w-[880px]',
};

/**
 * Panel lateral. En compact ocupa el ancho completo; en wide un panel derecho.
 * `context` sitúa al usuario: qué objeto está mirando (05 §8).
 */
export const Drawer = ({
  title,
  context,
  onClose,
  children,
  footer,
  size = 'md',
  className,
}) => {
  const ref = useOverlay({ onClose });

  return (
    <>
      <Veil onClick={onClose} />
      <motion.div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        initial={{ x: '100%' }}
        animate={{ x: 0 }}
        exit={{ x: '100%' }}
        transition={{ duration: 0.25, ease: EASE }}
        className={cn(
          'fixed inset-y-0 right-0 z-50 flex w-full flex-col overflow-hidden',
          'border-l border-[var(--nx-border)] bg-[var(--nx-canvas)] shadow-dialog',
          widths[size] ?? widths.md,
          className
        )}
      >
        <header className="flex shrink-0 items-start gap-4 border-b border-[var(--nx-border)] bg-[var(--nx-surface)] px-6 py-4">
          <div className="min-w-0 flex-1">
            {context && (
              <p className="mb-1 text-eyebrow uppercase text-[var(--nx-text-muted)]">{context}</p>
            )}
            <h2 className="truncate text-h3 text-[var(--nx-text)]">{title}</h2>
          </div>
          <IconButton label="Cerrar panel" onClick={onClose} size="sm">
            <X size={18} aria-hidden />
          </IconButton>
        </header>

        <div className="flex-1 overflow-y-auto overscroll-contain">{children}</div>

        {footer && (
          <footer className="shrink-0 border-t border-[var(--nx-border)] bg-[var(--nx-surface)] px-6 py-4">
            {footer}
          </footer>
        )}
      </motion.div>
    </>
  );
};

/** Diálogo centrado para decisiones cortas. */
export const Dialog = ({ title, description, onClose, children, footer, size = 'sm', className }) => {
  const ref = useOverlay({ onClose });

  return (
    <>
      <Veil onClick={onClose} />
      <div className="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center">
        <motion.div
          ref={ref}
          role="dialog"
          aria-modal="true"
          aria-label={title}
          tabIndex={-1}
          initial={{ opacity: 0, scale: 0.97, y: 8 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.97, y: 8 }}
          transition={{ duration: 0.2, ease: EASE }}
          className={cn(
            'w-full rounded-panel border border-[var(--nx-border)] bg-[var(--nx-surface)] p-6 shadow-dialog',
            widths[size] ?? widths.sm,
            className
          )}
        >
          {title && <h2 className="text-h3 text-[var(--nx-text)]">{title}</h2>}
          {description && (
            <p className="mt-2 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">{description}</p>
          )}
          {children && <div className="mt-5">{children}</div>}
          {footer && <div className="mt-6 flex flex-wrap justify-end gap-3">{footer}</div>}
        </motion.div>
      </div>
    </>
  );
};

/**
 * Confirmación proporcional al daño (DEC-013).
 * El verbo de la acción es específico: nunca "Aceptar".
 */
export const ConfirmDialog = ({
  title,
  description,
  consequence,
  confirmLabel,
  cancelLabel = 'Cancelar',
  onConfirm,
  onClose,
  loading,
  destructive,
}) => (
  <Dialog
    title={undefined}
    onClose={onClose}
    footer={
      <>
        <Button variant="secondary" onClick={onClose} disabled={loading}>
          {cancelLabel}
        </Button>
        <Button
          variant={destructive ? 'danger' : 'primary'}
          onClick={onConfirm}
          loading={loading}
          data-nx-autofocus
        >
          {confirmLabel}
        </Button>
      </>
    }
  >
    <div
      className={cn(
        'mb-5 grid h-12 w-12 place-items-center rounded-surface',
        destructive
          ? 'bg-[color-mix(in_oklch,var(--nx-danger)_var(--nx-subtle-mix),var(--nx-tint-base))] text-[var(--nx-danger)]'
          : 'bg-[color-mix(in_oklch,var(--nx-accent)_var(--nx-subtle-mix),var(--nx-tint-base))] text-[var(--nx-accent)]'
      )}
    >
      <AlertTriangle size={22} strokeWidth={1.75} aria-hidden />
    </div>
    <h2 className="text-h3 text-[var(--nx-text)]">{title}</h2>
    {description && (
      <p className="mt-2 text-body-sm leading-relaxed text-[var(--nx-text-muted)]">{description}</p>
    )}
    {consequence && (
      <p className="mt-4 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3 text-body-sm text-[var(--nx-text)]">
        {consequence}
      </p>
    )}
  </Dialog>
);
