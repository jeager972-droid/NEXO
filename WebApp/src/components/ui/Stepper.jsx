/**
 * CMP-018 Indicador de progreso de flujo
 * Autoridad: SCR-OPS-02, DEC-012 (Goal Gradient: el avance debe ser visible).
 * Muestra dónde está el usuario y cuánto falta, sin prometer pasos que no existen.
 */
import { Check } from 'lucide-react';
import { cn } from '../../utils/cn';

export const Stepper = ({ steps = [], current = 0, className }) => (
  <ol className={cn('flex flex-wrap items-center gap-x-3 gap-y-2', className)}>
    {steps.map((step, index) => {
      const done = index < current;
      const active = index === current;
      return (
        <li key={step} className="flex items-center gap-3">
          <div className="flex items-center gap-2">
            <span
              aria-hidden
              className={cn(
                'grid h-6 w-6 place-items-center rounded-full border text-caption font-bold transition-colors duration-standard ease-out',
                done && 'border-[var(--nx-accent)] bg-[var(--nx-accent)] text-[var(--nx-accent-text)]',
                active && !done && 'border-[var(--nx-accent)] bg-[var(--nx-accent)] text-[var(--nx-accent-text)]',
                !done && !active && 'border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]'
              )}
            >
              {done ? <Check size={13} strokeWidth={3} /> : index + 1}
            </span>
            <span
              aria-current={active ? 'step' : undefined}
              className={cn(
                'text-caption font-semibold',
                active ? 'text-[var(--nx-accent)]' : 'text-[var(--nx-text-muted)]'
              )}
            >
              {step}
            </span>
          </div>
          {index < steps.length - 1 && (
            <span aria-hidden className="hidden h-px w-6 bg-[var(--nx-border)] sm:block" />
          )}
        </li>
      );
    })}
  </ol>
);
