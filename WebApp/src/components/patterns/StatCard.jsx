/**
 * CMP-027 Tarjeta de métrica — moodboard .nx-stat
 * Autoridad: 03_DESIGN_SYSTEM.md §3.5, 04_VISUAL_LANGUAGE.md §12.
 * Estructura: icono + etiqueta + cifra tabular + estado textual + tendencia + periodo.
 * La cifra usa tabular-nums para que no bailen los dígitos al actualizar.
 */
import { cn } from '../../utils/cn';
import { TrendingUp, TrendingDown, Minus } from 'lucide-react';

const tones = {
  neutral:  'border-[var(--nx-border)]',
  accent:   'border-[color-mix(in_oklch,var(--nx-accent)_28%,var(--nx-border))]',
  success:  'border-[color-mix(in_oklch,var(--nx-success)_28%,var(--nx-border))]',
  warning:  'border-[color-mix(in_oklch,var(--nx-warning)_30%,var(--nx-border))]',
  danger:   'border-[color-mix(in_oklch,var(--nx-danger)_28%,var(--nx-border))]',
};

const iconTile = {
  neutral:  'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]',
  accent:   'bg-[color-mix(in_oklch,var(--nx-accent)_11%,transparent)] text-[var(--nx-accent)]',
  success:  'bg-[color-mix(in_oklch,var(--nx-success)_12%,transparent)] text-[var(--nx-success)]',
  warning:  'bg-[color-mix(in_oklch,var(--nx-warning)_13%,transparent)] text-[var(--nx-warning)]',
  danger:   'bg-[color-mix(in_oklch,var(--nx-danger)_12%,transparent)] text-[var(--nx-danger)]',
};

const trendMeta = {
  up:    { icon: TrendingUp,   cls: 'text-[var(--nx-success)]' },
  down:  { icon: TrendingDown, cls: 'text-[var(--nx-danger)]' },
  flat:  { icon: Minus,        cls: 'text-[var(--nx-text-muted)]' },
};

export const StatCard = ({
  icon,
  label,
  value = 0,
  statusText,
  trend,
  trendLabel,
  period,
  tone = 'neutral',
  onClick,
  className,
}) => {
  const Trend = trend ? (trendMeta[trend] ?? trendMeta.flat) : null;
  const TrendIcon = Trend?.icon;
  const interactive = !!onClick;
  const Component = interactive ? 'button' : 'div';

  return (
    <Component
      type={interactive ? 'button' : undefined}
      onClick={onClick}
      className={cn(
        'nx-pressable rounded-surface border bg-[var(--nx-surface)] p-5 text-left',
        tones[tone] ?? tones.neutral,
        interactive && 'cursor-pointer hover:shadow-medium',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--nx-accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--nx-canvas)]',
        className
      )}
    >
      <div className="flex items-start justify-between gap-3">
        <span className={cn('grid h-9 w-9 shrink-0 place-items-center rounded-control', iconTile[tone] ?? iconTile.neutral)}>
          {icon}
        </span>
        {Trend && (
          <span className={cn('flex items-center gap-1 text-caption font-semibold', Trend.cls)}>
            <TrendIcon size={13} strokeWidth={2} aria-hidden />
            {trendLabel}
          </span>
        )}
      </div>
      <p className={cn('nx-tnum mt-4 text-metric text-[var(--nx-text)]')}>
        {typeof value === 'number' ? value.toLocaleString('es-CO') : value}
      </p>
      <p className="mt-1 text-caption font-medium uppercase text-[var(--nx-text-muted)]">{label}</p>
      {statusText && (
        <p className="mt-2 text-body-sm text-[var(--nx-text-muted)]">{statusText}</p>
      )}
      {period && (
        <p className="mt-1.5 text-caption text-[var(--nx-text-muted)]">{period}</p>
      )}
    </Component>
  );
};
