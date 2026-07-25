/**
 * CMP-001 Botón
 * Autoridad: 03_DESIGN_SYSTEM.md §3.1
 * Variantes: primary, secondary, quiet, danger.
 * Tamaños 44 y 52 px. Un primary por estado.
 */
import React from 'react';
import { Loader2 } from 'lucide-react';
import { clsx } from 'clsx';

const variants = {
  primary:   'bg-[var(--nx-accent)] text-[var(--nx-accent-text)] shadow-low hover:shadow-medium',
  secondary: 'bg-[var(--nx-surface)] text-[var(--nx-text)] border border-[var(--nx-border)] hover:bg-[var(--nx-surface-subtle)]',
  quiet:     'bg-transparent text-[var(--nx-text-muted)] hover:text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)]',
  danger:    'bg-[var(--nx-danger)] text-white shadow-low hover:opacity-90',
};

const sizes = {
  sm: 'h-9 px-3 text-body-sm gap-1.5',
  md: 'h-11 px-4 text-body gap-2',
  lg: 'h-13 px-5 text-body gap-2',
};

export const Button = React.forwardRef(
  ({ variant = 'primary', size = 'md', loading, leftIcon, rightIcon, children, className, disabled, ...props }, ref) => {
    const isDisabled = disabled || loading;
    return (
      <button
        ref={ref}
        disabled={isDisabled}
        aria-busy={loading || undefined}
        className={clsx(
          'inline-flex items-center justify-center rounded-control font-label transition-all duration-fast ease-out',
          'disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none',
          'active:scale-[0.98]',
          variants[variant],
          sizes[size],
          className
        )}
        {...props}
      >
        {loading ? <Loader2 size={size === 'sm' ? 14 : 18} className="animate-spin" aria-hidden /> : leftIcon}
        <span>{children}</span>
        {rightIcon}
      </button>
    );
  }
);
Button.displayName = 'Button';
