/**
 * CMP-005 Select
 * Autoridad: 03_DESIGN_SYSTEM.md §3.3. Chevron de iconografía lineal (no glífo),
 * halo de foco de 3 px y placeholder que nunca es una opción válida.
 */
import React, { useId } from 'react';
import { AlertCircle, ChevronDown } from 'lucide-react';
import { clsx } from 'clsx';

export const Select = React.forwardRef(
  (
    { label, error, help, hint, options = [], placeholder, className, id: idProp, required, ...props },
    ref
  ) => {
    const auto = useId();
    const id = idProp ?? `nx-${auto}`;
    return (
      <div className={clsx('space-y-2', className)}>
        {label && (
          <div className="flex items-baseline justify-between gap-3">
            <label htmlFor={id} className="block text-label text-[var(--nx-text)]">
              {label}
              {required && <span className="ml-1 text-[var(--nx-danger)]" aria-hidden>*</span>}
            </label>
            {hint && <span className="text-caption text-[var(--nx-text-muted)]">{hint}</span>}
          </div>
        )}
        <div className="relative">
          <select
            ref={ref}
            id={id}
            required={required}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? `${id}-error` : help ? `${id}-help` : undefined}
            className={clsx(
              'h-12 w-full appearance-none rounded-control border bg-[var(--nx-surface)] pl-4 pr-11',
              'text-body text-[var(--nx-text)] outline-none',
              'transition-[border-color,box-shadow] duration-fast ease-out',
              'disabled:cursor-not-allowed disabled:opacity-50',
              error
                ? 'border-[var(--nx-danger)] focus:shadow-[var(--nx-ring-danger)]'
                : 'border-[var(--nx-border)] hover:border-[color-mix(in_oklch,var(--nx-text)_var(--nx-subtle-mix-w),var(--nx-tint-base))] focus:border-[var(--nx-accent)] focus:shadow-[var(--nx-ring)]'
            )}
            {...props}
          >
            {placeholder && (
              <option value="" disabled>
                {placeholder}
              </option>
            )}
            {options.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
          <ChevronDown
            size={16}
            aria-hidden
            className="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-[var(--nx-text-muted)]"
          />
        </div>
        {error ? (
          <p id={`${id}-error`} className="flex items-start gap-1.5 text-caption text-[var(--nx-danger)]">
            <AlertCircle size={13} className="mt-px shrink-0" aria-hidden />
            <span>{error}</span>
          </p>
        ) : (
          help && (
            <p id={`${id}-help`} className="text-caption text-[var(--nx-text-muted)]">
              {help}
            </p>
          )
        )}
      </div>
    );
  }
);
Select.displayName = 'Select';
