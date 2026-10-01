import { describe, it, expect, beforeEach } from 'vitest';
import { setPendingPrompt, takePendingPrompt } from '@/lib/chatContext';

const KEY = 'nx:chat:pending';

describe('chatContext — pending prompt (deep-links al chat)', () => {
  beforeEach(() => sessionStorage.clear());

  it('entrega el texto una sola vez y borra la clave', () => {
    setPendingPrompt('¿Cuántas tardanzas van hoy?');
    expect(takePendingPrompt()).toBe('¿Cuántas tardanzas van hoy?');
    expect(takePendingPrompt()).toBeNull();
    expect(sessionStorage.getItem(KEY)).toBeNull();
  });

  it('descarta prompts vencidos (>2 min) y limpia la clave igualmente', () => {
    sessionStorage.setItem(KEY, JSON.stringify({ text: 'pregunta vieja', ts: Date.now() - 3 * 60 * 1000 }));
    expect(takePendingPrompt()).toBeNull();
    expect(sessionStorage.getItem(KEY)).toBeNull();
  });

  it.each([
    ['sin ts', '{"text":"hola"}'],
    ['vacío', '{}'],
    ['null', 'null'],
    ['solo espacios', '{"text":"   "}'],
    ['JSON corrupto', 'no-json{'],
  ])('ignora contenido inválido (%s)', (_label, raw) => {
    sessionStorage.setItem(KEY, raw);
    expect(takePendingPrompt()).toBeNull();
  });

  it.each([[null], [undefined], ['   '], ['']])('no guarda textos vacíos (%s)', (t) => {
    setPendingPrompt(t);
    expect(sessionStorage.getItem(KEY)).toBeNull();
  });

  it('recorta a 500 caracteres (límite de /chat/message)', () => {
    setPendingPrompt(`  ${'x'.repeat(600)}  `);
    expect(takePendingPrompt()).toHaveLength(500);
  });
});
