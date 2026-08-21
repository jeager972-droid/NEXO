import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { EmptyState } from '../../components/ui/EmptyState';

describe('EmptyState', () => {
  it('renders title', () => {
    render(<EmptyState title="No hay datos" />);
    expect(screen.getByText('No hay datos')).toBeInTheDocument();
  });

  it('renders description', () => {
    render(<EmptyState title="T" description="A description here" />);
    expect(screen.getByText('A description here')).toBeInTheDocument();
  });

  it('renders action', () => {
    render(<EmptyState title="T" action={<button data-testid="action-btn">Reintentar</button>} />);
    expect(screen.getByTestId('action-btn')).toBeInTheDocument();
  });

  it('renders secondaryAction', () => {
    render(<EmptyState title="T" secondaryAction={<button data-testid="secondary">Cancelar</button>} />);
    expect(screen.getByTestId('secondary')).toBeInTheDocument();
  });

  it('renders with role="alert" when variant is error', () => {
    const { container } = render(<EmptyState variant="error" title="Error" />);
    expect(container.firstChild).toHaveAttribute('role', 'alert');
  });

  it('does not set role when variant is not error', () => {
    const { container } = render(<EmptyState variant="empty" title="Empty" />);
    expect(container.firstChild).not.toHaveAttribute('role');
  });

  it.each(['empty', 'error', 'denied', 'offline'])(
    'renders variant=%s without crashing',
    (variant) => {
      render(<EmptyState variant={variant} title="Test" />);
      expect(screen.getByText('Test')).toBeInTheDocument();
    }
  );

  it('renders custom icon when provided', () => {
    render(<EmptyState icon={<span data-testid="custom">★</span>} title="T" />);
    expect(screen.getByTestId('custom')).toBeInTheDocument();
  });

  it('renders fallback icon when no custom icon', () => {
    const { container } = render(<EmptyState title="T" />);
    expect(container.querySelector('svg')).toBeInTheDocument();
  });

  it('applies compact padding when compact is true', () => {
    const { container } = render(<EmptyState compact title="T" />);
    expect(container.firstChild.className).toContain('py-10');
  });

  it('applies default padding when not compact', () => {
    const { container } = render(<EmptyState title="T" />);
    expect(container.firstChild.className).toContain('py-14');
  });

  it('applies custom className', () => {
    const { container } = render(<EmptyState className="my-class" title="T" />);
    expect(container.firstChild.className).toContain('my-class');
  });
});
