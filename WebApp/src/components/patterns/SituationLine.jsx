/**
 * CMP-028 Línea de situación — firma visual de NEXO (04_VISUAL_LANGUAGE.md §4)
 * Detecta y comunica una situación en una sola línea: icono + estado + detalle.
 * Es el North Star del producto: NEXO detecta, no solo muestra.
 */
import { cn } from '../../utils/cn';
import { Badge } from '../ui/Badge';

const schemes = {
  neutral:  { bar: 'bg-[var(--nx-text-muted)]', tile: 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]' },
  accent:   { bar: 'bg-[var(--nx-accent)]',     tile: 'bg-[color-mix(in_oklch,var(--nx-accent)_11%,transparent)] text-[var(--nx-accent)]' },
  success:  { bar: 'bg-[var(--nx-success)]',    tile: 'bg-[color-mix(in_oklch,var(--nx-success)_12%,transparent)] text-[var(--nx-success)]' },
  warning:  { bar: 'bg-[var(--nx-warning)]',    tile: 'bg-[color-mix(in_oklch,var(--nx-warning)_13%,transparent)] text-[var(--nx-warning)]' },
  danger:   { bar: 'bg-[var(--nx-danger)]',     tile: 'bg-[color-mix(in_oklch,var(--nx-danger)_12%,transparent)] text-[var(--nx-danger)]' },
};

export const SituationLine = ({
  icon,
  label,
  value,
  detail,
  scheme = 'neutral',
  badge,
  action,
  className,
}) => {
  const s = schemes[scheme] ?? schemes.neutral;
  return (
    <div
      className={cn(
        'flex items-center gap-4 rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] py-3.5 pl-4 pr-5',
        'border-l-[3px]',
        s.bar,
        className
      )}
    >
      <span className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-control', s.tile)}>
        {icon}
      </span>
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          <p className="text-label text-[var(--nx-text-muted)] uppercase">{label}</p>
          {badge && <Badge scheme={scheme} dot>{badge}</Badge>}
        </div>
        <p className="mt-0.5 truncate text-body text-[var(--nx-text)]">
          <span className="font-medium">{value}</span>
          {detail && <span className="text-[var(--nx-text-muted)]"> — {detail}</span>}
        </p>
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  );
};
