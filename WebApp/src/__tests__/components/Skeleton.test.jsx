import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Skeleton, SkeletonText, SkeletonMetrics, SkeletonRows, SkeletonCards } from '../../components/ui/Skeleton';

describe('Skeleton', () => {
  it('renders with aria-hidden', () => {
    const { container } = render(<Skeleton />);
    const el = container.querySelector('div');
    expect(el).toHaveAttribute('aria-hidden');
  });

  it('renders children', () => {
    const { container } = render(<Skeleton><span data-testid="child">x</span></Skeleton>);
    expect(container.querySelector('[data-testid="child"]')).toBeInTheDocument();
  });

  it('applies custom className', () => {
    const { container } = render(<Skeleton className="my-class" />);
    expect(container.querySelector('div').className).toContain('my-class');
  });
});

describe('SkeletonText', () => {
  it('renders default 1 line', () => {
    const { container } = render(<SkeletonText />);
    const skeletons = container.querySelectorAll('.nx-skeleton');
    expect(skeletons).toHaveLength(1);
  });

  it('renders specified number of lines', () => {
    const { container } = render(<SkeletonText lines={4} />);
    const skeletons = container.querySelectorAll('.nx-skeleton');
    expect(skeletons).toHaveLength(4);
  });

  it('last line is shorter when lines > 1', () => {
    const { container } = render(<SkeletonText lines={3} />);
    const skeletons = container.querySelectorAll('.nx-skeleton');
    expect(skeletons[2].className).toContain('w-3/5');
    expect(skeletons[0].className).toContain('w-full');
  });
});

describe('SkeletonMetrics', () => {
  it('renders default 4 metric cards', () => {
    const { container } = render(<SkeletonMetrics />);
    const cards = container.querySelectorAll('.border');
    expect(cards).toHaveLength(4);
  });

  it('renders specified count', () => {
    const { container } = render(<SkeletonMetrics count={6} />);
    const cards = container.querySelectorAll('.border');
    expect(cards).toHaveLength(6);
  });
});

describe('SkeletonRows', () => {
  it('renders default 4 rows', () => {
    const { container } = render(<SkeletonRows />);
    const rows = container.querySelectorAll('.flex.items-center.gap-4');
    expect(rows).toHaveLength(4);
  });

  it('renders specified count', () => {
    const { container } = render(<SkeletonRows count={2} />);
    const rows = container.querySelectorAll('.flex.items-center.gap-4');
    expect(rows).toHaveLength(2);
  });
});

describe('SkeletonCards', () => {
  it('renders default 6 cards', () => {
    const { container } = render(<SkeletonCards />);
    const cards = container.querySelectorAll('.border');
    expect(cards).toHaveLength(6);
  });

  it('renders specified count', () => {
    const { container } = render(<SkeletonCards count={3} />);
    const cards = container.querySelectorAll('.border');
    expect(cards).toHaveLength(3);
  });
});
