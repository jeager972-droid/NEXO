/**
 * CMP-002 Campo de texto / CMP-003 Textarea
 * Label persistente arriba, 48 px altura, errores junto al campo.
 */
import React from 'react';
import { clsx } from 'clsx';

const base =
  'w-full rounded-control border bg-[var(--nx-surface)] text-[var(--nx-text)] placeholder:text-[var(--nx-text-muted)] ' +
  'py-2.5 text-body outline-none ' +
  'border-[var(--nx-border)] hover:border-[var(--nx-text-muted)] focus:border-[var(--nx-accent)] ' +
  'disabled:opacity-50 disabled:cursor-not-allowed transition-colors duration-fast';

export const Input = React.forwardRef(
  ({ label, error, help, leftIcon, rightIcon, className, ...props }, ref) => (
    <div className={clsx('space-y-1.5', className)}>
      {label && (
        <label className="block text-label text-[var(--nx-text)]">
          {label}
        </label>
      )}
      <div className="relative">
        {leftIcon && (
          <span className="absolute inset-y-0 left-3 flex items-center text-[var(--nx-text-muted)] pointer-events-none">
            {leftIcon}
          </span>
        )}
        <input
          ref={ref}
          className={clsx(base, 'h-12', leftIcon ? 'pl-10' : 'px-3.5', rightIcon ? 'pr-10' : 'px-3.5')}
          {...props}
        />
        {rightIcon && (
          <span className="absolute inset-y-0 right-3 flex items-center text-[var(--nx-text-muted)]">
            {rightIcon}
          </span>
        )}
      </div>
      {error && <p className="text-caption text-[var(--nx-danger)]">{error}</p>}
      {help && !error && <p className="text-caption text-[var(--nx-text-muted)]">{help}</p>}
    </div>
  )
);
Input.displayName = 'Input';

export const Textarea = React.forwardRef(
  ({ label, error, help, className, rows = 3, ...props }, ref) => (
    <div className={clsx('space-y-1.5', className)}>
      {label && (
        <label className="block text-label text-[var(--nx-text)]">
          {label}
        </label>
      )}
      <textarea ref={ref} rows={rows} className={clsx(base, 'min-h-[5.5rem] resize-y')} {...props} />
      {error && <p className="text-caption text-[var(--nx-danger)]">{error}</p>}
      {help && !error && <p className="text-caption text-[var(--nx-text-muted)]">{help}</p>}
    </div>
  )
);
Textarea.displayName = 'Textarea';
