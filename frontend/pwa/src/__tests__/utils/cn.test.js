import { describe, it, expect } from 'vitest';
import { cn } from '@/utils/cn';

describe('cn', () => {
  it('combines simple class strings', () => {
    expect(cn('foo', 'bar')).toBe('foo bar');
  });

  it('handles conditional classes via objects', () => {
    expect(cn('base', { active: true, hidden: false })).toBe('base active');
  });

  it('handles arrays of classes', () => {
    expect(cn(['a', 'b'], 'c')).toBe('a b c');
  });

  it('ignores falsy values', () => {
    expect(cn('keep', false, null, undefined, '', 0)).toBe('keep');
  });

  it('merges conflicting Tailwind classes (tailwind-merge)', () => {
    expect(cn('px-2 py-1', 'px-4')).toBe('py-1 px-4');
    expect(cn('text-red-500', 'text-blue-500')).toBe('text-blue-500');
  });

  it('returns empty string for no input', () => {
    expect(cn()).toBe('');
  });
});
