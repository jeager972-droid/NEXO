import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Input, PasswordInput, Textarea } from '../../components/ui/Input';

describe('Input', () => {
  it('renders label text', () => {
    render(<Input label="Correo electrónico" />);
    expect(screen.getByText('Correo electrónico')).toBeInTheDocument();
  });

  it('renders required indicator when required', () => {
    render(<Input label="Nombre" required />);
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('renders error message with aria-invalid', () => {
    render(<Input label="Email" error="Campo obligatorio" />);
    expect(screen.getByText('Campo obligatorio')).toBeInTheDocument();
    const input = screen.getByRole('textbox');
    expect(input).toHaveAttribute('aria-invalid', 'true');
  });

  it('renders help text when no error', () => {
    render(<Input label="Email" help="Usa tu correo institucional" />);
    expect(screen.getByText('Usa tu correo institucional')).toBeInTheDocument();
  });

  it('does not render help text when error is present', () => {
    render(<Input label="Email" help="Help text" error="Error message" />);
    expect(screen.queryByText('Help text')).not.toBeInTheDocument();
    expect(screen.getByText('Error message')).toBeInTheDocument();
  });

  it('renders hint in label row', () => {
    render(<Input label="Código" hint="6 dígitos" />);
    expect(screen.getByText('6 dígitos')).toBeInTheDocument();
  });

  it('passes through input attributes', () => {
    render(<Input label="Email" type="email" placeholder="ejemplo@nexo.edu" />);
    const input = screen.getByPlaceholderText('ejemplo@nexo.edu');
    expect(input).toHaveAttribute('type', 'email');
  });

  it('supports user typing', async () => {
    render(<Input label="Nombre" />);
    const input = screen.getByRole('textbox');
    await userEvent.type(input, 'Juan Pérez');
    expect(input).toHaveValue('Juan Pérez');
  });
});

describe('PasswordInput', () => {
  it('starts with type=password', () => {
    render(<PasswordInput label="Contraseña" />);
    const input = document.querySelector('input');
    expect(input).toHaveAttribute('type', 'password');
  });

  it('toggles to type=text when eye button clicked', async () => {
    render(<PasswordInput label="Contraseña" />);
    const input = document.querySelector('input');
    const toggleBtn = screen.getByRole('button', { name: /mostrar contraseña/i });
    await userEvent.click(toggleBtn);
    expect(input).toHaveAttribute('type', 'text');
  });

  it('toggles back to password when clicked again', async () => {
    render(<PasswordInput label="Contraseña" />);
    const input = document.querySelector('input');
    const toggleBtn = screen.getByRole('button', { name: /mostrar contraseña/i });
    await userEvent.click(toggleBtn);
    await userEvent.click(screen.getByRole('button', { name: /ocultar contraseña/i }));
    expect(input).toHaveAttribute('type', 'password');
  });
});

describe('Textarea', () => {
  it('renders label and textarea element', () => {
    render(<Textarea label="Descripción" />);
    expect(screen.getByText('Descripción')).toBeInTheDocument();
    expect(screen.getByRole('textbox')).toBeInTheDocument();
  });

  it('renders error with aria-invalid', () => {
    render(<Textarea label="Notas" error="Muy corto" />);
    const textarea = screen.getByRole('textbox');
    expect(textarea).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByText('Muy corto')).toBeInTheDocument();
  });

  it('supports typing', async () => {
    render(<Textarea label="Notas" />);
    const textarea = screen.getByRole('textbox');
    await userEvent.type(textarea, 'Comentario de prueba');
    expect(textarea).toHaveValue('Comentario de prueba');
  });
});
