/**
 * CMP-011 Badge — estado no interactivo.
 * Siempre texto + semántica; nunca solo color.
 */
import React from 'react';
import { clsx } from 'clsx';

const schemes = {
  neutral:  'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] border-[var(--nx-border)]',
  accent:   'bg-[color-mix(in_oklch,var(--nx-accent)_10%,transparent)] text-[var(--nx-accent)] border-[color-mix(in_oklch,var(--nx-accent)_25%,transparent)]',
  success:  'bg-[color-mix(in_oklch,var(--nx-success)_12%,transparent)] text-[var(--nx-success)] border-[color-mix(in_oklch,var(--nx-success)_25%,transparent)]',
  warning:  'bg-[color-mix(in_oklch,var(--nx-warning)_12%,transparent)] text-[var(--nx-warning)] border-[color-mix(in_oklch,var(--nx-warning)_25%,transparent)]',
  danger:   'bg-[color-mix(in_oklch,var(--nx-danger)_10%,transparent)] text-[var(--nx-danger)] border-[color-mix(in_oklch,var(--nx-danger)_25%,transparent)]',
};

export const Badge = ({ children, scheme = 'neutral', dot, className }) => (
  <span
    className={clsx(
      'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-caption font-medium',
      schemes[scheme],
      className
    )}
  >
    {dot && <span className={clsx('h-1.5 w-1.5 rounded-full', scheme === 'success' ? 'bg-[var(--nx-success)]' : scheme === 'warning' ? 'bg-[var(--nx-warning)]' : scheme === 'danger' ? 'bg-[var(--nx-danger)]' : 'bg-[var(--nx-accent)]')} />}
    {children}
  </span>
);
