/**
 * CMP-005 Select / CMP-006 Combobox simple
 */
import React from 'react';
import { clsx } from 'clsx';

export const Select = React.forwardRef(
  ({ label, error, help, options = [], placeholder, className, ...props }, ref) => (
    <div className={clsx('space-y-1.5', className)}>
      {label && (
        <label className="block text-label text-[var(--nx-text)]">
          {label}
        </label>
      )}
      <div className="relative">
        <select
          ref={ref}
          className={
            'w-full h-12 rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] ' +
            'text-body text-[var(--nx-text)] px-3.5 pr-8 outline-none ' +
            'hover:border-[var(--nx-text-muted)] focus:border-[var(--nx-accent)] ' +
            'disabled:opacity-50 transition-colors duration-fast appearance-none'
          }
          {...props}
        >
          {placeholder && <option value="" disabled>{placeholder}</option>}
          {options.map((opt) => (
            <option key={opt.value} value={opt.value}>
              {opt.label}
            </option>
          ))}
        </select>
        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[var(--nx-text-muted)]">▼</span>
      </div>
      {error && <p className="text-caption text-[var(--nx-danger)]">{error}</p>}
      {help && !error && <p className="text-caption text-[var(--nx-text-muted)]">{help}</p>}
    </div>
  )
);
Select.displayName = 'Select';
