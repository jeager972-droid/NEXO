import { Skeleton } from '../ui/Skeleton';

export const NexoChatBubble = ({ message, timestamp = 'Ahora' }) => (
  <div className="flex items-start gap-3">
    <div className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-[var(--nx-accent)] text-[var(--nx-accent-text)] font-bold text-body">N</div>
    <div className="flex-1">
      <div className="rounded-surface rounded-bl-xs border border-[var(--nx-border)] bg-[var(--nx-surface-subtle)] px-4 py-3">
        <p className="text-body text-[var(--nx-text)] leading-relaxed">{message}</p>
      </div>
      <p className="text-caption text-[var(--nx-text-muted)] mt-1 px-1">NEXO · {timestamp}</p>
    </div>
  </div>
);

export const NexoChatSkeleton = () => (
  <div className="flex items-start gap-3">
    <Skeleton className="h-10 w-10 shrink-0 rounded-full" />
    <div className="flex-1 space-y-2">
      <Skeleton className="h-16 w-full rounded-surface" />
      <Skeleton className="h-3 w-24" />
    </div>
  </div>
);
