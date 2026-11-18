/**
 * B8-E — l'assistant « Nouvelle campagne » : cible pré-remplie par `?audience=`, et joignables VRAIS
 * (jamais un total présenté comme joignable, jamais « 0 » pour un nombre inconnu). Faux serveur local.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { NouvelleCampagnePage } from '@/features/envoi/campagnes/NouvelleCampagnePage';
import { handlers } from '@/demo/handlers/envoi/creation.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

const CHEMIN = '/envoi/campagnes/nouvelle';

interface CibleServeur {
  type: 'etiquette' | 'audience' | 'liste' | 'carnet';
  reference: string;
  libelle: string;
  total: number;
  joignables: number | null;
}

const AUDIENCE_12: CibleServeur = { type: 'audience', reference: '12', libelle: 'Audience test douze', total: 90, joignables: 80 };
const AUTRE: CibleServeur = { type: 'audience', reference: '3', libelle: 'Autre audience', total: 40, joignables: 35 };

/** Faux serveur : rend les cibles voulues (en notant le paramètre `audience`) et compte les estimations. */
function serveur(cibles: readonly CibleServeur[], estimation: Record<string, unknown> = { total: 90, exclus: 10, restant: 80, exclusions: { opposition: 4, clients: 3, adresses_partagees: 3 } }) {
  const trace = { audiences: [] as Array<string | null>, estimations: 0 };
  const surcharges = [
    http.get('*/envoi/creation/cibles', ({ request }) => {
      trace.audiences.push(new URL(request.url).searchParams.get('audience'));
      return HttpResponse.json({ data: cibles });
    }),
    http.post('*/envoi/brouillons/*/cible/estimation', () => {
      trace.estimations += 1;
      return HttpResponse.json(estimation);
    }),
  ];
  return { trace, surcharges };
}

async function ouvrir(url: string, surcharges: ReturnType<typeof serveur>['surcharges']) {
  await renderScreen(<NouvelleCampagnePage />, { path: CHEMIN, url, handlers: [...surcharges, ...handlers], landingRoutes: ['/envoi/campagnes/$campagneId'] });
  await screen.findByRole('heading', { level: 1, name: 'Nouvelle campagne' });
}

describe('B8-E — assistant : audience dans l’adresse et joignables vrais', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('?audience=12 : la cible est cochée, le serveur reçoit l’audience, une seule estimation', async () => {
    const { trace, surcharges } = serveur([AUTRE, AUDIENCE_12]);
    await ouvrir(`${CHEMIN}?audience=12`, surcharges);
    expect(await screen.findByRole('radio', { name: /Audience test douze/ })).toBeChecked();
    expect(screen.getByRole('radio', { name: /Autre audience/ })).not.toBeChecked();
    expect(screen.getByLabelText('Nom de la campagne')).toHaveValue('Campagne Audience test douze');
    await waitFor(() => { expect(trace.estimations).toBe(1); });
    // Laisse passer d'éventuelles requêtes en double avant de conclure.
    await new Promise((r) => setTimeout(r, 150));
    expect(trace.estimations).toBe(1);
    expect(trace.audiences).toContain('12');
  });

  it('audience inconnue : aucune cible cochée, aucun message d’erreur', async () => {
    const { trace, surcharges } = serveur([AUTRE]);
    await ouvrir(`${CHEMIN}?audience=999`, surcharges);
    expect(await screen.findByRole('radio', { name: /Autre audience/ })).not.toBeChecked();
    expect(screen.queryByRole('radio', { checked: true })).not.toBeInTheDocument();
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(trace.estimations).toBe(0);
  });

  it('une audience mal formée dans l’adresse n’est pas transmise au serveur', async () => {
    const { trace, surcharges } = serveur([AUTRE]);
    await ouvrir(`${CHEMIN}?audience=${encodeURIComponent('1 OR 1=1')}`, surcharges);
    await screen.findByRole('radio', { name: /Autre audience/ });
    expect(trace.audiences).toEqual([null]);
  });

  it('joignables: null : la liste n’affiche jamais « 0 » ni le total comme joignable', async () => {
    const { surcharges } = serveur([{ ...AUDIENCE_12, total: 57, joignables: null }]);
    await ouvrir(`${CHEMIN}`, surcharges);
    const radio = await screen.findByRole('radio', { name: /Audience test douze/ });
    const carte = radio.closest('label');
    expect(carte).not.toBeNull();
    expect(carte).toHaveTextContent(/57\s+membres/);
    expect(carte).not.toHaveTextContent(/joignables/i);
    expect(carte?.textContent ?? '').not.toMatch(/(^|\D)0(\D|$)/);
  });

  it('estimation complète : « Joignables » est le reste après exclusions, sans « au moins »', async () => {
    const { surcharges } = serveur([AUDIENCE_12]);
    await ouvrir(`${CHEMIN}?audience=12`, surcharges);
    const zone = await screen.findByTestId('joignables-estimes');
    expect(zone).toHaveTextContent(/Joignables/);
    expect(zone).toHaveTextContent(/80/);
    expect(zone).not.toHaveTextContent(/au moins/);
  });

  it('complet: false : « au moins N sur M examinés », jamais un chiffre ferme', async () => {
    const { surcharges } = serveur([AUDIENCE_12], {
      total: 5000, exclus: 800, restant: 4200, complet: false, exclusions_evaluees: true, exclusions: { opposition: 500, clients: 200, adresses_partagees: 100 },
    });
    await ouvrir(`${CHEMIN}?audience=12`, surcharges);
    const zone = await screen.findByTestId('joignables-estimes');
    expect(zone).toHaveTextContent(/au moins\s+4\D?200\s+sur\s+5\D?000\s+examinés/);
  });

  it('exclusions non évaluées : le total n’est jamais présenté comme joignable', async () => {
    const { surcharges } = serveur([AUDIENCE_12], { total: 90, exclus: 0, restant: 90, exclusions_evaluees: false, exclusions: {} });
    await ouvrir(`${CHEMIN}?audience=12`, surcharges);
    const zone = await screen.findByTestId('joignables-estimes');
    expect(zone).toHaveTextContent(/non évalués/i);
    expect(zone).not.toHaveTextContent(/90/);
  });
});
