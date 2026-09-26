import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SearchableSelect } from '../../components/ui/SearchableSelect';

const options = [
  { value: 'apple', label: 'Apple' },
  { value: 'banana', label: 'Banana' },
  { value: 'cherry', label: 'Cherry' },
];

describe('SearchableSelect', () => {
  it('renders label', () => {
    render(<SearchableSelect label="Pick fruit" options={options} />);
    expect(screen.getByText('Pick fruit')).toBeInTheDocument();
  });

  it('renders placeholder when no value selected', () => {
    render(<SearchableSelect options={options} placeholder="Choose..." />);
    expect(screen.getByText('Choose...')).toBeInTheDocument();
  });

  it('opens dropdown on click', async () => {
    render(<SearchableSelect options={options} placeholder="Pick" />);
    await userEvent.click(screen.getByRole('button'));
    expect(screen.getByRole('listbox')).toBeInTheDocument();
  });

  it('shows all options when opened', async () => {
    render(<SearchableSelect options={options} placeholder="Pick" />);
    await userEvent.click(screen.getByRole('button'));
    expect(screen.getByRole('option', { name: 'Apple' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Banana' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Cherry' })).toBeInTheDocument();
  });

  it('filters options based on search query', async () => {
    render(<SearchableSelect options={options} placeholder="Pick" />);
    await userEvent.click(screen.getByRole('button'));
    await userEvent.type(screen.getByPlaceholderText('Buscar…'), 'ban');
    expect(screen.getByRole('option', { name: 'Banana' })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: 'Apple' })).not.toBeInTheDocument();
  });

  it('shows empty text when no results', async () => {
    render(<SearchableSelect options={options} placeholder="Pick" emptyText="No items found" />);
    await userEvent.click(screen.getByRole('button'));
    await userEvent.type(screen.getByPlaceholderText('Buscar…'), 'xyz');
    expect(screen.getByText('No items found')).toBeInTheDocument();
  });

  it('calls onChange when option is selected', async () => {
    const onChange = vi.fn();
    render(<SearchableSelect options={options} onChange={onChange} placeholder="Pick" />);
    await userEvent.click(screen.getByRole('button'));
    await userEvent.click(screen.getByRole('option', { name: 'Banana' }));
    expect(onChange).toHaveBeenCalledWith('banana');
  });

  it('closes dropdown after selecting in single mode', async () => {
    render(<SearchableSelect options={options} placeholder="Pick" />);
    await userEvent.click(screen.getByRole('button'));
    await userEvent.click(screen.getByRole('option', { name: 'Apple' }));
    await waitFor(() => {
      expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
    });
  });

  it('shows selected value as label', () => {
    render(<SearchableSelect options={options} value="cherry" />);
    expect(screen.getByText('Cherry')).toBeInTheDocument();
  });

  it('renders required indicator', () => {
    render(<SearchableSelect label="Fruit" options={options} required />);
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('renders error message', () => {
    render(<SearchableSelect options={options} error="This field is required" />);
    expect(screen.getByText('This field is required')).toBeInTheDocument();
  });

  it('renders help text', () => {
    render(<SearchableSelect options={options} help="Select one option" />);
    expect(screen.getByText('Select one option')).toBeInTheDocument();
  });

  it('renders hint', () => {
    render(<SearchableSelect label="L" options={options} hint="Optional" />);
    expect(screen.getByText('Optional')).toBeInTheDocument();
  });

  it('shows clear button when clearable and value selected', () => {
    render(<SearchableSelect options={options} value="apple" clearable onChange={vi.fn()} />);
    const clearBtn = screen.getByRole('button', { name: '' });
    expect(clearBtn).toBeInTheDocument();
  });

  it('calls onChange with empty string when cleared (single mode)', async () => {
    const onChange = vi.fn();
    render(<SearchableSelect options={options} value="apple" clearable onChange={onChange} />);
    // Find the clear button (span with role=button)
    const clearBtn = screen.getByRole('button', { name: '' });
    await userEvent.click(clearBtn);
    expect(onChange).toHaveBeenCalledWith('');
  });

  it('supports multiple selection', async () => {
    const onChange = vi.fn();
    render(<SearchableSelect options={options} multiple onChange={onChange} placeholder="Pick" />);
    await userEvent.click(screen.getByRole('button'));
    await userEvent.click(screen.getByRole('option', { name: 'Apple' }));
    expect(onChange).toHaveBeenCalledWith(['apple']);
  });

  it('toggles selection in multiple mode', async () => {
    const onChange = vi.fn();
    render(<SearchableSelect options={options} multiple value={['apple', 'banana']} onChange={onChange} placeholder="Pick" />);
    await userEvent.click(screen.getByRole('button'));
    await userEvent.click(screen.getByRole('option', { name: 'Apple' }));
    expect(onChange).toHaveBeenCalledWith(['banana']);
  });

  it('sets aria-expanded on toggle button', async () => {
    render(<SearchableSelect options={options} placeholder="Pick" />);
    const btn = screen.getByRole('button');
    expect(btn).toHaveAttribute('aria-expanded', 'false');
    await userEvent.click(btn);
    expect(btn).toHaveAttribute('aria-expanded', 'true');
  });

  it('sets aria-haspopup on toggle button', () => {
    render(<SearchableSelect options={options} placeholder="Pick" />);
    expect(screen.getByRole('button')).toHaveAttribute('aria-haspopup', 'listbox');
  });
});
