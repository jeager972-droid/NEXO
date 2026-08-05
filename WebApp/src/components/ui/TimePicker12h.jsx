/**
 * TimePicker12h — Selector de hora en formato 12h (3:00 PM).
 * Usa los mismos tokens de diseño que Select (CMP-005): h-12, rounded-control,
 * border, focus ring. Convierte internamente a formato 24h (HH:MM) para el backend.
 *
 * Props:
 *   label     — etiqueta del campo
 *   value     — hora en formato 24h "HH:MM" (ej: "15:30")
 *   onChange  — callback que recibe "HH:MM" en formato 24h
 *   required  — marca el campo como obligatorio
 *   leftIcon  — icono opcional a la izquierda (ej: Clock)
 */
import { useId } from 'react';
import { clsx } from 'clsx';

const HOURS_12 = Array.from({ length: 12 }, (_, i) => i + 1); // 1..12
const MINUTES = [0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55];

/** Convierte "HH:MM" (24h) a { hour, minute, period } (12h) */
function to12h(time24) {
  if (!time24) return { hour: '', minute: '', period: '' };
  const [h, m] = time24.split(':').map(Number);
  if (isNaN(h) || isNaN(m)) return { hour: '', minute: '', period: '' };
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h === 0 ? 12 : h > 12 ? h - 12 : h;
  return { hour: hour12, minute: m, period };
}

/** Convierte { hour, minute, period } (12h) a "HH:MM" (24h) */
function to24h(hour, minute, period) {
  if (!hour || minute === '' || !period) return '';
  let h = Number(hour);
  if (period === 'PM' && h !== 12) h += 12;
  if (period === 'AM' && h === 12) h = 0;
  const m = Number(minute);
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

const selectClass = clsx(
  'h-12 w-full appearance-none rounded-control border bg-[var(--nx-surface)] pl-3 pr-9',
  'text-body text-[var(--nx-text)] outline-none cursor-pointer',
  'transition-[border-color,box-shadow] duration-fast ease-out',
  'border-[var(--nx-border)] hover:border-[color-mix(in_oklch,var(--nx-text)_var(--nx-subtle-mix-w),var(--nx-tint-base))] focus:border-[var(--nx-accent)] focus:shadow-[var(--nx-ring)]'
);

export const TimePicker12h = ({ label, value, onChange, required, leftIcon: Icon }) => {
  const auto = useId();
  const id = `nx-tp-${auto}`;
  const { hour, minute, period } = to12h(value);

  const handleChange = (field, val) => {
    const next = {
      hour: field === 'hour' ? val : hour,
      minute: field === 'minute' ? val : minute,
      period: field === 'period' ? val : period,
    };
    const result = to24h(next.hour, next.minute, next.period);
    onChange?.(result);
  };

  return (
    <div className="space-y-2">
      {label && (
        <label htmlFor={id} className="block text-label text-[var(--nx-text)]">
          {label}
          {required && <span className="ml-1 text-[var(--nx-danger)]" aria-hidden>*</span>}
        </label>
      )}
      <div className="flex items-center gap-2">
        {Icon && (
          <Icon size={16} className="shrink-0 text-[var(--nx-text-muted)]" />
        )}
        <div className="relative flex-1">
          <select
            id={id}
            value={hour}
            onChange={(e) => handleChange('hour', e.target.value)}
            className={selectClass}
            aria-label="Hora"
          >
            <option value="" disabled>Hora</option>
            {HOURS_12.map((h) => (
              <option key={h} value={h}>{h}</option>
            ))}
          </select>
          <svg size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[var(--nx-text-muted)]" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <span className="text-body text-[var(--nx-text-muted)] shrink-0">:</span>
        <div className="relative flex-1">
          <select
            value={minute}
            onChange={(e) => handleChange('minute', e.target.value)}
            className={selectClass}
            aria-label="Minutos"
          >
            <option value="" disabled>Min</option>
            {MINUTES.map((m) => (
              <option key={m} value={m}>{String(m).padStart(2, '0')}</option>
            ))}
          </select>
          <svg size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[var(--nx-text-muted)]" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div className="relative w-20 shrink-0">
          <select
            value={period}
            onChange={(e) => handleChange('period', e.target.value)}
            className={selectClass}
            aria-label="AM/PM"
          >
            <option value="" disabled>—</option>
            <option value="AM">AM</option>
            <option value="PM">PM</option>
          </select>
          <svg size={16} className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[var(--nx-text-muted)]" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
      </div>
    </div>
  );
};
