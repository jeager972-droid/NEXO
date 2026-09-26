import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { StudentItem } from '@/components/patterns/StudentItem';

describe('StudentItem', () => {
  it('renders the student name', () => {
    render(<StudentItem name="Ana López" group="Grupo A" />);
    expect(screen.getByText('Ana López')).toBeInTheDocument();
  });

  it('renders the group text', () => {
    render(<StudentItem name="Ana López" group="Grupo A" />);
    expect(screen.getByText('Grupo A')).toBeInTheDocument();
  });

  it('renders the photo image when photo prop is provided', () => {
    const { container } = render(
      <StudentItem name="Ana López" group="Grupo A" photo="/img/ana.png" />
    );
    const img = container.querySelector('img');
    expect(img).toBeInTheDocument();
    expect(img.src).toContain('/img/ana.png');
  });

  it('renders the User fallback icon when no photo is provided', () => {
    const { container } = render(<StudentItem name="Ana López" group="Grupo A" />);
    expect(container.querySelector('img')).not.toBeInTheDocument();
    // lucide-react renders an svg
    expect(container.querySelector('svg')).toBeInTheDocument();
  });

  it('renders the status node when provided', () => {
    render(
      <StudentItem
        name="Ana López"
        group="Grupo A"
        status={<span data-testid="status">Activo</span>}
      />
    );
    expect(screen.getByTestId('status')).toBeInTheDocument();
  });

  it('does not render a status node when not provided', () => {
    const { container } = render(<StudentItem name="Ana López" group="Grupo A" />);
    expect(container.querySelectorAll('.shrink-0').length).toBeLessThanOrEqual(1);
  });

  it('renders the action node when provided', () => {
    render(
      <StudentItem
        name="Ana López"
        group="Grupo A"
        action={<button data-testid="action">Ver</button>}
      />
    );
    expect(screen.getByTestId('action')).toBeInTheDocument();
  });

  it('calls onClick when the item is clicked', () => {
    const onClick = vi.fn();
    render(<StudentItem name="Ana López" group="Grupo A" onClick={onClick} />);
    fireEvent.click(screen.getByText('Ana López'));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('applies cursor-pointer class when onClick is provided', () => {
    const { container } = render(
      <StudentItem name="Ana López" group="Grupo A" onClick={() => {}} />
    );
    expect(container.firstChild.className).toContain('cursor-pointer');
  });

  it('applies cursor-pointer class when action is provided even without onClick', () => {
    const { container } = render(
      <StudentItem
        name="Ana López"
        group="Grupo A"
        action={<button data-testid="action">Ver</button>}
      />
    );
    expect(container.firstChild.className).toContain('cursor-pointer');
  });

  it('does not apply cursor-pointer class when neither onClick nor action is provided', () => {
    const { container } = render(<StudentItem name="Ana López" group="Grupo A" />);
    expect(container.firstChild.className).not.toContain('cursor-pointer');
  });

  it('applies custom className', () => {
    const { container } = render(
      <StudentItem name="Ana López" group="Grupo A" className="my-item" />
    );
    expect(container.firstChild.className).toContain('my-item');
  });

  it('renders both status and action together', () => {
    render(
      <StudentItem
        name="Ana López"
        group="Grupo A"
        status={<span data-testid="status">Alto</span>}
        action={<button data-testid="action">Ver</button>}
      />
    );
    expect(screen.getByTestId('status')).toBeInTheDocument();
    expect(screen.getByTestId('action')).toBeInTheDocument();
  });

  it('truncates long names (has truncate class)', () => {
    const { container } = render(<StudentItem name="Nombre muy largo" group="Grupo A" />);
    const nameEl = screen.getByText('Nombre muy largo');
    expect(nameEl.className).toContain('truncate');
  });
});
