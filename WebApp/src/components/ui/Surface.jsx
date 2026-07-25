/**
 * Superficie contenedora.
 */
import React from 'react';
import { clsx } from 'clsx';

export const Surface = React.forwardRef(
  ({ children, className, elevated, ...props }, ref) => (
    <div
      ref={ref}
      className={clsx(
        'rounded-surface bg-[var(--nx-surface)] border border-[var(--nx-border)]',
        elevated && 'shadow-medium',
        className
      )}
      {...props}
    >
      {children}
    </div>
  )
);
Surface.displayName = 'Surface';

export const Section = ({ title, subtitle, children, action, className }) => (
  <section className={clsx('space-y-4', className)}>
    <div className="flex items-end justify-between gap-4">
      <div>
        {title && <h2 className="text-h2 text-[var(--nx-text)]">{title}</h2>}
        {subtitle && <p className="text-body-sm text-[var(--nx-text-muted)] mt-1">{subtitle}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
    {children}
  </section>
);
