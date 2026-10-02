/**
 * GARDE — lot 4 (audit UX 2026-10-02, P1-5) : la carte de France ne lance
 * plus JAMAIS une collecte sur un simple clic.
 *
 * Avant : en mode « Action », un clic sur un département appelait
 * `POST /coverage/launch` immédiatement. Une erreur de clic coûtait du quota
 * et des appels externes.
 *
 * Ce que cette garde tient :
 *  1. un clic sur un département SÉLECTIONNE (le panneau s'ouvre) et
 *     n'appelle pas l'API de collecte ;
 *  2. le bouton du panneau ouvre une confirmation qui nomme le département
 *     (nom + code) et le volume, avec « Annuler » au focus ;
 *  3. « Annuler » n'appelle rien ;
 *  4. « Confirmer » appelle l'API UNE seule fois, même cliqué deux fois.
 *
 * La carte réelle (maplibre-gl, WebGL) ne se monte pas sous jsdom : elle est
 * remplacée par une liste de boutons qui appelle le MÊME `onZoneClick`.
 */
import { describe, it, expect, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { delay, http, HttpResponse } from 'msw';

import { CoveragePage } from '@/features/coverage/CoveragePage';
import type { Cell } from '@/features/coverage/statsCouverture';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson } from '../msw/handlers';

vi.mock('@/features/coverage/FranceCoverageMap', () => ({
  FranceCoverageMap: ({ cells, onZoneClick }: { cells: Cell[]; onZoneClick?: (code: string) => void }) => (
    <div>
      {cells.map((c) => (
        <button key={c.code} type="button" onClick={() => onZoneClick?.(c.code)}>
          {`zone ${c.code}`}
        </button>
      ))}
    </div>
  ),
}));

const CELLULES: Cell[] = [
  { code: '69', name: 'Rhône', total: 1200 },
  { code: '38', name: 'Isère', total: 0 },
];

function monterAvecJournal() {
  const lancements: unknown[] = [];
  const handler = http.post(apiUrl('/coverage/launch'), async ({ request }) => {
    lancements.push(await request.json());
    // Une réponse un peu lente : le second clic tombe PENDANT l'envoi.
    await delay(50);
    return HttpResponse.json({ ok: true });
  });
  return { lancements, handler };
}

async function monter() {
  const { lancements, handler } = monterAvecJournal();
  await renderScreen(<CoveragePage />, {
    path: '/coverage',
    handlers: [getJson('/coverage', { cells: CELLULES }), handler],
  });
  await screen.findByRole('button', { name: 'zone 69' });
  return { lancements };
}

describe('Carte de France — aucun clic dangereux', () => {
  it('affiche l’en-tête du système avec le bon titre', async () => {
    await monter();
    expect(screen.getByRole('heading', { level: 1, name: 'Carte de France' })).toBeInTheDocument();
    expect(screen.getByText('Cliquez sur un département pour voir ses entreprises')).toBeInTheDocument();
    // Le sélecteur de modes a disparu.
    expect(screen.queryByRole('button', { name: 'Action' })).toBeNull();
  });

  it('un clic sur un département ouvre le panneau et n’appelle PAS la collecte', async () => {
    const { lancements } = await monter();

    await userEvent.click(screen.getByRole('button', { name: 'zone 69' }));

    expect(
      await screen.findByRole('button', { name: 'Récupérer 100 entreprises de ce département' }),
    ).toBeInTheDocument();
    expect(screen.getByText('Sélection')).toBeInTheDocument();
    // Laisse à un éventuel appel le temps de partir avant de constater qu'il n'est pas parti.
    await new Promise((r) => setTimeout(r, 100));
    expect(lancements).toEqual([]);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('« Annuler » referme la confirmation sans rien lancer', async () => {
    const { lancements } = await monter();

    await userEvent.click(screen.getByRole('button', { name: 'zone 69' }));
    await userEvent.click(
      await screen.findByRole('button', { name: 'Récupérer 100 entreprises de ce département' }),
    );

    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByText('Récupérer 100 entreprises ?')).toBeInTheDocument();
    expect(within(dialogue).getByText('Département : Rhône (69)')).toBeInTheDocument();
    const annuler = within(dialogue).getByRole('button', { name: 'Annuler' });
    // « Annuler » est le choix par défaut.
    await waitFor(() => expect(annuler).toHaveFocus());

    await userEvent.click(annuler);

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await new Promise((r) => setTimeout(r, 100));
    expect(lancements).toEqual([]);
  });

  it('« Confirmer » lance la collecte une seule fois, même cliqué deux fois', async () => {
    const { lancements } = await monter();

    await userEvent.click(screen.getByRole('button', { name: 'zone 69' }));
    await userEvent.click(
      await screen.findByRole('button', { name: 'Récupérer 100 entreprises de ce département' }),
    );
    const dialogue = await screen.findByRole('dialog');
    const confirmer = within(dialogue).getByRole('button', { name: 'Confirmer' });

    await userEvent.dblClick(confirmer);

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(lancements).toEqual([{ department: '69', limit: 100, enrich: false }]);
  });
});
