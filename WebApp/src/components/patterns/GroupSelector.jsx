/**
 * CMP-100 Selector de grupo
 * Persistente. Muestra grupo y jornada.
 */
import { useState, useRef, useEffect } from 'react';
import { clsx } from 'clsx';
import { ChevronDown, Users } from 'lucide-react';

export const GroupSelector = ({ value, options = [], onChange, loading, disabled }) => {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);
  const selected = options.find((o) => o.value === value) || options[0] || { label: 'Seleccionar grupo', value: '' };

  useEffect(() => {
    const onClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  return (
    <div ref={ref} className="relative">
      <button
        onClick={() => !disabled && setOpen((v) => !v)}
        disabled={disabled || loading}
        aria-haspopup="listbox"
        aria-expanded={open}
        className={clsx(
          'flex items-center gap-2 h-11 pl-3 pr-2 rounded-control border transition-colors duration-fast',
          'border-[var(--nx-border)] bg-[var(--nx-surface)] hover:border-[var(--nx-text-muted)]',
          'disabled:opacity-50 disabled:cursor-not-allowed'
        )}
      >
        <Users size={16} className="text-[var(--nx-accent)]" />
        <div className="text-left min-w-0">
          <p className="text-caption text-[var(--nx-text-muted)] leading-none">Grupo activo</p>
          <p className="text-label text-[var(--nx-text)] leading-none mt-1 truncate max-w-[9rem]">{selected.label}</p>
        </div>
        <ChevronDown size={14} className={clsx('text-[var(--nx-text-muted)] transition-transform duration-fast', open && 'rotate-180')} />
      </button>
      {open && (
        <ul
          role="listbox"
          className="absolute z-50 top-full left-0 mt-2 w-56 max-h-72 overflow-auto rounded-surface border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-high py-1"
        >
          {options.map((opt) => (
            <li
              key={opt.value}
              role="option"
              aria-selected={opt.value === value}
              onClick={() => { onChange(opt.value); setOpen(false); }}
              className={clsx(
                'px-3 py-2.5 text-body cursor-pointer transition-colors duration-fast',
                opt.value === value
                  ? 'bg-[color-mix(in_oklch,var(--nx-accent)_var(--nx-subtle-mix),transparent)] text-[var(--nx-accent)]'
                  : 'text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)]'
              )}
            >
              {opt.label}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
};
