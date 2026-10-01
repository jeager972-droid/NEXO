import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useSpeechInput } from '@/hooks/useSpeechInput';

// SpeechRecognitionResult mínimo: indexable ([0] = alternativa) + isFinal.
const makeResult = (transcript, isFinal) => Object.assign([{ transcript }], { isFinal });

class MockRecognition {
  constructor() { MockRecognition.instances.push(this); }
  start() { this.started = true; }
  stop() { this.stopped = true; this.onend?.(); }
}
MockRecognition.instances = [];

beforeEach(() => {
  MockRecognition.instances = [];
  window.SpeechRecognition = MockRecognition;
});
afterEach(() => {
  delete window.SpeechRecognition;
  delete window.webkitSpeechRecognition;
});

describe('useSpeechInput', () => {
  it('reports unsupported and no-ops when the API is missing', () => {
    delete window.SpeechRecognition;
    const onResult = vi.fn();
    const { result } = renderHook(() => useSpeechInput({ onResult }));
    expect(result.current.supported).toBe(false);
    act(() => result.current.toggle());
    expect(result.current.listening).toBe(false);
    expect(onResult).not.toHaveBeenCalled();
  });

  it('starts a es-CO session and streams interim plus final transcripts', () => {
    const onResult = vi.fn();
    const { result } = renderHook(() => useSpeechInput({ onResult }));
    expect(result.current.supported).toBe(true);
    act(() => result.current.toggle());
    expect(result.current.listening).toBe(true);
    const rec = MockRecognition.instances[0];
    expect(rec.lang).toBe('es-CO');
    expect(rec.interimResults).toBe(true);
    act(() => rec.onresult({ results: [makeResult('hola ', false)] }));
    expect(onResult).toHaveBeenLastCalledWith('hola ', false);
    act(() => rec.onresult({ results: [makeResult('hola ', true), makeResult('mundo', true)] }));
    expect(onResult).toHaveBeenLastCalledWith('hola mundo', true);
    act(() => rec.onend());
    expect(result.current.listening).toBe(false);
  });

  it('toggles off via stop and releases the session on unmount', () => {
    const { result, unmount } = renderHook(() => useSpeechInput());
    act(() => result.current.toggle());
    const rec = MockRecognition.instances[0];
    act(() => result.current.toggle());
    expect(rec.stopped).toBe(true);
    expect(result.current.listening).toBe(false);
    act(() => result.current.start());
    const rec2 = MockRecognition.instances[1];
    unmount();
    expect(rec2.stopped).toBe(true);
  });
});
