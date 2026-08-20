import { useState } from 'react';
import { Skeleton } from '../ui/Skeleton';

const NexoAvatar = ({ size = 40 }) => {
  const [failed, setFailed] = useState(false);
  if (failed) {
    return (
      <div
        className="grid shrink-0 place-items-center rounded-full bg-[var(--nx-accent)] text-[var(--nx-accent-text)] font-bold"
        style={{ width: size, height: size, fontSize: '0.875rem' }}
      >
        N
      </div>
    );
  }
  return (
    <img
      src={`${import.meta.env.BASE_URL}logo/logo_nexo_app.png`}
      alt="NEXO"
      className="shrink-0 rounded-full object-cover"
      style={{ width: size, height: size }}
      onError={() => setFailed(true)}
    />
  );
};

export { NexoAvatar };
export const NexoChatBubble = ({ message, timestamp = 'Ahora' }) => (
  <div className="flex items-start gap-3">
    <NexoAvatar size={40} />
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
