import { useState } from 'react';
import { clsx } from 'clsx';
import { Skeleton } from '../ui/Skeleton';

const NexoAvatar = ({ size = 40 }) => {
  const [failed, setFailed] = useState(false);
  if (failed) {
    return (
      <div
        className="grid shrink-0 place-items-center rounded-full bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] border border-[var(--nx-border-accent)] font-bold"
        style={{ width: size, height: size, fontSize: '0.875rem' }}
      >
        N
      </div>
    );
  }
  return (
    <img
      src="/imagenbot.png"
      alt="Nexus"
      className="shrink-0 rounded-full object-cover"
      style={{ width: size, height: size }}
      onError={() => setFailed(true)}
    />
  );
};

export { NexoAvatar };
export const NexoChatBubble = ({ message, timestamp = 'Ahora', action, unread }) => (
  <div className="flex items-start gap-3" role="article">
    <NexoAvatar size={36} />
    <div className="flex-1 min-w-0">
      <div className={clsx(
        'rounded-surface rounded-tl-xs border px-4 py-3',
        unread
          ? 'border-[var(--nx-border-accent)] bg-[var(--nx-subtle-bg-accent)]'
          : 'border-[var(--nx-border)] bg-[var(--nx-surface)]'
      )}>
        {/* meta discreta dentro de la burbuja: remitente + hora; el punto
            marca lo no leído sin saturar toda la tarjeta de acento */}
        <div className="mb-1.5 flex items-center gap-2">
          <span className={clsx(
            'text-caption font-semibold tracking-wide',
            unread ? 'text-[var(--nx-accent)]' : 'text-[var(--nx-text-muted)]'
          )}>
            NEXO
          </span>
          {unread && <span className="h-1.5 w-1.5 rounded-full bg-[var(--nx-accent)]" title="Sin leer" />}
          <span className="ml-auto text-caption tabular-nums text-[var(--nx-text-muted)]">{timestamp}</span>
        </div>
        <p className="text-body-sm text-[var(--nx-text)] leading-relaxed">{message}</p>
        {action && (
          <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-[var(--nx-border)] pt-3">
            {action}
          </div>
        )}
      </div>
    </div>
  </div>
);

export const NexoChatSkeleton = () => (
  <div className="flex items-start gap-3">
    <Skeleton className="h-9 w-9 shrink-0 rounded-full" />
    <div className="flex-1 space-y-2">
      <Skeleton className="h-20 w-full rounded-surface" />
      <Skeleton className="h-3 w-24" />
    </div>
  </div>
);
