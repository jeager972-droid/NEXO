/**
 * CMP-020 Card
 * Resumen autónomo o inicio de acción. Sin anidación.
 */
import React from 'react';
import { clsx } from 'clsx';

export const Card = React.forwardRef(
  ({ children, className, asAction, onClick, ...props }, ref) => (
    <div
      ref={ref}
      onClick={onClick}
      className={clsx(
        'rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] p-5',
        asAction && 'cursor-pointer hover:border-[var(--nx-accent)] hover:shadow-medium transition-all duration-fast',
        className
      )}
      {...props}
    >
      {children}
    </div>
  )
);
Card.displayName = 'Card';

export const CardHeader = ({ title, subtitle, action, children }) => (
  <div className="flex items-start justify-between gap-4 mb-4">
    <div className="min-w-0">
      {title && <h3 className="text-h3 text-[var(--nx-text)]">{title}</h3>}
      {subtitle && <p className="text-body-sm text-[var(--nx-text-muted)] mt-1">{subtitle}</p>}
    </div>
    {action && <div className="shrink-0">{action}</div>}
    {children}
  </div>
);
