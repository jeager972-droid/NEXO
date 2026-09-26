/**
 * CMP-041 Empty / error / sin permiso
 * Autoridad: 06_USER_FLOWS.md §11 · moodboard `.nx-empty`.
 * Contrato: título factual → causa → acción disponible → alternativa.
 * El tile de 52 px da presencia sin recurrir a ilustraciones decorativas.
 */
import { AlertTriangle, Inbox, Lock, WifiOff } from 'lucide-react';
import { cn } from '../../utils/cn';

const variants = {
  empty:   { tile: 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]', fallback: Inbox },
  error:   { tile: 'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)]', fallback: AlertTriangle },
  denied:  { tile: 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]', fallback: Lock },
  offline: { tile: 'bg-[var(--nx-subtle-bg-warning)] text-[var(--nx-warning)]', fallback: WifiOff },
};

export const EmptyState = ({
  icon,
  title,
  description,
  action,
  secondaryAction,
  variant = 'empty',
  compact,
  className,
}) => {
  const spec = variants[variant] ?? variants.empty;
  const Fallback = spec.fallback;
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center px-6 text-center',
        compact ? 'py-10' : 'py-14',
        className
      )}
      role={variant === 'error' ? 'alert' : undefined}
    >
      <div className={cn('mb-5 grid h-13 w-13 place-items-center rounded-surface', spec.tile)}>
        {icon ?? <Fallback size={22} strokeWidth={1.75} aria-hidden />}
      </div>
      {title && <h3 className="max-w-sm text-h3 text-[var(--nx-text)]">{title}</h3>}
      {description && (
        <p className="mt-2 max-w-[34ch] text-body-sm leading-relaxed text-[var(--nx-text-muted)]">
          {description}
        </p>
      )}
      {(action || secondaryAction) && (
        <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
          {action}
          {secondaryAction}
        </div>
      )}
    </div>
  );
};
