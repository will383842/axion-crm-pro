/**
 * FINITIONS P2 — la langue de la console est FIXÉE au français.
 *
 * L'ancien détecteur lisait la langue du navigateur : un navigateur en anglais
 * basculait l'interface sur un dictionnaire anglais de quelques libellés.
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import i18n from '@/lib/i18n';

describe('i18n — français fixe', () => {
  it('la langue est « fr », et seul le français est chargé', () => {
    expect(i18n.options.lng).toBe('fr');
    expect(i18n.language).toBe('fr');
    expect(Object.keys(i18n.options.resources ?? {})).toEqual(['fr']);
  });

  it('plus aucun détecteur de langue', () => {
    const racine = process.cwd().endsWith('frontend') ? process.cwd() : join(process.cwd(), 'frontend');
    const source = readFileSync(join(racine, 'src', 'lib', 'i18n.ts'), 'utf8');
    expect(source).not.toMatch(/LanguageDetector|languagedetector/);
    const paquet = readFileSync(join(racine, 'package.json'), 'utf8');
    expect(paquet).not.toContain('i18next-browser-languagedetector');
  });
});
