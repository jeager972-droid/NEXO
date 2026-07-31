/**
 * CMP-011 Badge — estado no interactivo.
 * Siempre texto + semántica; nunca solo color.
 */
import { cn } from '../../utils/cn';

const schemes = {
  neutral:  'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)] border-[var(--nx-border)]',
  accent:   'bg-[color-mix(in_oklch,var(--nx-accent)_var(--nx-subtle-mix),transparent)] text-[var(--nx-accent)] border-[color-mix(in_oklch,var(--nx-accent)_var(--nx-border-mix),transparent)]',
  success:  'bg-[color-mix(in_oklch,var(--nx-success)_var(--nx-subtle-mix),transparent)] text-[var(--nx-success)] border-[color-mix(in_oklch,var(--nx-success)_var(--nx-border-mix),transparent)]',
  warning:  'bg-[color-mix(in_oklch,var(--nx-warning)_var(--nx-subtle-mix-w),transparent)] text-[var(--nx-warning)] border-[color-mix(in_oklch,var(--nx-warning)_var(--nx-border-mix),transparent)]',
  danger:   'bg-[color-mix(in_oklch,var(--nx-danger)_var(--nx-subtle-mix),transparent)] text-[var(--nx-danger)] border-[color-mix(in_oklch,var(--nx-danger)_var(--nx-border-mix),transparent)]',
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
