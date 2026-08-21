import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Button } from '../../components/ui/Button';
import { Loader2 } from 'lucide-react';

describe('Button', () => {
  it('renders children text', () => {
    render(<Button>Click me</Button>);
    expect(screen.getByText('Click me')).toBeInTheDocument();
  });

  it('applies primary variant by default', () => {
    render(<Button>Default</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-[var(--nx-surface-accent)]');
  });

  it('applies secondary variant', () => {
    render(<Button variant="secondary">Secondary</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-[var(--nx-surface)]');
  });

  it('applies danger variant', () => {
    render(<Button variant="danger">Delete</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-[var(--nx-danger)]');
  });

  it('applies quiet variant', () => {
    render(<Button variant="quiet">Quiet</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-transparent');
  });

  it('applies ghost variant', () => {
    render(<Button variant="ghost">Ghost</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-transparent');
    expect(btn.className).toContain('text-[var(--nx-text-muted)]');
  });

  it('applies size classes', () => {
    const { rerender } = render(<Button size="sm">Small</Button>);
    expect(screen.getByRole('button').className).toContain('h-9');

    rerender(<Button size="md">Medium</Button>);
    expect(screen.getByRole('button').className).toContain('h-11');

    rerender(<Button size="lg">Large</Button>);
    expect(screen.getByRole('button').className).toContain('h-13');
  });

  it('shows spinner and aria-busy when loading', () => {
    render(<Button loading>Saving</Button>);
    const btn = screen.getByRole('button');
    expect(btn).toHaveAttribute('aria-busy', 'true');
    expect(btn).toBeDisabled();
    expect(btn.querySelector('.animate-spin')).toBeInTheDocument();
  });

  it('shows loadingLabel when provided', () => {
    render(<Button loading loadingLabel="Guardando...">Save</Button>);
    expect(screen.getByText('Guardando...')).toBeInTheDocument();
    expect(screen.queryByText('Save')).not.toBeInTheDocument();
  });

  it('is disabled when disabled prop is true', () => {
    render(<Button disabled>Disabled</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('applies block (w-full) class', () => {
    render(<Button block>Full width</Button>);
    expect(screen.getByRole('button').className).toContain('w-full');
  });

  it('renders leftIcon', () => {
    render(<Button leftIcon={<Loader2 data-testid="icon" size={16} />}>With Icon</Button>);
    expect(screen.getByTestId('icon')).toBeInTheDocument();
  });

  it('renders rightIcon when not loading', () => {
    render(<Button rightIcon={<span data-testid="right-icon">→</span>}>Next</Button>);
    expect(screen.getByTestId('right-icon')).toBeInTheDocument();
  });

  it('does not render rightIcon when loading', () => {
    render(<Button loading rightIcon={<span data-testid="right-icon">→</span>}>Next</Button>);
    expect(screen.queryByTestId('right-icon')).not.toBeInTheDocument();
  });

  it('defaults to type="button"', () => {
    render(<Button>Default Type</Button>);
    expect(screen.getByRole('button')).toHaveAttribute('type', 'button');
  });

  it('forwards type="submit"', () => {
    render(<Button type="submit">Submit</Button>);
    expect(screen.getByRole('button')).toHaveAttribute('type', 'submit');
  });
});
