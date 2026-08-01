/**
 * CMP-001 (icon-only) Botón con icono.
 * Siempre debe llevar tooltip/aria-label.
 */
import React from 'react';
import { clsx } from 'clsx';

const variants = {
  primary:   'bg-[var(--nx-accent)] text-[var(--nx-accent-text)] hover:bg-[var(--nx-accent-strong)]',
  secondary: 'bg-transparent text-[var(--nx-text-muted)] hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]',
  quiet:     'bg-transparent text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]',
  danger:    'bg-transparent text-[var(--nx-danger)] hover:bg-[color-mix(in_oklch,var(--nx-danger)_var(--nx-subtle-mix),var(--nx-tint-base))]',
};

const sizes = {
  sm: 'h-9 w-9',
  md: 'h-11 w-11',
  lg: 'h-13 w-13',
};

export const IconButton = React.forwardRef(
  ({ children, label, variant = 'secondary', size = 'md', type = 'button', className, ...props }, ref) => (
    <button
      ref={ref}
      type={type}
      aria-label={label ?? props['aria-label']}
      title={label ?? props.title}
      className={clsx(
        'inline-flex items-center justify-center rounded-control transition-colors duration-fast ease-out',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--nx-accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--nx-canvas)]',
        'disabled:pointer-events-none disabled:opacity-45 motion-safe:active:scale-[0.96]',
        variants[variant] ?? variants.secondary,
        sizes[size] ?? sizes.md,
        className
      )}
      {...props}
    >
      {children}
    </button>
  )
);
IconButton.displayName = 'IconButton';
