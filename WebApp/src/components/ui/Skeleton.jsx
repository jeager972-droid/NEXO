/**
 * CMP-039 Skeleton
 * Autoridad: 06_USER_FLOWS.md §11 · 08_MICRO_INTERACTIONS §5.
 * Reproduce la estructura que llega, con barrido lateral en vez de parpadeo:
 * la espera informa, no reclama atención.
 */
import { cn } from '../../utils/cn';

export const Skeleton = ({ className, children }) => (
  <div aria-hidden className={cn('nx-skeleton rounded-control', className)}>
    {children}
  </div>
);

export const SkeletonText = ({ lines = 1, className }) => (
  <div className={cn('space-y-2', className)}>
    {Array.from({ length: lines }).map((_, i) => (
      <Skeleton key={i} className={cn('h-4', i === lines - 1 && lines > 1 ? 'w-3/5' : 'w-full')} />
    ))}
  </div>
);

/** Rejilla de métricas en carga: conserva el layout final para evitar salto. */
export const SkeletonMetrics = ({ count = 4, className }) => (
  <div className={cn('grid grid-cols-2 md:grid-cols-4 gap-4', className)}>
    {Array.from({ length: count }).map((_, i) => (
      <div
        key={i}
        className="rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] p-5"
      >
        <Skeleton className="h-9 w-9 rounded-control" />
        <Skeleton className="mt-4 h-7 w-14" />
        <Skeleton className="mt-2.5 h-3 w-20" />
        <Skeleton className="mt-2 h-3 w-16" />
      </div>
    ))}
  </div>
);

/** Lista en carga dentro de una superficie ya existente. */
export const SkeletonRows = ({ count = 4, className }) => (
  <div className={cn('divide-y divide-[var(--nx-border)]', className)}>
    {Array.from({ length: count }).map((_, i) => (
      <div key={i} className="flex items-center gap-4 px-5 py-4">
        <Skeleton className="h-9 w-9 shrink-0 rounded-control" />
        <div className="min-w-0 flex-1 space-y-2">
          <Skeleton className="h-3.5 w-2/5" />
          <Skeleton className="h-3 w-1/4" />
        </div>
        <Skeleton className="h-6 w-16 shrink-0 rounded-full" />
      </div>
    ))}
  </div>
);

/** Rejilla de tarjetas de acción en carga: icono + título + badge. */
export const SkeletonCards = ({ count = 6, className }) => (
  <div className={cn('grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4', className)}>
    {Array.from({ length: count }).map((_, i) => (
      <div
        key={i}
        className="rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] p-5"
      >
        <div className="flex items-start justify-between">
          <Skeleton className="h-10 w-10 rounded-control" />
          <Skeleton className="h-4 w-4 rounded-control" />
        </div>
        <Skeleton className="mt-4 h-5 w-2/3" />
        <Skeleton className="mt-2 h-5 w-16 rounded-full" />
      </div>
    ))}
  </div>
);
