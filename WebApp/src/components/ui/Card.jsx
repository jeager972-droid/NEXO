/**
 * CMP-020 Card
 * Resumen autónomo o iniciador de acción (DEC-IA-05). Sin anidación de tarjetas.
 * Cuando la tarjeta es la acción, se renderiza como <button> para que exista
 * en el orden de tabulación y muestre foco visible (09_ACCESSIBILITY).
 */
import React from 'react';
import { cn } from '../../utils/cn';

/** Tonos cerrados: el color acompaña al texto, nunca lo sustituye. */
const tones = {
  neutral: 'border-[var(--nx-border)]',
  accent:  'border-[color-mix(in_oklch,var(--nx-accent)_30%,var(--nx-border))]',
  success: 'border-[color-mix(in_oklch,var(--nx-success)_30%,var(--nx-border))]',
  warning: 'border-[color-mix(in_oklch,var(--nx-warning)_34%,var(--nx-border))]',
  danger:  'border-[color-mix(in_oklch,var(--nx-danger)_32%,var(--nx-border))]',
};

/** Franja de 3 px a la izquierda: patrón de riesgo del moodboard (.nx-risk). */
const edges = {
  neutral: '',
  accent:  'border-l-[3px] border-l-[var(--nx-accent)]',
  success: 'border-l-[3px] border-l-[var(--nx-success)]',
  warning: 'border-l-[3px] border-l-[var(--nx-warning)]',
  danger:  'border-l-[3px] border-l-[var(--nx-danger)]',
};

export const Card = React.forwardRef(
  ({ children, className, asAction, tone = 'neutral', edge, onClick, ...props }, ref) => {
    const shared = cn(
      'rounded-surface border bg-[var(--nx-surface)] p-5 text-left',
      tones[tone] ?? tones.neutral,
      edge && (edges[tone] || edges.accent),
      asAction &&
        cn(
          'nx-pressable block w-full cursor-pointer',
          'hover:border-[color-mix(in_oklch,var(--nx-accent)_45%,var(--nx-border))] hover:shadow-medium',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--nx-accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--nx-canvas)]'
        ),
      className
    );

    if (asAction) {
      return (
        <button ref={ref} type="button" onClick={onClick} className={shared} {...props}>
          {children}
        </button>
      );
    }

    return (
      <div ref={ref} onClick={onClick} className={shared} {...props}>
        {children}
      </div>
    );
  }
);
Card.displayName = 'Card';

export const CardHeader = ({ title, subtitle, action, icon, children, className }) => (
  <div className={cn('mb-4 flex items-start justify-between gap-4', className)}>
    <div className="flex min-w-0 items-start gap-3">
      {icon && (
        <span className="mt-0.5 grid h-9 w-9 shrink-0 place-items-center rounded-control bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]">
          {icon}
        </span>
      )}
      <div className="min-w-0">
        {title && <h3 className="text-h3 text-[var(--nx-text)]">{title}</h3>}
        {subtitle && <p className="mt-1 text-body-sm text-[var(--nx-text-muted)]">{subtitle}</p>}
      </div>
    </div>
    {action && <div className="shrink-0">{action}</div>}
    {children}
  </div>
);
