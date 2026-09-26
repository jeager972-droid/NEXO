import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Select } from '../../components/ui/Select';

const options = [
  { value: 'a', label: 'Option A' },
  { value: 'b', label: 'Option B' },
  { value: 'c', label: 'Option C' },
];

describe('Select', () => {
  it('renders label text', () => {
    render(<Select label="Choose one" options={options} />);
    expect(screen.getByText('Choose one')).toBeInTheDocument();
  });

  it('renders required indicator when required', () => {
    render(<Select label="Required field" options={options} required />);
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('renders hint when provided', () => {
    render(<Select label="L" options={options} hint="Pick wisely" />);
    expect(screen.getByText('Pick wisely')).toBeInTheDocument();
  });

  it('renders help text when provided', () => {
    render(<Select label="L" options={options} help="This is help" />);
    expect(screen.getByText('This is help')).toBeInTheDocument();
  });

  it('renders error message and aria-invalid', () => {
    render(<Select label="L" options={options} error="Required field" />);
    expect(screen.getByText('Required field')).toBeInTheDocument();
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
  });

  it('renders placeholder as disabled option', () => {
    render(<Select options={options} placeholder="Select..." />);
    expect(screen.getByText('Select...')).toBeInTheDocument();
  });

  it('renders all options', () => {
    render(<Select options={options} />);
    expect(screen.getByRole('option', { name: 'Option A' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Option B' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Option C' })).toBeInTheDocument();
  });

  it('user can select an option', async () => {
    render(<Select options={options} />);
    const select = screen.getByRole('combobox');
    await userEvent.selectOptions(select, 'b');
    expect(select.value).toBe('b');
  });

  it('forwards ref', () => {
    const ref = { current: null };
    render(<Select ref={ref} options={options} />);
    expect(ref.current).toBeInstanceOf(HTMLSelectElement);
  });

  it('applies custom className', () => {
    const { container } = render(<Select options={options} className="my-class" />);
    expect(container.firstChild.className).toContain('my-class');
  });

  it('shows error instead of help when both are provided', () => {
    render(<Select options={options} error="Error!" help="Help text" />);
    expect(screen.getByText('Error!')).toBeInTheDocument();
    expect(screen.queryByText('Help text')).not.toBeInTheDocument();
  });
});
