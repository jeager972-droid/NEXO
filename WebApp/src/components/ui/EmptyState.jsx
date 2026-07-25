/**
 * CMP-041 Empty/error state
 * Título factual, causa y acción.
 */
import React from 'react';
import { clsx } from 'clsx';

export const EmptyState = ({ icon, title, description, action, className }) => (
  <div className={clsx('flex flex-col items-center justify-center text-center px-6 py-14', className)}>
    {icon && <div className="mb-4 text-[var(--nx-text-muted)]">{icon}</div>}
    {title && <h3 className="text-h3 text-[var(--nx-text)] max-w-md">{title}</h3>}
    {description && <p className="text-body text-[var(--nx-text-muted)] mt-2 max-w-md">{description}</p>}
    {action && <div className="mt-6">{action}</div>}
  </div>
);
