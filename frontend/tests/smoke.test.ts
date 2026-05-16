import { describe, it, expect } from 'vitest';

describe('smoke', () => {
  it('exports environment variables placeholder', () => {
    expect(typeof process.env).toBe('object');
  });
});
