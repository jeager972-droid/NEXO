/**
 * Superficies y jerarquía de encabezado.
 * Autoridad: 04_VISUAL_LANGUAGE.md §12 (prueba de "solo títulos").
 * Tres niveles distinguibles sin leer el cuerpo:
 *   PageHeader  → la pantalla responde una pregunta.
 *   Section     → grupo dentro de la pantalla.
 *   BlockTitle  → lista o tabla concreta.
 */
import React from 'react';
import { cn } from '../../utils/cn';

export const Surface = React.forwardRef(
  ({ children, className, elevated, ...props }, ref) => (
    <div
      ref={ref}
      className={cn(
        'rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)]',
        elevated && 'shadow-medium',
        className
      )}
      {...props}
    >
      {children}
    </div>
  )
);
Surface.displayName = 'Surface';

/**
 * Encabezado de pantalla. `eyebrow` da contexto temporal o de alcance;
 * `meta` admite estado de datos (última actualización, caché, alcance).
 */
export const PageHeader = ({ eyebrow, title, subtitle, meta, actions, className }) => (
  <header className={cn('flex flex-col gap-5 md:flex-row md:items-start md:justify-between', className)}>
    <div className="min-w-0 max-w-reading">
      {eyebrow && (
        <p className="mb-2 flex items-center gap-2 text-eyebrow uppercase text-[var(--nx-text-muted)]">
          {eyebrow}
        </p>
      )}
      <h1 className="text-h1 tracking-[-0.02em] text-[var(--nx-text)]">{title}</h1>
      {subtitle && <p className="mt-2 text-body text-[var(--nx-text-muted)]">{subtitle}</p>}
      {meta && <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5">{meta}</div>}
    </div>
    {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
  </header>
);

export const Section = ({ title, subtitle, children, action, className }) => (
  <section className={cn('space-y-4', className)}>
    {(title || subtitle || action) && (
      <div className="flex items-end justify-between gap-4">
        <div className="min-w-0">
          {title && <h2 className="text-h2 tracking-[-0.02em] text-[var(--nx-text)]">{title}</h2>}
          {subtitle && <p className="mt-1 text-body-sm text-[var(--nx-text-muted)]">{subtitle}</p>}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>
    )}
    {children}
  </section>
);

/** Título de bloque: moodboard `.nx-section-t`. Nunca compite con el h1. */
export const BlockTitle = ({ children, count, action, className }) => (
  <div className={cn('flex items-baseline justify-between gap-4', className)}>
    <h3 className="flex items-baseline gap-2 text-label text-[var(--nx-text)]">
      {children}
      {count !== undefined && count !== null && (
        <span className="nx-tnum text-caption font-medium text-[var(--nx-text-muted)]">{count}</span>
      )}
    </h3>
    {action}
  </div>
);

/** Metadato de encabezado: icono + texto, siempre legible sin color. */
export const MetaItem = ({ icon, children }) => (
  <span className="flex items-center gap-1.5 text-caption text-[var(--nx-text-muted)]">
    {icon}
    {children}
  </span>
);

/** Separador de aire entre grandes bloques de pantalla. */
export const Divider = ({ className }) => (
  <hr className={cn('border-0 border-t border-[var(--nx-border)]', className)} />
);
