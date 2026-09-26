import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { StatCard } from '@/components/patterns/StatCard';

describe('StatCard', () => {
  it('renders the label text', () => {
    render(<StatCard label="Asistencia" value={92} />);
    expect(screen.getByText('Asistencia')).toBeInTheDocument();
  });

  it('renders a numeric value formatted with es-CO locale', () => {
    render(<StatCard label="Total" value={1234} />);
    expect(screen.getByText('1.234')).toBeInTheDocument();
  });

  it('renders a string value as-is without locale formatting', () => {
    render(<StatCard label="Estado" value="Activo" />);
    expect(screen.getByText('Activo')).toBeInTheDocument();
  });

  it('defaults value to 0 when not provided', () => {
    render(<StatCard label="Métrica" />);
    expect(screen.getByText('0')).toBeInTheDocument();
  });

  it('renders the icon node when provided', () => {
    render(<StatCard label="Métrica" value={5} icon={<span data-testid="ico">★</span>} />);
    expect(screen.getByTestId('ico')).toBeInTheDocument();
  });

  it('renders statusText when provided', () => {
    render(<StatCard label="Métrica" value={5} statusText="Por encima del objetivo" />);
    expect(screen.getByText('Por encima del objetivo')).toBeInTheDocument();
  });

  it('does not render statusText when not provided', () => {
    render(<StatCard label="Métrica" value={5} />);
    expect(screen.queryByText('Por encima del objetivo')).not.toBeInTheDocument();
  });

  it('renders period when provided', () => {
    render(<StatCard label="Métrica" value={5} period="Marzo 2024" />);
    expect(screen.getByText('Marzo 2024')).toBeInTheDocument();
  });

  it('does not render period when not provided', () => {
    render(<StatCard label="Métrica" value={5} />);
    expect(screen.queryByText('Marzo 2024')).not.toBeInTheDocument();
  });

  it('renders trend label when trend and trendLabel are provided', () => {
    render(<StatCard label="Métrica" value={5} trend="up" trendLabel="+12%" />);
    expect(screen.getByText('+12%')).toBeInTheDocument();
  });

  it('does not render trend label when trend is not provided', () => {
    render(<StatCard label="Métrica" value={5} trendLabel="+12%" />);
    expect(screen.queryByText('+12%')).not.toBeInTheDocument();
  });

  it.each(['up', 'down', 'flat'])('renders without crashing for trend=%s', (trend) => {
    render(<StatCard label="Métrica" value={5} trend={trend} trendLabel="x" />);
    expect(screen.getByText('x')).toBeInTheDocument();
  });

  it('falls back to flat trend icon for unknown trend value', () => {
    render(<StatCard label="Métrica" value={5} trend="sideways" trendLabel="x" />);
    expect(screen.getByText('x')).toBeInTheDocument();
  });

  it('renders as a div (non-interactive) when no onClick is provided', () => {
    const { container } = render(<StatCard label="Métrica" value={5} />);
    expect(container.querySelector('div')).toBeInTheDocument();
    expect(container.querySelector('button')).not.toBeInTheDocument();
  });

  it('renders as a button when onClick is provided', () => {
    const { container } = render(<StatCard label="Métrica" value={5} onClick={() => {}} />);
    expect(container.querySelector('button')).toBeInTheDocument();
    expect(container.querySelector('button').type).toBe('button');
  });

  it('calls onClick when the button is clicked', () => {
    const onClick = vi.fn();
    render(<StatCard label="Métrica" value={5} onClick={onClick} />);
    fireEvent.click(screen.getByRole('button'));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('applies interactive cursor class when onClick is provided', () => {
    const { container } = render(<StatCard label="Métrica" value={5} onClick={() => {}} />);
    expect(container.querySelector('button').className).toContain('cursor-pointer');
  });

  it.each(['neutral', 'accent', 'success', 'warning', 'danger'])(
    'renders with tone=%s without crashing',
    (tone) => {
      render(<StatCard label="Métrica" value={5} tone={tone} />);
      expect(screen.getByText('Métrica')).toBeInTheDocument();
    }
  );

  it('falls back to neutral tone for unknown tone value', () => {
    const { container } = render(<StatCard label="Métrica" value={5} tone="unknown" />);
    expect(container.firstChild.className).toContain('bg-[var(--nx-surface)]');
  });

  it('applies custom className', () => {
    const { container } = render(<StatCard label="Métrica" value={5} className="my-card" />);
    expect(container.firstChild.className).toContain('my-card');
  });
});
