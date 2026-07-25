/**
 * CMP-011 variante dot
 */
import React from 'react';
import { clsx } from 'clsx';

const schemeColor = {
  success: 'bg-[var(--nx-success)]',
  warning: 'bg-[var(--nx-warning)]',
  danger: 'bg-[var(--nx-danger)]',
  accent: 'bg-[var(--nx-accent)]',
  muted: 'bg-[var(--nx-text-muted)]',
};

export const StatusDot = ({ scheme = 'success', size = 'sm', pulse }) => (
  <span
    className={clsx(
      'inline-block rounded-full',
      size === 'sm' ? 'h-2 w-2' : 'h-2.5 w-2.5',
      schemeColor[scheme],
      pulse && 'animate-pulse'
    )}
  />
);
