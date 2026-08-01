/**
 * CMP-011 Badge — estado no interactivo.
 * Siempre texto + semántica; nunca solo color.
 */
import { cn } from '../../utils/cn';

const schemes = {
  neutral:  'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] border-[var(--nx-border)]',
  accent:   'bg-[var(--nx-subtle-bg-accent)] text-[var(--nx-accent)] border-[var(--nx-border-accent)]',
  success:  'bg-[var(--nx-subtle-bg-success)] text-[var(--nx-success)] border-[var(--nx-border-success)]',
  warning:  'bg-[var(--nx-subtle-bg-warning)] text-[var(--nx-warning)] border-[var(--nx-border-warning)]',
  danger:   'bg-[var(--nx-subtle-bg-danger)] text-[var(--nx-danger)] border-[var(--nx-border-danger)]',
};

const dots = {
  neutral: 'bg-[var(--nx-text-muted)]',
  accent:  'bg-[var(--nx-accent)]',
  success: 'bg-[var(--nx-success)]',
  warning: 'bg-[var(--nx-warning)]',
  danger:  'bg-[var(--nx-danger)]',
};

export const Badge = ({ children, scheme = 'neutral', dot, icon, className }) => (
  <span
    className={cn(
      'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-1',
      'text-caption font-semibold',
      schemes[scheme] ?? schemes.neutral,
      className
    )}
  >
    {dot && <span aria-hidden className={cn('h-1.5 w-1.5 rounded-full', dots[scheme] ?? dots.neutral)} />}
    {icon}
    {children}
  </span>
);
