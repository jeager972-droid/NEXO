import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Badge } from '../../components/ui/Badge';

describe('Badge', () => {
  it('renders children text', () => {
    render(<Badge>Activo</Badge>);
    expect(screen.getByText('Activo')).toBeInTheDocument();
  });

  it('renders as a span element', () => {
    const { container } = render(<Badge>Test</Badge>);
    expect(container.querySelector('span')).toBeInTheDocument();
  });

  it('renders with default neutral scheme', () => {
    const { container } = render(<Badge>Test</Badge>);
    const span = container.querySelector('span');
    expect(span.className).toContain('rounded-full');
  });

  it.each(['neutral', 'accent', 'success', 'warning', 'danger'])(
    'renders with scheme=%s without crashing',
    (scheme) => {
      render(<Badge scheme={scheme}>Test</Badge>);
      expect(screen.getByText('Test')).toBeInTheDocument();
    }
  );

  it('renders dot when dot prop is true', () => {
    const { container } = render(<Badge dot>Test</Badge>);
    const dot = container.querySelector('span[aria-hidden]');
    expect(dot).toBeInTheDocument();
  });

  it('does not render dot by default', () => {
    const { container } = render(<Badge>Test</Badge>);
    const dot = container.querySelector('span[aria-hidden]');
    expect(dot).not.toBeInTheDocument();
  });

  it('renders custom icon', () => {
    const icon = <span data-testid="custom-icon">★</span>;
    render(<Badge icon={icon}>Test</Badge>);
    expect(screen.getByTestId('custom-icon')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    const { container } = render(<Badge className="my-custom">Test</Badge>);
    expect(container.querySelector('span').className).toContain('my-custom');
  });
});
