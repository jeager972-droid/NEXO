/**
 * TimeInput12h — Wrapper sobre <Input type="time"> que muestra el valor
 * en formato 12h (1:00 PM) en la UI. El reloj nativo del navegador sigue
 * funcionando igual: el input real está encima con texto transparente,
 * y debajo se muestra el texto formateado en 12h.
 *
 * El valor (value/onChange) sigue siendo "HH:MM" en formato 24h para el backend.
 */
import { useId } from 'react';
import { Clock } from 'lucide-react';
import { clsx } from 'clsx';

/** Convierte "HH:MM" (24h) a "h:mm AM/PM" (12h) para display */
function format12h(time24) {
  if (!time24) return '';
  const [h, m] = time24.split(':').map(Number);
  if (isNaN(h) || isNaN(m)) return '';
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h === 0 ? 12 : h > 12 ? h - 12 : h;
  return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
}

const fieldClass = clsx(
  'w-full rounded-control border bg-[var(--nx-surface)] text-body',
  'outline-none transition-[border-color,box-shadow] duration-fast ease-out',
  'border-[var(--nx-border)] hover:border-[color-mix(in_oklch,var(--nx-text)_var(--nx-subtle-mix-w),var(--nx-tint-base))] focus:border-[var(--nx-accent)] focus:shadow-[var(--nx-ring)]'
);

export const TimeInput12h = ({ label, value, onChange, required, leftIcon: Icon, className }) => {
  const auto = useId();
  const id = `nx-ti-${auto}`;
  const display = format12h(value);

  return (
    <div className={clsx('space-y-2', className)}>
      {label && (
        <div className="flex items-baseline justify-between gap-3">
          <label htmlFor={id} className="block text-label text-[var(--nx-text)]">
            {label}
            {required && <span className="ml-0.5 text-[var(--nx-text-muted)]" aria-hidden>*</span>}
          </label>
        </div>
      )}
      <div className="relative">
        {Icon && (
          <span className="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[var(--nx-text-muted)] z-10">
            <Icon size={16} />
          </span>
        )}
        {/* Texto visible en formato 12h */}
        <div
          className={clsx(
            fieldClass,
            'h-12 flex items-center pointer-events-none',
            Icon ? 'pl-11' : 'pl-4',
            'pr-4',
            display ? 'text-[var(--nx-text)]' : 'text-[var(--nx-text-muted)]'
          )}
        >
          {display || '--:--'}
        </div>
        {/* Input nativo transparente encima — maneja el reloj y el valor */}
        <input
          id={id}
          type="time"
          required={required}
          value={value}
          onChange={(e) => onChange?.(e.target.value)}
          className={clsx(
            fieldClass,
            'absolute inset-0 h-12',
            Icon ? 'pl-11' : 'pl-4',
            'pr-4',
            'text-transparent caret-transparent cursor-pointer'
          )}
        />
      </div>
    </div>
  );
};
