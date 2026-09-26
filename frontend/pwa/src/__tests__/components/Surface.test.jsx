import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Surface, PageHeader, Section, BlockTitle, MetaItem, Divider } from '../../components/ui/Surface';

describe('Surface', () => {
  it('renders children', () => {
    render(<Surface>Content</Surface>);
    expect(screen.getByText('Content')).toBeInTheDocument();
  });

  it('applies elevated class when elevated prop is true', () => {
    const { container } = render(<Surface elevated>Test</Surface>);
    expect(container.querySelector('div').className).toContain('shadow-medium');
  });

  it('does not apply shadow when not elevated', () => {
    const { container } = render(<Surface>Test</Surface>);
    expect(container.querySelector('div').className).not.toContain('shadow-medium');
  });

  it('applies custom className', () => {
    const { container } = render(<Surface className="custom">Test</Surface>);
    expect(container.querySelector('div').className).toContain('custom');
  });

  it('forwards ref', () => {
    const ref = { current: null };
    render(<Surface ref={ref}>Test</Surface>);
    expect(ref.current).toBeInstanceOf(HTMLDivElement);
  });
});

describe('PageHeader', () => {
  it('renders title', () => {
    render(<PageHeader title="Dashboard" />);
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Dashboard');
  });

  it('renders eyebrow text', () => {
    render(<PageHeader title="T" eyebrow="Hoy" />);
    expect(screen.getByText('Hoy')).toBeInTheDocument();
  });

  it('renders subtitle', () => {
    render(<PageHeader title="T" subtitle="A subtitle" />);
    expect(screen.getByText('A subtitle')).toBeInTheDocument();
  });

  it('renders meta content', () => {
    render(<PageHeader title="T" meta={<div data-testid="meta">Meta</div>} />);
    expect(screen.getByTestId('meta')).toBeInTheDocument();
  });

  it('renders actions', () => {
    render(<PageHeader title="T" actions={<button data-testid="action">Go</button>} />);
    expect(screen.getByTestId('action')).toBeInTheDocument();
  });
});

describe('Section', () => {
  it('renders title and children', () => {
    render(<Section title="My Section"><div data-testid="child">Content</div></Section>);
    expect(screen.getByText('My Section')).toBeInTheDocument();
    expect(screen.getByTestId('child')).toBeInTheDocument();
  });

  it('renders subtitle', () => {
    render(<Section title="T" subtitle="Sub"><div>x</div></Section>);
    expect(screen.getByText('Sub')).toBeInTheDocument();
  });

  it('renders action', () => {
    render(<Section title="T" action={<button data-testid="act">Do</button>}><div>x</div></Section>);
    expect(screen.getByTestId('act')).toBeInTheDocument();
  });

  it('renders children without header when no title/subtitle/action', () => {
    render(<Section><div data-testid="only-child">Content</div></Section>);
    expect(screen.getByTestId('only-child')).toBeInTheDocument();
  });
});

describe('BlockTitle', () => {
  it('renders children as h3', () => {
    render(<BlockTitle>My Block</BlockTitle>);
    expect(screen.getByRole('heading', { level: 3 })).toHaveTextContent('My Block');
  });

  it('renders count when provided', () => {
    render(<BlockTitle count={42}>Items</BlockTitle>);
    expect(screen.getByText('42')).toBeInTheDocument();
  });

  it('does not render count when not provided', () => {
    render(<BlockTitle>Items</BlockTitle>);
    expect(screen.queryByText('42')).not.toBeInTheDocument();
  });

  it('renders action', () => {
    render(<BlockTitle action={<button data-testid="btn">Go</button>}>T</BlockTitle>);
    expect(screen.getByTestId('btn')).toBeInTheDocument();
  });
});

describe('MetaItem', () => {
  it('renders children', () => {
    render(<MetaItem>Last updated</MetaItem>);
    expect(screen.getByText('Last updated')).toBeInTheDocument();
  });

  it('renders icon', () => {
    render(<MetaItem icon={<span data-testid="icon">★</span>}>Info</MetaItem>);
    expect(screen.getByTestId('icon')).toBeInTheDocument();
  });
});

describe('Divider', () => {
  it('renders an hr element', () => {
    const { container } = render(<Divider />);
    expect(container.querySelector('hr')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    const { container } = render(<Divider className="my-divider" />);
    expect(container.querySelector('hr').className).toContain('my-divider');
  });
});
