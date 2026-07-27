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
  primary:
    'bg-[var(--nx-accent)] text-[var(--nx-accent-text)] shadow-low ' +
    'hover:bg-[var(--nx-accent-strong)] hover:shadow-medium',
  secondary:
    'bg-[var(--nx-surface)] text-[var(--nx-text)] border border-[var(--nx-border)] ' +
    'hover:bg-[var(--nx-surface-subtle)] hover:border-[color-mix(in_oklch,var(--nx-text)_22%,transparent)]',
  quiet:
    'bg-transparent text-[var(--nx-accent)] ' +
    'hover:bg-[color-mix(in_oklch,var(--nx-accent)_9%,transparent)]',
  ghost:
    'bg-transparent text-[var(--nx-text-muted)] ' +
    'hover:text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)]',
  danger:
    'bg-[var(--nx-danger)] text-[var(--nx-on-solid)] shadow-low ' +
    'hover:bg-[var(--nx-danger-strong)] hover:shadow-medium',
};

// 03 §3.1: alturas 44 y 52 px. `sm` (36) queda reservado a acciones inline
// dentro de superficies ya densas, donde el área táctil la aporta el contenedor.
const sizes = {
  sm: 'h-9  px-3   text-body-sm gap-1.5',
  md: 'h-11 px-4   text-body-sm gap-2',
  lg: 'h-13 px-6   text-body    gap-2',
};

const iconSize = { sm: 14, md: 16, lg: 18 };

export const Button = React.forwardRef(
  (
    {
      variant = 'primary',
      size = 'md',
      loading,
      loadingLabel,
      leftIcon,
      rightIcon,
      children,
      className,
      disabled,
      block,
      type = 'button',
      ...props
    },
    ref
  ) => {
    const isDisabled = disabled || loading;
    return (
      <button
        ref={ref}
        type={type}
        disabled={isDisabled}
        aria-busy={loading || undefined}
        className={clsx(
          'relative inline-flex select-none items-center justify-center whitespace-nowrap rounded-control font-label',
          'transition-[background-color,border-color,box-shadow,transform,color] duration-fast ease-out',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--nx-accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--nx-canvas)]',
          'disabled:pointer-events-none disabled:opacity-45 disabled:shadow-none',
          'motion-safe:active:scale-[0.985]',
          variants[variant] ?? variants.primary,
          sizes[size] ?? sizes.md,
          block && 'w-full',
          className
        )}
        {...props}
      >
        {loading ? (
          <Loader2 size={iconSize[size] ?? 16} className="animate-spin" aria-hidden />
        ) : (
          leftIcon
        )}
        <span>{loading && loadingLabel ? loadingLabel : children}</span>
        {!loading && rightIcon}
      </button>
    );
  }
);
Button.displayName = 'Button';

export const BUTTON_VARIANTS = variants;
