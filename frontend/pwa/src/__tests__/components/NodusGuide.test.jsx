import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, act, fireEvent, waitForElementToBeRemoved } from '@testing-library/react';
import { NodusGuide } from '../../components/patterns/NodusGuide';

// El efecto typewriter corre con setInterval(18ms) — timers falsos.
const finishTyping = () => act(() => { vi.advanceTimersByTime(4000); });

describe('NodusGuide', () => {
  beforeEach(() => { vi.useFakeTimers(); });
  afterEach(() => { vi.useRealTimers(); });

  it('muestra el primer mensaje del guion y avanza al tocar la burbuja', () => {
    render(<NodusGuide script={[{ text: 'Hola' }, { text: 'Segundo mensaje' }]} />);
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
      <NodusGuide
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
      <NodusGuide script={[{ text: 'Texto largo para escribir', chips: [{ label: 'Revisar', action: vi.fn() }] }]} />
    );
    expect(screen.queryByRole('button', { name: 'Revisar' })).not.toBeInTheDocument();
  });

  it('el botón de descarte llama onDismiss y retira la burbuja', () => {
    const onDismiss = vi.fn();
    render(<NodusGuide script={[{ text: 'Aviso', dismissKey: 'k1' }]} onDismiss={onDismiss} />);
    finishTyping();
    fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
    // onDismiss recibe las llaves del guion (dismissKey o texto)
    expect(onDismiss).toHaveBeenCalledWith(['k1']);
  });

  it('el contador solo aparece cuando hay más de un mensaje', () => {
    render(<NodusGuide script={[{ text: 'Único' }]} />);
    finishTyping();
    expect(screen.queryByText('1 / 1')).not.toBeInTheDocument();
  });

  it('un array nuevo con el mismo contenido no revive una burbuja descartada', async () => {
    // El polling de notificaciones reconstruye el script cada minuto: el
    // reset debe depender del CONTENIDO (dismissKey/texto), no de la
    // identidad del array. La animación de salida de AnimatePresence corre
    // con tiempo real — este test apaga los timers falsos.
    vi.useRealTimers();
    const { rerender } = render(<NodusGuide script={[{ text: 'Aviso puntual', dismissKey: 'k1' }]} />);
    await act(async () => { await new Promise((r) => setTimeout(r, 500)); });
    fireEvent.click(screen.getByRole('button', { name: 'Descartar' }));
    await waitForElementToBeRemoved(() => screen.queryByText('Aviso puntual'));

    rerender(<NodusGuide script={[{ text: 'Aviso puntual', dismissKey: 'k1' }]} />);
    await act(async () => { await new Promise((r) => setTimeout(r, 300)); });
    expect(screen.queryByText(/aviso puntual/i)).not.toBeInTheDocument();

    // Contenido distinto → sí reaparece (evento nuevo)
    rerender(<NodusGuide script={[{ text: 'Otro aviso', dismissKey: 'k2' }]} />);
    expect(await screen.findByText(/otro aviso/i)).toBeInTheDocument();
  });
});
