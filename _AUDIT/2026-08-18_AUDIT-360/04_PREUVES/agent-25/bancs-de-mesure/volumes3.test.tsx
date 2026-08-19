/**
 * AGENT 25 — REPRISE : /audiences aux volumes, avec le BON nom de champ.
 * Ma premiere mesure passait `members_count` ; l'ecran lit `member_count`
 * (AudiencesListPage.tsx:230). Elle mesurait donc un plantage, pas un volume.
 */
import { describe, it, expect } from 'vitest';
import { waitFor } from '@testing-library/react';
import { appendFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { renderScreen } from '../../tests/helpers/renderScreen';
import { getJson } from '../../tests/msw/handlers';
import { AudiencesListPage } from '@/features/audiences/AudiencesListPage';

const F = 'tmp/agent25/out/releve-volumes-3.txt';

describe('AGENT 25 — /audiences aux volumes (reprise)', () => {
  it('000 entete', () => {
    mkdirSync('tmp/agent25/out', { recursive: true });
    writeFileSync(F, 'AGENT 25 — /audiences aux volumes — REPRISE avec `member_count` (le bon champ)\nReference : main 8db8229\n\nroute      | lignes | ms | noeuds DOM | html (Ko) | note\n', 'utf8');
    expect(true).toBe(true);
  });
  for (const n of [0, 1, 100, 10_000]) {
    it(`/audiences — ${n}`, async () => {
      const corps = {
        data: Array.from({ length: n }, (_, i) => ({
          id: i, name: `Audience ${i}`, description: null,
          criteria: { all: [] }, is_active: true, auto_refresh: false,
          member_count: i, refreshed_at: null, created_at: '2026-01-01T00:00:00Z',
        })),
      };
      const t0 = performance.now();
      let noeuds = 0, ko = 0, note = '';
      try {
        await renderScreen(<AudiencesListPage />, { path: '/audiences', url: '/audiences', handlers: [getJson('/audiences', corps)] });
        await waitFor(() => {
          expect(document.querySelectorAll('.animate-pulse').length === 0).toBe(true);
        }, { timeout: 60_000 });
        await new Promise((r) => setTimeout(r, 80));
        noeuds = document.querySelectorAll('*').length;
        ko = Math.round(document.body.innerHTML.length / 1024);
        if (/Something went wrong/i.test(document.body.textContent ?? '')) note = 'PLANTAGE';
      } catch (e) { note = `ECHEC: ${(e as Error).message?.slice(0, 100)}`; }
      appendFileSync(F, `/audiences | ${String(n).padStart(6)} | ${String(Math.round(performance.now() - t0)).padStart(6)} | ${String(noeuds).padStart(10)} | ${String(ko).padStart(9)} | ${note}\n`, 'utf8');
      expect(true).toBe(true);
    }, 180_000);
  }
});
