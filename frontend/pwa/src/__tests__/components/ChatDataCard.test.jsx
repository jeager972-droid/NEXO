import { describe, it, expect } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DataCard } from '@/pages/Chat';

const makeCard = (count, title = 'Estudiantes') => Object.freeze({
  title,
  columns: Object.freeze(['Nombre', 'Valor']),
  rows: Object.freeze(Array.from({ length: count }, (_, i) => Object.freeze([`${title} ${i + 1}`, i]))),
});
const visibleRows = (table = screen.getByRole('table')) => within(table).getAllByRole('row').slice(1)
  .map((row) => within(row).getAllByRole('cell').map((cell) => cell.textContent));

 describe('Chat DataCard', () => {
  it.each([1, 10])('keeps a %i-row table unpaginated with a caption and scoped headers', (count) => {
    const card = makeCard(count);
    render(<DataCard card={card} />);
    const table = screen.getByRole('table', { name: card.title });
    expect(table.querySelector('caption')).toHaveTextContent(card.title);
    within(table).getAllByRole('columnheader').forEach((header) => expect(header).toHaveAttribute('scope', 'col'));
    expect(visibleRows()).toEqual(card.rows.map((row) => row.map(String)));
    expect(screen.getByRole('status')).toHaveTextContent(`Filas 1–${count} de ${count}`);
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('makes every supplied row reachable with accurate ranges and keyboard/page controls', async () => {
    const user = userEvent.setup();
    const card = makeCard(23);
    render(<DataCard card={card} />);
    expect(visibleRows()).toHaveLength(10);
    const previous = screen.getByRole('button', { name: 'Anterior' });
    const next = screen.getByRole('button', { name: 'Siguiente' });
    const page = screen.getByRole('combobox', { name: 'Página' });
    expect(previous).toBeDisabled();
    expect(next).toHaveAttribute('aria-controls', screen.getByRole('table').id);
    expect(screen.getByRole('status')).toHaveAttribute('aria-live', 'polite');
    await user.tab();
    expect(page).toHaveFocus();
    await user.tab();
    expect(next).toHaveFocus();
    const visited = [];
    for (let i = 0; i < 3; i += 1) {
      expect(page).toHaveDisplayValue(`Página ${i + 1} de 3`);
      expect(screen.getByRole('status')).toHaveTextContent(`Filas ${i * 10 + 1}–${Math.min((i + 1) * 10, 23)} de 23`);
      visited.push(...visibleRows());
      if (i < 2) await user.keyboard('{Enter}');
    }
    expect(visited).toEqual(card.rows.map((row) => row.map(String)));
    expect(next).toBeDisabled();
    await user.click(previous);
    expect(screen.getByRole('status')).toHaveTextContent('Filas 11–20 de 23');
    await user.selectOptions(page, '0');
    expect(visibleRows()[0]).toEqual(['Estudiantes 1', '0']);
    expect(previous).toBeDisabled();
    await user.selectOptions(page, '2');
    expect(visibleRows()).toHaveLength(3);
    expect(next).toBeDisabled();
  });

  it('preserves zero, false, blank and extra cells while marking null or missing cells', () => {
    render(<DataCard card={{
      title: 'Valores', columns: ['Nombre', 'Cantidad', 'Activo'],
      rows: [['Ana', null, 0], ['Luis'], [undefined, '', false], null, ['Extra', 1, true, 'Conservar']],
    }} />);
    expect(visibleRows()).toEqual([
      ['Ana', '—', '0'], ['Luis', '—', '—'], ['—', '', 'false'], ['—', '—', '—'], ['Extra', '1', 'true', 'Conservar'],
    ]);
    expect(screen.getByRole('status')).toHaveTextContent('Filas 1–5 de 5');
  });

  it.each([['empty', []], ['null', null], ['missing', undefined]])('handles %s row lists without phantom pages', (_label, rows) => {
    render(<DataCard card={{ columns: ['Nombre'], rows }} />);
    expect(screen.getByRole('table', { name: 'Datos de Nexus' })).toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Filas 0–0 de 0');
    expect(screen.queryAllByRole('cell')).toHaveLength(0);
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it.each([null, undefined])('retains supplied cells when columns are %s', (columns) => {
    render(<DataCard card={{ columns, rows: [['Conservar', 0]] }} />);
    expect(visibleRows()).toEqual([['Conservar', '0']]);
  });

  it('resets replaced cards to the first page and handles a shorter replacement', async () => {
    const user = userEvent.setup();
    const { rerender } = render(<DataCard card={makeCard(23)} />);
    await user.selectOptions(screen.getByRole('combobox', { name: 'Página' }), '2');
    rerender(<DataCard card={makeCard(31, 'Reemplazo')} />);
    expect(visibleRows()[0]).toEqual(['Reemplazo 1', '0']);
    expect(screen.getByRole('status')).toHaveTextContent('Filas 1–10 de 31');
    expect(screen.getByRole('combobox')).toHaveDisplayValue('Página 1 de 4');
    await user.selectOptions(screen.getByRole('combobox'), '3');
    rerender(<DataCard card={makeCard(2, 'Corto')} />);
    expect(visibleRows()).toEqual([['Corto 1', '0'], ['Corto 2', '1']]);
    expect(screen.getByRole('status')).toHaveTextContent('Filas 1–2 de 2');
    expect(screen.queryByRole('navigation')).not.toBeInTheDocument();
  });

  it('keeps pagination independent for multiple cards', async () => {
    const user = userEvent.setup();
    render(<><DataCard card={makeCard(23, 'Primero')} /><DataCard card={makeCard(12, 'Segundo')} /></>);
    const first = screen.getByRole('table', { name: 'Primero' });
    const second = screen.getByRole('table', { name: 'Segundo' });
    const controls = within(screen.getByRole('navigation', { name: 'Paginación de Primero' }));
    await user.selectOptions(controls.getByRole('combobox', { name: 'Página' }), '2');
    expect(visibleRows(first)[0]).toEqual(['Primero 21', '20']);
    expect(visibleRows(second)[0]).toEqual(['Segundo 1', '0']);
    expect(controls.getByRole('button', { name: 'Anterior' })).toHaveAttribute('aria-controls', first.id);
    expect(first.id).not.toBe(second.id);
  });
});
