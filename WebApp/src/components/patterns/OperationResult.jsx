/**
 * CMP-105 Resultado de operación — SCR-OPS-03
 * Autoridad: DEC-017 (Peak-End), 01_PRODUCT_DESIGN_PHILOSOPHY.md §7.
 * El cierre del flujo es lo que se recuerda: estado real de entrega, no jerga.
 * Usa deliveryCopy para traducir estados internos a lenguaje institucional.
 */
import { motion } from 'framer-motion';
import { CheckCircle2, AlertTriangle, XCircle, Clock, Send } from 'lucide-react';
import { cn } from '../../utils/cn';
import { Button } from '../ui/Button';
import { Badge } from '../ui/Badge';
import { deliveryCopy } from '../../utils/messages';

const EASE = [0.22, 1, 0.36, 1];

const variants = {
  success: {
    ring: 'bg-[color-mix(in_oklch,var(--nx-success)_12%,transparent)] text-[var(--nx-success)]',
    Icon: CheckCircle2,
    animate: 'animate-seal',
  },
  warning: {
    ring: 'bg-[color-mix(in_oklch,var(--nx-warning)_13%,transparent)] text-[var(--nx-warning)]',
    Icon: AlertTriangle,
    animate: '',
  },
  danger: {
    ring: 'bg-[color-mix(in_oklch,var(--nx-danger)_12%,transparent)] text-[var(--nx-danger)]',
    Icon: XCircle,
    animate: '',
  },
  pending: {
    ring: 'bg-[var(--nx-surface-subtle)] text-[var(--nx-text-muted)]',
    Icon: Clock,
    animate: '',
  },
};

export const OperationResult = ({
  variant = 'success',
  title,
  message,
  deliveryStatus,
  recipients = 0,
  onPrimary,
  primaryLabel = 'Nueva operación',
  onSecondary,
  secondaryLabel = 'Volver al inicio',
}) => {
  const v = variants[variant] ?? variants.success;
  const VIcon = v.Icon;
  const delivery = deliveryStatus ? deliveryCopy(deliveryStatus) : null;

  return (
    <motion.div
      initial={{ opacity: 0, y: 8 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: EASE }}
      className="mx-auto max-w-lg py-8 space-y-6"
    >
      <div className="flex flex-col items-center text-center">
        <div className={cn('grid h-16 w-16 place-items-center rounded-full', v.ring, v.animate)}>
          <VIcon size={32} strokeWidth={1.75} aria-hidden />
        </div>
        <h2 className="mt-5 text-h2 text-[var(--nx-text)]">{title}</h2>
        {message && (
          <p className="mt-2 max-w-sm text-body text-[var(--nx-text-muted)]">{message}</p>
        )}
      </div>

      {delivery && (
        <div className="rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] p-5 space-y-3">
          <div className="flex items-center justify-between">
            <span className="flex items-center gap-2 text-label text-[var(--nx-text-muted)] uppercase">
              <Send size={14} aria-hidden /> Entrega
            </span>
            <Badge
              scheme={
                deliveryStatus === 'delivered' || deliveryStatus === 'read' ? 'success' :
                deliveryStatus === 'failed' ? 'danger' :
                deliveryStatus === 'sent' ? 'accent' : 'warning'
              }
              dot
            >
              {delivery.label}
            </Badge>
          </div>
          <p className="text-body-sm text-[var(--nx-text-muted)]">{delivery.help}</p>
          {recipients > 0 && (
            <p className="text-caption text-[var(--nx-text-muted)]">
              {recipients} destinatario{recipients !== 1 ? 's' : ''} notificado{recipients !== 1 ? 's' : ''}
            </p>
          )}
        </div>
      )}

      <div className="flex flex-wrap justify-center gap-3">
        {onSecondary && (
          <Button variant="secondary" onClick={onSecondary}>
            {secondaryLabel}
          </Button>
        )}
        {onPrimary && (
          <Button onClick={onPrimary}>
            {primaryLabel}
          </Button>
        )}
      </div>
    </motion.div>
  );
};
