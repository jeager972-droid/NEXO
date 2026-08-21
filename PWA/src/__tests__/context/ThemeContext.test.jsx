import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, act } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider, useTheme } from '@/context/ThemeContext';

const renderWithProvider = (ui) =>
  render(
    <MemoryRouter>
      <ThemeProvider>{ui}</ThemeProvider>
    </MemoryRouter>
  );

// Probe captures the context value via the hook and renders interactive controls.
const Probe = ({ onValue }) => {
  const ctx = useTheme();
  if (onValue) onValue(ctx);
  return (
    <div>
      <span data-testid="dark-mode">{ctx.darkMode ? 'on' : 'off'}</span>
      <button data-testid="toggle-btn" onClick={ctx.toggleDarkMode}>
        Toggle
      </button>
    </div>
  );
};

describe('ThemeProvider', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    document.documentElement.classList.remove('dark');
  });

  it('provides default darkMode=false when nothing stored', () => {
    let contextValue;
    renderWithProvider(<Probe onValue={(v) => (contextValue = v)} />);

    expect(contextValue.darkMode).toBe(false);
    expect(typeof contextValue.toggleDarkMode).toBe('function');
  });

  it('reads darkMode=true from localStorage', () => {
    localStorage.setItem('darkMode', 'true');
    let contextValue;
    renderWithProvider(<Probe onValue={(v) => (contextValue = v)} />);

    expect(contextValue.darkMode).toBe(true);
  });

  it('returns false when localStorage has invalid JSON', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    localStorage.setItem('darkMode', '{invalid json');
    let contextValue;
    renderWithProvider(<Probe onValue={(v) => (contextValue = v)} />);

    expect(contextValue.darkMode).toBe(false);
    expect(spy).toHaveBeenCalled();
    spy.mockRestore();
  });

  it('throws when useTheme is used outside provider', () => {
    const spy = vi.spyOn(console, 'error').mockImplementation(() => {});
    const Outside = () => {
      useTheme();
      return null;
    };

    expect(() => render(<Outside />)).toThrow(
      'useTheme debe ser usado dentro de un ThemeProvider'
    );
    spy.mockRestore();
  });

  it('toggleDarkMode flips darkMode and persists to localStorage', () => {
    const { getByTestId } = renderWithProvider(<Probe />);

    expect(getByTestId('dark-mode').textContent).toBe('off');
    expect(localStorage.getItem('darkMode')).toBe('false');

    act(() => {
      getByTestId('toggle-btn').click();
    });

    expect(getByTestId('dark-mode').textContent).toBe('on');
    expect(localStorage.getItem('darkMode')).toBe('true');
  });

  it('adds dark class to documentElement when darkMode is true', () => {
    localStorage.setItem('darkMode', 'true');
    renderWithProvider(<Probe />);

    expect(document.documentElement.classList.contains('dark')).toBe(true);
  });

  it('removes dark class when toggled off', () => {
    localStorage.setItem('darkMode', 'true');
    const { getByTestId } = renderWithProvider(<Probe />);

    expect(document.documentElement.classList.contains('dark')).toBe(true);

    act(() => {
      getByTestId('toggle-btn').click();
    });

    expect(document.documentElement.classList.contains('dark')).toBe(false);
    expect(localStorage.getItem('darkMode')).toBe('false');
  });

  it('does not add dark class when darkMode is false', () => {
    renderWithProvider(<Probe />);
    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });
});
