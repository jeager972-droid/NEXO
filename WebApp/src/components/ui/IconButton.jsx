/**
 * CMP-001 (icon-only) Botón con icono.
 * Siempre debe llevar tooltip/aria-label.
 */
import React from 'react';
import { clsx } from 'clsx';

export const IconButton = React.forwardRef(
  ({ children, variant = 'secondary', size = 'md', className, ...props }, ref) => {
    const variants = {
      primary:   'bg-[var(--nx-accent)] text-[var(--nx-accent-text)] hover:opacity-90',
      secondary: 'bg-transparent text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]',
      quiet:     'bg-transparent text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]',
      danger:    'bg-transparent text-[var(--nx-danger)] hover:bg-[color-mix(in_oklch,var(--nx-danger)_8%,transparent)]',
    };
    const sizes = {
      sm: 'h-8 w-8',
      md: 'h-11 w-11',
      lg: 'h-13 w-13',
    };
    return (
      <button
        ref={ref}
        className={clsx(
          'inline-flex items-center justify-center rounded-control transition-all duration-fast ease-out',
          'disabled:opacity-50 disabled:cursor-not-allowed active:scale-[0.98]',
          variants[variant],
          sizes[size],
          className
        )}
        {...props}
      >
        {children}
      </button>
    );
  }
);
IconButton.displayName = 'IconButton';
