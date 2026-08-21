/**
 * CMP-018 Indicador de progreso de flujo
 * Autoridad: SCR-OPS-02, DEC-012 (Goal Gradient: el avance debe ser visible).
 * Muestra donde esta el usuario y cuanto falta, sin prometer pasos que no existen.
 *
 * Diseno inspirado en la landing "Asi opera NEXO":
 *   - Barra de progreso SVG que se llena progresivamente al completar pasos
 *   - strokeDashoffset animado con framer-motion (mismo efecto que scroll scrub
 *     de la landing, pero vinculado al paso actual en lugar del scroll)
 *   - Nodos con efecto glass/transparent (nunca azul solido)
 *
 * Colores: usa --nx-surface-accent y --nx-subtle-bg-accent (pastel/glass)
 * en lugar de --nx-accent solido.
 */
import { motion } from 'framer-motion';
import { Check } from 'lucide-react';
import { cn } from '../../utils/cn';

const EASE = [0.22, 1, 0.36, 1];

export const Stepper = ({ steps = [], current = 0, className }) => {
  const total = steps.length;
  const progress = total > 1 ? current / (total - 1) : 0;

  return (
    <div className={cn('w-full', className)}>
      {/* Track + progress bar */}
      <div className="relative mb-3 h-[3px] w-full overflow-hidden rounded-full bg-[var(--nx-surface-subtle)]">
        <motion.div
          className="absolute inset-y-0 left-0 rounded-full bg-[var(--nx-accent)]"
          style={{ filter: 'drop-shadow(0 0 4px color-mix(in oklch, var(--nx-accent) 40%, transparent))' }}
          initial={{ width: 0 }}
          animate={{ width: `${progress * 100}%` }}
          transition={{ duration: 0.5, ease: EASE }}
        />
      </div>

      {/* Step labels con nodos glass */}
      <ol className="flex flex-wrap items-center gap-x-3 gap-y-2">
        {steps.map((step, index) => {
          const done = index < current;
          const active = index === current;
          return (
            <li key={step} className="flex items-center gap-3">
              <div className="flex items-center gap-2">
                <span
                  aria-hidden
                  className={cn(
                    'grid h-6 w-6 place-items-center rounded-full border text-caption font-bold transition-all duration-standard ease-out',
                    done && 'border-[var(--nx-border-accent)] bg-[var(--nx-surface-accent)] text-[var(--nx-accent)]',
                    active && !done && 'border-[var(--nx-border-accent)] bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--nx-accent)_18%,transparent)]',
                    !done && !active && 'border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]'
                  )}
                >
                  {done ? <Check size={13} strokeWidth={3} /> : index + 1}
                </span>
                <span
                  aria-current={active ? 'step' : undefined}
                  className={cn(
                    'text-caption font-semibold transition-colors duration-standard',
                    active ? 'text-[var(--nx-accent)]' : done ? 'text-[var(--nx-text)]' : 'text-[var(--nx-text-muted)]'
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
    </div>
  );
};
