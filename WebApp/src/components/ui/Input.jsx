/**
 * CMP-002 Campo de texto / CMP-003 Textarea / CMP-004 Contraseña
 * Autoridad: 03_DESIGN_SYSTEM.md §3.2 · nexo_screens.html .nx-input
 * Label persistente arriba, 48 px de alto, foco con halo de 3 px,
 * error junto al campo y descripción enlazada por aria-describedby.
 */
import React, { useId, useState } from 'react';
import { AlertCircle, Eye, EyeOff } from 'lucide-react';
import { clsx } from 'clsx';

const field = (invalid) =>
  clsx(
    'w-full rounded-control border bg-[var(--nx-surface)] text-body text-[var(--nx-text)]',
    'placeholder:text-[var(--nx-text-muted)] placeholder:opacity-60',
    'outline-none transition-[border-color,box-shadow] duration-fast ease-out',
    'disabled:cursor-not-allowed disabled:opacity-50',
    invalid
      ? 'border-[var(--nx-danger)] focus:shadow-[var(--nx-ring-danger)]'
      : 'border-[var(--nx-border)] hover:border-[color-mix(in_oklch,var(--nx-text)_28%,transparent)] focus:border-[var(--nx-accent)] focus:shadow-[var(--nx-ring)]'
  );

const FieldShell = ({ id, label, error, help, hint, required, children, className }) => (
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
    {children}
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

export const Input = React.forwardRef(
  ({ label, error, help, hint, leftIcon, rightSlot, rightIcon, className, id: idProp, required, ...props }, ref) => {
    const auto = useId();
    const id = idProp ?? `nx-${auto}`;
    const trailing = rightSlot ?? rightIcon;
    return (
      <FieldShell id={id} label={label} error={error} help={help} hint={hint} required={required} className={className}>
        <div className="relative">
          {leftIcon && (
            <span className="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[var(--nx-text-muted)]">
              {leftIcon}
            </span>
          )}
          <input
            ref={ref}
            id={id}
            required={required}
            aria-invalid={error ? true : undefined}
            aria-describedby={error ? `${id}-error` : help ? `${id}-help` : undefined}
            className={clsx(
              field(!!error),
              'h-12',
              leftIcon ? 'pl-11' : 'pl-4',
              trailing ? 'pr-12' : 'pr-4'
            )}
            {...props}
          />
          {trailing && (
            <span className="absolute inset-y-0 right-1.5 flex items-center text-[var(--nx-text-muted)]">
              {trailing}
            </span>
          )}
        </div>
      </FieldShell>
    );
  }
);
Input.displayName = 'Input';

/**
 * Contraseña con alternancia de visibilidad.
 * El botón vive dentro del contenedor del input, no del campo completo:
 * así queda centrado vertical sin depender del alto del label.
 */
export const PasswordInput = React.forwardRef(
  ({ showLabel = 'Mostrar contraseña', hideLabel = 'Ocultar contraseña', ...props }, ref) => {
    const [visible, setVisible] = useState(false);
    return (
      <Input
        ref={ref}
        type={visible ? 'text' : 'password'}
        rightSlot={
          <button
            type="button"
            onClick={() => setVisible((v) => !v)}
            aria-label={visible ? hideLabel : showLabel}
            title={visible ? hideLabel : showLabel}
            className={clsx(
              'grid h-9 w-9 place-items-center rounded-control text-[var(--nx-text-muted)]',
              'transition-colors duration-fast hover:bg-[var(--nx-surface-subtle)] hover:text-[var(--nx-text)]',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--nx-accent)]'
            )}
          >
            {visible ? <EyeOff size={17} aria-hidden /> : <Eye size={17} aria-hidden />}
          </button>
        }
        {...props}
      />
    );
  }
);
PasswordInput.displayName = 'PasswordInput';

export const Textarea = React.forwardRef(
  ({ label, error, help, hint, className, rows = 3, id: idProp, required, ...props }, ref) => {
    const auto = useId();
    const id = idProp ?? `nx-${auto}`;
    return (
      <FieldShell id={id} label={label} error={error} help={help} hint={hint} required={required} className={className}>
        <textarea
          ref={ref}
          id={id}
          rows={rows}
          required={required}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? `${id}-error` : help ? `${id}-help` : undefined}
          className={clsx(field(!!error), 'min-h-[6rem] resize-y px-4 py-3 leading-relaxed')}
          {...props}
        />
      </FieldShell>
    );
  }
);
Textarea.displayName = 'Textarea';
