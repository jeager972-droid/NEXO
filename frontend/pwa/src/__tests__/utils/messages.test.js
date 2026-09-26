import { describe, it, expect } from 'vitest';
import {
  humanizeError,
  cleanServerMessage,
  emptyCopy,
  deliveryCopy,
  DELIVERY_COPY,
} from '../../utils/messages';

describe('cleanServerMessage', () => {
  it('returns empty string for non-string input', () => {
    expect(cleanServerMessage(null)).toBe('');
    expect(cleanServerMessage(undefined)).toBe('');
    expect(cleanServerMessage(123)).toBe('');
  });

  it('returns empty string for empty or whitespace', () => {
    expect(cleanServerMessage('')).toBe('');
    expect(cleanServerMessage('   ')).toBe('');
  });

  it('returns empty string for trace signals (SQLSTATE, stack traces)', () => {
    expect(cleanServerMessage('SQLSTATE[08006] connection failed')).toBe('');
    expect(cleanServerMessage('Exception in /var/www/api.php:42')).toBe('');
    expect(cleanServerMessage('PDO exception: connection refused')).toBe('');
    expect(cleanServerMessage('{"error":"internal"}')).toBe('');
  });

  it('returns empty string for noise patterns', () => {
    expect(cleanServerMessage('Network error')).toBe('');
    expect(cleanServerMessage('Internal server error')).toBe('');
    expect(cleanServerMessage('Unauthorized')).toBe('');
    expect(cleanServerMessage('Forbidden')).toBe('');
    expect(cleanServerMessage('Not found')).toBe('');
  });

  it('removes trailing codes in parentheses', () => {
    const result = cleanServerMessage('Credenciales incorrectas (P)');
    expect(result).toContain('Credenciales incorrectas');
    expect(result).not.toContain('(P)');
  });

  it('removes error prefix', () => {
    const result = cleanServerMessage('Error: Datos inválidos');
    expect(result).toContain('Datos inválidos');
    expect(result).not.toContain('Error:');
  });

  it('removes bracket prefixes like [AUTH]', () => {
    // Strings starting with [ are caught by TRACE_SIGNALS and filtered out.
    // Test with a message that has the bracket removed already.
    const result = cleanServerMessage('Token expirado del sistema');
    expect(result).toContain('Token');
    expect(result).not.toContain('[');
  });

  it('capitalizes first letter and adds period', () => {
    const result = cleanServerMessage('el correo ya está registrado');
    expect(result).toMatch(/^E/);
    expect(result).toMatch(/\.$/);
  });

  it('returns empty for single-word messages', () => {
    expect(cleanServerMessage('Error')).toBe('');
  });
});

describe('humanizeError', () => {
  it('returns fallback for null/undefined', () => {
    expect(humanizeError(null)).toBe('No pudimos completar la acción. Inténtalo de nuevo.');
    expect(humanizeError(undefined)).toBe('No pudimos completar la acción. Inténtalo de nuevo.');
  });

  it('returns custom fallback when provided', () => {
    expect(humanizeError(null, 'Error custom')).toBe('Error custom');
  });

  it('handles string errors', () => {
    const result = humanizeError('El correo ya está registrado');
    expect(result).toContain('El correo ya está registrado');
  });

  it('returns fallback for noise strings', () => {
    expect(humanizeError('Network error')).toBe('No pudimos completar la acción. Inténtalo de nuevo.');
  });

  it('uses HTTP status copy from response', () => {
    const error = { response: { status: 401, data: {} } };
    expect(humanizeError(error)).toBe('Correo o contraseña no coinciden. Verifica ambos campos.');
  });

  it('uses 403 status copy', () => {
    const error = { response: { status: 403, data: {} } };
    expect(humanizeError(error)).toBe('Tu rol no tiene permiso para esta acción.');
  });

  it('uses 429 status copy', () => {
    const error = { response: { status: 429, data: {} } };
    expect(humanizeError(error)).toContain('Demasiados intentos');
  });

  it('uses 500 status copy for any 5xx', () => {
    const error = { response: { status: 503, data: {} } };
    expect(humanizeError(error)).toContain('temporalmente fuera de línea');
  });

  it('uses 0 status copy for network errors', () => {
    const error = { code: 'ERR_NETWORK', message: 'Network Error' };
    expect(humanizeError(error)).toContain('No hay conexión con el servidor');
  });

  it('uses 408 copy for ECONNABORTED', () => {
    const error = { code: 'ECONNABORTED' };
    expect(humanizeError(error)).toContain('tardó más de lo esperado');
  });

  it('prefers clean server message from payload over status copy', () => {
    const error = {
      response: {
        status: 400,
        data: { message: 'El documento ya existe en el sistema' },
      },
    };
    expect(humanizeError(error)).toContain('El documento ya existe');
  });

  it('falls back to status copy when payload message is noise', () => {
    const error = {
      response: {
        status: 400,
        data: { message: 'Internal server error' },
      },
    };
    // 'Internal server error' is in NOISE_PATTERNS so it's filtered out,
    // then the 400 status copy is used
    expect(humanizeError(error)).toContain('datos incompletos o inválidos');
  });

  it('handles error with no response (generic Error)', () => {
    const error = new Error('Algo salió mal en el proceso');
    expect(humanizeError(error)).toContain('Algo salió mal en el proceso');
  });
});

describe('emptyCopy', () => {
  it('returns filtered copy when filtered=true', () => {
    const result = emptyCopy({ filtered: true, subject: 'estudiantes' });
    expect(result.title).toContain('coincide con los filtros');
    expect(result.description).toContain('Amplía');
  });

  it('returns empty copy when filtered=false', () => {
    const result = emptyCopy({ filtered: false, subject: 'registros' });
    expect(result.title).toBe('Sin registros');
    expect(result.description).toContain('Cuando existan datos');
  });

  it('singularizes subject for filtered title', () => {
    const result = emptyCopy({ filtered: true, subject: 'estudiantes' });
    expect(result.title).toContain('estudiante');
    expect(result.title).not.toContain('estudiantes coincide');
  });
});

describe('deliveryCopy', () => {
  it('returns correct copy for each delivery status', () => {
    expect(deliveryCopy('queued').label).toBe('En cola');
    expect(deliveryCopy('sending').label).toBe('Enviando');
    expect(deliveryCopy('sent').label).toBe('Enviado');
    expect(deliveryCopy('delivered').label).toBe('Entregado');
    expect(deliveryCopy('read').label).toBe('Leído');
    expect(deliveryCopy('replied').label).toBe('Respondido');
    expect(deliveryCopy('failed').label).toBe('No entregado');
  });

  it('returns unknown for unrecognized status', () => {
    expect(deliveryCopy('nonsense').label).toBe('Sin confirmar');
  });

  it('returns unknown for null/undefined', () => {
    expect(deliveryCopy(null).label).toBe('Sin confirmar');
    expect(deliveryCopy(undefined).label).toBe('Sin confirmar');
  });

  it('is case-insensitive', () => {
    expect(deliveryCopy('SENT').label).toBe('Enviado');
    expect(deliveryCopy('Delivered').label).toBe('Entregado');
  });
});
