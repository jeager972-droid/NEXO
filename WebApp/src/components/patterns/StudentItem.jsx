/**
 * CMP-106 Estudiante contextual
 * Foto opcional, nombre, grupo, estado relevante y una acción.
 */
import React from 'react';
import { clsx } from 'clsx';
import { User } from 'lucide-react';

export const StudentItem = ({ name, group, photo, status, action, onClick, className }) => (
  <div
    onClick={onClick}
    className={clsx(
      'flex items-center gap-4 rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] p-4',
      (onClick || action) && 'cursor-pointer hover:border-[var(--nx-accent)] transition-colors duration-fast',
      className
    )}
  >
    <div className="shrink-0 h-12 w-12 rounded-control bg-[var(--nx-surface-subtle)] overflow-hidden flex items-center justify-center">
      {photo ? (
        <img src={photo} alt="" className="h-full w-full object-cover" />
      ) : (
        <User size={22} className="text-[var(--nx-text-muted)]" />
      )}
    </div>
    <div className="min-w-0 flex-1">
      <p className="text-body font-medium text-[var(--nx-text)] truncate">{name}</p>
      <p className="text-body-sm text-[var(--nx-text-muted)]">{group}</p>
    </div>
    {status && <div className="shrink-0">{status}</div>}
    {action && <div className="shrink-0">{action}</div>}
  </div>
);
