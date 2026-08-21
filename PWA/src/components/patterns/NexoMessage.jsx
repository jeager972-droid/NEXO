/**
 * CMP-025 Burbuja NEXO
 * Mensaje de solo lectura: origen, tiempo, texto, evidencia resumida y CTA contextual.
 */
import { clsx } from 'clsx';
import { Sparkles } from 'lucide-react';
import { Button } from '../ui/Button';

export const NexoMessage = ({ time, text, evidence, action, onAction, urgent, className }) => (
  <div className={clsx('rounded-surface border p-4 transition-all duration-fast', urgent ? 'border-[var(--nx-border-warning)] bg-[var(--nx-subtle-bg-warning)]' : 'border-[var(--nx-border)] bg-[var(--nx-surface)]', className)}>
    <div className="flex items-start gap-3">
      <div className="shrink-0 flex h-8 w-8 items-center justify-center rounded-full bg-[var(--nx-surface-accent)] text-[var(--nx-accent)] border border-[var(--nx-border-accent)]">
        <Sparkles size={16} />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2 mb-1">
          <span className="text-label text-[var(--nx-text)]">NEXO</span>
          <span className="text-caption text-[var(--nx-text-muted)]">{time}</span>
        </div>
        <p className="text-body text-[var(--nx-text)] leading-relaxed">{text}</p>
        {evidence && <p className="text-body-sm text-[var(--nx-text-muted)] mt-2">{evidence}</p>}
        {action && onAction && (
          <div className="mt-4">
            <Button size="sm" variant={urgent ? 'primary' : 'secondary'} onClick={onAction}>{action}</Button>
          </div>
        )}
      </div>
    </div>
  </div>
);
