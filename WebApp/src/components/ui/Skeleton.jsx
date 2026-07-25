/**
 * CMP-039 Skeleton
 * Estructura de la UI que se va a cargar.
 */
import React from 'react';
import { clsx } from 'clsx';

export const Skeleton = ({ className, children }) => (
  <div className={clsx('animate-skeleton rounded-control bg-[var(--nx-surface-subtle)]', className)}>
    {children}
  </div>
);

export const SkeletonText = ({ lines = 1, className }) => (
  <div className={clsx('space-y-2', className)}>
    {Array.from({ length: lines }).map((_, i) => (
      <Skeleton key={i} className="h-4 w-full" />
    ))}
  </div>
);
