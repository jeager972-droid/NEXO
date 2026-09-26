import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Card, CardHeader } from '../../components/ui/Card';

describe('Card', () => {
  it('renders children in a div by default', () => {
    render(<Card>Content</Card>);
    expect(screen.getByText('Content')).toBeInTheDocument();
  });

  it('renders as div by default (not button)', () => {
    const { container } = render(<Card>Content</Card>);
    expect(container.querySelector('div')).toBeInTheDocument();
    expect(container.querySelector('button')).not.toBeInTheDocument();
  });

  it('renders as button when asAction is true', () => {
    const { container } = render(<Card asAction>Click me</Card>);
    const btn = container.querySelector('button');
    expect(btn).toBeInTheDocument();
    expect(btn.type).toBe('button');
  });

  it('calls onClick when clicked as action', async () => {
    const onClick = vi.fn();
    const { container } = render(<Card asAction onClick={onClick}>Click</Card>);
    await userEvent.click(container.querySelector('button'));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('calls onClick when clicked as div', async () => {
    const onClick = vi.fn();
    const { container } = render(<Card onClick={onClick}>Click</Card>);
    await userEvent.click(container.querySelector('div'));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it.each(['neutral', 'accent', 'success', 'warning', 'danger'])(
    'renders with tone=%s without crashing',
    (tone) => {
      render(<Card tone={tone}>Test</Card>);
      expect(screen.getByText('Test')).toBeInTheDocument();
    }
  );

  it('renders with edge prop', () => {
    render(<Card tone="danger" edge>Test</Card>);
    expect(screen.getByText('Test')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    const { container } = render(<Card className="my-class">Test</Card>);
    expect(container.querySelector('div').className).toContain('my-class');
  });

  it('forwards ref', () => {
    const ref = { current: null };
    render(<Card ref={ref}>Test</Card>);
    expect(ref.current).toBeInstanceOf(HTMLDivElement);
  });
});

describe('CardHeader', () => {
  it('renders title', () => {
    render(<CardHeader title="My Title" />);
    expect(screen.getByText('My Title')).toBeInTheDocument();
  });

  it('renders subtitle', () => {
    render(<CardHeader title="T" subtitle="Subtitle text" />);
    expect(screen.getByText('Subtitle text')).toBeInTheDocument();
  });

  it('renders icon when provided', () => {
    render(<CardHeader title="T" icon={<span data-testid="icon">★</span>} />);
    expect(screen.getByTestId('icon')).toBeInTheDocument();
  });

  it('renders action when provided', () => {
    render(<CardHeader title="T" action={<button data-testid="action-btn">Go</button>} />);
    expect(screen.getByTestId('action-btn')).toBeInTheDocument();
  });

  it('renders children', () => {
    render(<CardHeader title="T"><div data-testid="child">Extra</div></CardHeader>);
    expect(screen.getByTestId('child')).toBeInTheDocument();
  });

  it('does not render title when not provided', () => {
    const { container } = render(<CardHeader />);
    expect(container.querySelector('h3')).not.toBeInTheDocument();
  });
});
