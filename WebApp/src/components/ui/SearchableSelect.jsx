/**
 * CMP-005b SearchableSelect
 * Barra desplegable con búsqueda integrada — patrón unificado para todas
 * las selecciones de NEXO (operations, consultations, reports, enrollment).
 * Reemplaza Select nativo cuando hay >8 opciones o se necesita búsqueda.
 */
import React, { useId, useState, useRef, useEffect, useMemo } from 'react';
import { Search, ChevronDown, Check, X } from 'lucide-react';
import { clsx } from 'clsx';
import { AnimatePresence, motion } from 'framer-motion';

const EASE = [0.22, 1, 0.36, 1];

export const SearchableSelect = ({
  label,
  error,
  help,
  hint,
  options = [],
  value,
  onChange,
  placeholder = 'Seleccionar…',
  searchPlaceholder = 'Buscar…',
  emptyText = 'Sin resultados',
  required,
  className,
  id: idProp,
  clearable,
  multiple,
}) => {
  const auto = useId();
  const id = idProp ?? `nx-ss-${auto}`;
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const ref = useRef(null);
  const inputRef = useRef(null);

  useEffect(() => {
    if (!open) return;
    const handler = (e) => {
      if (ref.current && !ref.current.contains(e.target)) {
        setOpen(false);
        setQuery('');
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  useEffect(() => {
    if (open && inputRef.current) {
      inputRef.current.focus();
    }
  }, [open]);

  const selectedValues = useMemo(() => {
    if (!multiple) return value ? [value] : [];
    return Array.isArray(value) ? value : (value ? String(value).split(',').filter(Boolean) : []);
  }, [multiple, value]);

  const selected = multiple ? options.filter((o) => selectedValues.includes(o.value)) : options.find((o) => o.value === value);

  const filtered = useMemo(() => {
    if (!query.trim()) return options;
    const q = query.toLowerCase();
    return options.filter((o) => String(o.label).toLowerCase().includes(q));
  }, [options, query]);

  const handleSelect = (val) => {
    if (!multiple) {
      onChange?.(val);
      setOpen(false);
      setQuery('');
      return;
    }
    const current = selectedValues;
    const next = current.includes(val) ? current.filter((v) => v !== val) : [...current, val];
    onChange?.(next);
  };

  const handleClear = (e) => {
    e.stopPropagation();
    onChange?.(multiple ? [] : '');
  };

  return (
    <div className={clsx('space-y-2', className)} ref={ref}>
      {label && (
        <div className="flex items-baseline justify-between gap-3">
          <label className="block text-label text-[var(--nx-text)]" onClick={() => setOpen(true)}>
            {label}
            {required && <span className="ml-0.5 text-[var(--nx-text-muted)]" aria-hidden>*</span>}
          </label>
          {hint && <span className="text-caption text-[var(--nx-text-muted)]">{hint}</span>}
        </div>
      )}
      <div className="relative">
        <button
          type="button"
          id={id}
          onClick={() => setOpen((v) => !v)}
          aria-expanded={open}
          aria-haspopup="listbox"
          className={clsx(
            'flex h-12 w-full items-center justify-between rounded-control border bg-[var(--nx-surface)] px-4',
            'text-body text-left outline-none',
            'transition-[border-color,box-shadow] duration-fast ease-out',
            error
              ? 'border-[var(--nx-danger)]'
              : open
                ? 'border-[var(--nx-accent)] focus:shadow-[var(--nx-ring)]'
                : 'border-[var(--nx-border)] hover:border-[color-mix(in_oklch,var(--nx-text)_var(--nx-subtle-mix-w),var(--nx-tint-base))]'
          )}
        >
          <span className={clsx('truncate', (!selected || (Array.isArray(selected) && selected.length === 0)) && 'text-[var(--nx-text-muted)]')}>
            {multiple
              ? (selected?.length ? `${selected[0].label}${selected.length > 1 ? ` +${selected.length - 1}` : ''}` : placeholder)
              : (selected ? selected.label : placeholder)}
          </span>
          <span className="flex items-center gap-1">
            {clearable && (multiple ? selected?.length > 0 : selected) && (
              <span
                role="button"
                tabIndex={-1}
                onClick={handleClear}
                className="grid h-5 w-5 place-items-center rounded-xs text-[var(--nx-text-muted)] hover:text-[var(--nx-text)]"
              >
                <X size={14} />
              </span>
            )}
            <ChevronDown
              size={16}
              className={clsx('text-[var(--nx-text-muted)] transition-transform duration-fast', open && 'rotate-180')}
            />
          </span>
        </button>

        <AnimatePresence>
          {open && (
            <motion.div
              initial={{ opacity: 0, y: -4 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -4 }}
              transition={{ duration: 0.15, ease: EASE }}
              className="absolute left-0 right-0 top-full z-40 mt-1 overflow-hidden rounded-control border border-[var(--nx-border)] bg-[var(--nx-surface)] shadow-medium"
              role="listbox"
            >
              <div className="sticky top-0 border-b border-[var(--nx-border)] bg-[var(--nx-surface)] p-2">
                <div className="relative">
                  <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-[var(--nx-text-muted)]">
                    <Search size={14} />
                  </span>
                  <input
                    ref={inputRef}
                    type="text"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={searchPlaceholder}
                    className="h-9 w-full rounded-xs border border-[var(--nx-border)] bg-[var(--nx-surface)] pl-9 pr-3 text-body-sm text-[var(--nx-text)] outline-none focus:border-[var(--nx-accent)]"
                  />
                </div>
              </div>
              <div className="max-h-48 overflow-y-auto">
                {filtered.length === 0 ? (
                  <p className="px-4 py-3 text-center text-body-sm text-[var(--nx-text-muted)]">{emptyText}</p>
                ) : (
                  filtered.map((opt) => {
                    const isSelected = selectedValues.includes(opt.value);
                    return (
                      <button
                        key={opt.value}
                        type="button"
                        role="option"
                        aria-selected={isSelected}
                        onClick={() => handleSelect(opt.value)}
                        className={clsx(
                          'flex w-full items-center gap-3 px-4 py-2.5 text-left text-body transition-colors',
                          isSelected
                            ? 'bg-[color-mix(in_oklch,var(--nx-accent)_var(--nx-subtle-mix),var(--nx-tint-base))] text-[var(--nx-accent)]'
                            : 'text-[var(--nx-text)] hover:bg-[var(--nx-surface-subtle)]'
                        )}
                      >
                        {multiple && (
                          <span className={clsx(
                            'grid h-4 w-4 shrink-0 place-items-center rounded-xs border',
                            isSelected ? 'border-[var(--nx-accent)] bg-[var(--nx-accent)] text-[var(--nx-accent-text)]' : 'border-[var(--nx-border)] bg-[var(--nx-surface)]'
                          )}>
                            {isSelected && <Check size={12} />}
                          </span>
                        )}
                        <span className="flex-1 truncate">{opt.label}</span>
                        {!multiple && isSelected && <Check size={14} className="shrink-0" />}
                      </button>
                    );
                  })
                )}
              </div>
            </motion.div>
          )}
        </AnimatePresence>
      </div>
      {error ? (
        <p className="flex items-start gap-1.5 text-caption text-[var(--nx-danger)]">
          <span>{error}</span>
        </p>
      ) : (
        help && <p className="text-caption text-[var(--nx-text-muted)]">{help}</p>
      )}
    </div>
  );
};
