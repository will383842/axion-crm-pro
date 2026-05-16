import { describe, it, expect } from 'vitest';
import { extractEmails, extractPhones } from '../src/utils/extract';

describe('extractEmails', () => {
  it('extracts unique lowercased emails', () => {
    const out = extractEmails('Contact: John@Example.com or john@example.com ; other: a@b.fr');
    expect(out.sort()).toEqual(['a@b.fr', 'john@example.com']);
  });

  it('returns [] when no email present', () => {
    expect(extractEmails('no email here')).toEqual([]);
  });
});

describe('extractPhones', () => {
  it('extracts FR phones normalized', () => {
    const out = extractPhones('Tél : 01 23 45 67 89 et +33 6 12 34 56 78');
    expect(out.length).toBeGreaterThan(0);
  });
});
