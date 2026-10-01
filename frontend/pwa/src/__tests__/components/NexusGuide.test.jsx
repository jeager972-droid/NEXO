import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act, fireEvent } from '@testing-library/react';
import { NexusGuide } from '../../components/patterns/NexusGuide';

// El efecto typewriter corre con setInterval(18ms) — timers falsos.
const finishTyping = () => act(() => { vi.advanceTimersByTime(4000); });

describe('NexusGuide', () => {
  beforeEach(() => { vi.useFakeTimers(); });
  afterEach(() => { vi.useRealTimers(); });

  it('muestra el primer mensaje del guion y avanza al tocar la burbuja', () => {
    render(<NexusGuide script={[{ text: 'Hola' }, { text: 'Segundo mensaje' }]} />);
    finishTyping();
    const bubble = screen.getByRole('button', { name: /hola/i });
    fireEvent.click(bubble);
    finishTyping();
    expect(screen.getByText(/segundo mensaje/i)).toBeInTheDocument();
    expect(screen.getByText('2 / 2')).toBeInTheDocument();
  });

  it('renderiza los chips como botones y ejecuta su acción', () => {
    const onReview = vi.fn();
    const onIgnore = vi.fn();
    render(
      <NexusGuide
        script={[{
          text: 'Llegaron notificaciones',
          chips: [
            { label: 'Revisar', action: onReview },
            { label: 'Ignorar', action: onIgnore },
          ],
        }]}
      />
    );
    finishTyping();
    fireEvent.click(screen.getByRole('button', { name: 'Revisar' }));
    expect(onReview).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: 'Ignorar' }));
    expect(onIgnore).toHaveBeenCalledTimes(1);
  });

  it('oculta los chips mientras el texto se escribe', () => {
    render(
      <NexusGuide script={[{ text: 'Texto largo para escribir', chips: [{ label: 'Revisar', action: vi.fn() }] }]} />
    );
    expect(screen.queryByRole('button', { name: 'Revisar' })).not.toBeInTheDocument();
  });

  it('el botón de descarte llama onDismiss y retira la burbuja', () => {
    const onDismiss = vi.fn();
    render(<NexusGuide script={[{ text: 'Aviso', dismissKey: 'k1' }]} onDismiss={onDismiss} />);
    finishTyping();
    fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
    // onDismiss recibe las llaves del guion (dismissKey o texto)
    expect(onDismiss).toHaveBeenCalledWith(['k1']);
  });

  it('el contador solo aparece cuando hay más de un mensaje', () => {
    render(<NexusGuide script={[{ text: 'Único' }]} />);
    finishTyping();
    expect(screen.queryByText('1 / 1')).not.toBeInTheDocument();
  });
});
