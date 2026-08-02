import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { Drawer, Dialog, ConfirmDialog } from '../../components/ui/Overlay';

describe('Drawer', () => {
  it('renders title', () => {
    render(<Drawer title="My Drawer" onClose={vi.fn()}>Content</Drawer>);
    expect(screen.getByText('My Drawer')).toBeInTheDocument();
  });

  it('renders context text when provided', () => {
    render(<Drawer title="T" context="Student: Juan" onClose={vi.fn()}>x</Drawer>);
    expect(screen.getByText('Student: Juan')).toBeInTheDocument();
  });

  it('renders children content', () => {
    render(<Drawer title="T" onClose={vi.fn()}><div data-testid="content">Body</div></Drawer>);
    expect(screen.getByTestId('content')).toBeInTheDocument();
  });

  it('renders footer when provided', () => {
    render(<Drawer title="T" onClose={vi.fn()} footer={<div data-testid="footer">Footer</div>}>x</Drawer>);
    expect(screen.getByTestId('footer')).toBeInTheDocument();
  });

  it('has role="dialog" and aria-modal', () => {
    render(<Drawer title="T" onClose={vi.fn()}>x</Drawer>);
    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
  });

  it('calls onClose when close button is clicked', async () => {
    const onClose = vi.fn();
    render(<Drawer title="T" onClose={onClose}>x</Drawer>);
    const closeBtn = screen.getByLabelText('Cerrar panel');
    await userEvent.click(closeBtn);
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});

describe('Dialog', () => {
  it('renders title', () => {
    render(<Dialog title="My Dialog" onClose={vi.fn()} />);
    expect(screen.getByText('My Dialog')).toBeInTheDocument();
  });

  it('renders description', () => {
    render(<Dialog title="T" description="A description" onClose={vi.fn()} />);
    expect(screen.getByText('A description')).toBeInTheDocument();
  });

  it('renders children', () => {
    render(<Dialog title="T" onClose={vi.fn()}><div data-testid="child">Content</div></Dialog>);
    expect(screen.getByTestId('child')).toBeInTheDocument();
  });

  it('renders footer', () => {
    render(<Dialog title="T" onClose={vi.fn()} footer={<button data-testid="btn">OK</button>} />);
    expect(screen.getByTestId('btn')).toBeInTheDocument();
  });

  it('has role="dialog" and aria-modal', () => {
    render(<Dialog title="T" onClose={vi.fn()} />);
    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveAttribute('aria-modal', 'true');
  });
});

describe('ConfirmDialog', () => {
  it('renders title and description', () => {
    render(<ConfirmDialog title="¿Eliminar?" description="Esta acción no se puede deshacer" onClose={vi.fn()} onConfirm={vi.fn()} confirmLabel="Eliminar" />);
    expect(screen.getByText('¿Eliminar?')).toBeInTheDocument();
    expect(screen.getByText('Esta acción no se puede deshacer')).toBeInTheDocument();
  });

  it('renders consequence text', () => {
    render(<ConfirmDialog title="T" consequence="Se perderán 5 registros" onClose={vi.fn()} onConfirm={vi.fn()} confirmLabel="OK" />);
    expect(screen.getByText('Se perderán 5 registros')).toBeInTheDocument();
  });

  it('renders cancel and confirm buttons', () => {
    render(<ConfirmDialog title="T" onClose={vi.fn()} onConfirm={vi.fn()} confirmLabel="Eliminar" />);
    expect(screen.getByText('Cancelar')).toBeInTheDocument();
    expect(screen.getByText('Eliminar')).toBeInTheDocument();
  });

  it('calls onConfirm when confirm button is clicked', async () => {
    const onConfirm = vi.fn();
    const onClose = vi.fn();
    render(<ConfirmDialog title="T" onClose={onClose} onConfirm={onConfirm} confirmLabel="Eliminar" />);
    await userEvent.click(screen.getByText('Eliminar'));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('calls onClose when cancel button is clicked', async () => {
    const onClose = vi.fn();
    render(<ConfirmDialog title="T" onClose={onClose} onConfirm={vi.fn()} confirmLabel="OK" />);
    await userEvent.click(screen.getByText('Cancelar'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('renders with default cancel label', () => {
    render(<ConfirmDialog title="T" onClose={vi.fn()} onConfirm={vi.fn()} confirmLabel="OK" />);
    expect(screen.getByText('Cancelar')).toBeInTheDocument();
  });

  it('renders with custom cancel label', () => {
    render(<ConfirmDialog title="T" cancelLabel="No, volver" onClose={vi.fn()} onConfirm={vi.fn()} confirmLabel="OK" />);
    expect(screen.getByText('No, volver')).toBeInTheDocument();
  });
});
