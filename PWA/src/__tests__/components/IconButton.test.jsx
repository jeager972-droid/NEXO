import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { IconButton } from '../../components/ui/IconButton';

describe('IconButton', () => {
  it('renders children (icon)', () => {
    render(<IconButton label="Close"><span data-testid="icon">X</span></IconButton>);
    expect(screen.getByTestId('icon')).toBeInTheDocument();
  });

  it('sets aria-label from label prop', () => {
    render(<IconButton label="Delete"><span>x</span></IconButton>);
    expect(screen.getByRole('button')).toHaveAttribute('aria-label', 'Delete');
  });

  it('sets title from label prop', () => {
    render(<IconButton label="Settings"><span>x</span></IconButton>);
    expect(screen.getByRole('button')).toHaveAttribute('title', 'Settings');
  });

  it('sets aria-label from aria-label prop when no label', () => {
    render(<IconButton aria-label="Edit"><span>x</span></IconButton>);
    expect(screen.getByRole('button')).toHaveAttribute('aria-label', 'Edit');
  });

  it('calls onClick when clicked', async () => {
    const onClick = vi.fn();
    render(<IconButton label="Test" onClick={onClick}><span>x</span></IconButton>);
    await userEvent.click(screen.getByRole('button'));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('defaults to type="button"', () => {
    render(<IconButton label="Test"><span>x</span></IconButton>);
    expect(screen.getByRole('button')).toHaveAttribute('type', 'button');
  });

  it.each(['primary', 'secondary', 'quiet', 'danger'])(
    'renders variant=%s without crashing',
    (variant) => {
      render(<IconButton label="Test" variant={variant}><span>x</span></IconButton>);
      expect(screen.getByRole('button')).toBeInTheDocument();
    }
  );

  it.each(['sm', 'md', 'lg'])(
    'renders size=%s without crashing',
    (size) => {
      render(<IconButton label="Test" size={size}><span>x</span></IconButton>);
      expect(screen.getByRole('button')).toBeInTheDocument();
    }
  );

  it('applies custom className', () => {
    render(<IconButton label="Test" className="my-class"><span>x</span></IconButton>);
    expect(screen.getByRole('button').className).toContain('my-class');
  });

  it('forwards ref', () => {
    const ref = { current: null };
    render(<IconButton ref={ref} label="Test"><span>x</span></IconButton>);
    expect(ref.current).toBeInstanceOf(HTMLButtonElement);
  });
});
