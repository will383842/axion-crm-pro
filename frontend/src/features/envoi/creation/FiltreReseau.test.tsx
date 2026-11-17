/** L2b-E — filtre « réseau d'origine » dans l'assistant (étape 1) et le récapitulatif. */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { NouvelleCampagnePage } from '@/features/envoi/campagnes/NouvelleCampagnePage';
import { handlers as handlersCreation } from '@/demo/handlers/envoi/creation.handlers';
import { renderScreen } from '../../../../tests/helpers/renderScreen';

const CHEMIN = '/envoi/campagnes/nouvelle';
const lignes = (...noms: string[]) => noms.map((reseau, i) => ({ reseau, canal: null, compte: null, demandes: 20 - i, personnes: 20 - i }));
const origines = (data: ReturnType<typeof lignes>) => http.get('*/crm/origines/par-reseau', () => HttpResponse.json({ actif: true, data }));

function brouillon(origine?: { reseaux: string[] }) {
  return http.get('*/envoi/brouillons/bro-1', () =>
    HttpResponse.json({
      id: 'bro-1', nom: 'Campagne test', etape_courante: 1, etapes_faites: [],
      contenu: { cible: { type: 'etiquette', reference: 'salon-artisans-2026', libelle: 'salon-artisans-2026', ...(origine ? { origine } : {}) } },
      mis_a_jour_le: '2027-01-12T09:00:00.000Z', campagne_id: null, annulable_jusqua: null,
    }),
  );
}

/** Capte les corps envoyés à l'étape 1 ; répond 200 ou le refus demandé. */
function etape1(corps: unknown[], refus?: { statut: number; message: string }) {
  return http.put('*/envoi/brouillons/*/etapes/1', async ({ request }) => {
    corps.push(await request.json());
    if (refus) return HttpResponse.json({ error: 'filtre_origine_inactif', message: refus.message }, { status: refus.statut });
    return HttpResponse.json({ id: 'bro-1', nom: 'Campagne test', etape_courante: 1, etapes_faites: [], contenu: {}, mis_a_jour_le: '2027-01-12T09:00:00.000Z', campagne_id: null, annulable_jusqua: null });
  });
}

const ouvrir = (extra: ReturnType<typeof http.get>[], url = `${CHEMIN}?brouillon=bro-1`) =>
  renderScreen(<NouvelleCampagnePage />, { path: CHEMIN, url, handlers: [...extra, ...handlersCreation], landingRoutes: ['/envoi/campagnes/$campagneId'] });

const attendre = (ms: number) => new Promise((r) => setTimeout(r, ms));

describe('FiltreReseau (étape 1)', () => {
  it('1. API en 404 : le bloc n’est pas affiché', async () => {
    await ouvrir([http.get('*/crm/origines/par-reseau', () => HttpResponse.json({ message: 'Introuvable' }, { status: 404 })), brouillon()]);
    await screen.findByRole('radio', { name: /salon-artisans-2026/ });
    await attendre(100);
    expect(screen.queryByText(/Restreindre par réseau d’origine/)).toBeNull();
  });

  it('2. « actif: false » + brouillon filtré : bandeau, et « Retirer le filtre » envoie origine: null', async () => {
    const corps: unknown[] = [];
    await ouvrir([http.get('*/crm/origines/par-reseau', () => HttpResponse.json({ actif: false })), brouillon({ reseaux: ['linkedin'] }), etape1(corps)]);
    expect(await screen.findByText(/Filtre par réseau inactif : cette campagne ne partira pas tant qu’il est posé/)).toBeVisible();
    expect(screen.queryByText(/Restreindre par réseau d’origine/)).toBeNull();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: 'Retirer le filtre' }));
    await user.click(screen.getByRole('button', { name: /Continuer : message/ }));
    await waitFor(() => { expect(corps).toHaveLength(1); });
    expect((corps[0] as { cible: Record<string, unknown> }).cible).toHaveProperty('origine', null);
    await waitFor(() => { expect(screen.queryByText(/Filtre par réseau inactif/)).toBeNull(); });
  });

  it('actif: false sans filtre posé : ni bloc ni bandeau', async () => {
    await ouvrir([http.get('*/crm/origines/par-reseau', () => HttpResponse.json({ actif: false })), brouillon()]);
    await screen.findByRole('radio', { name: /salon-artisans-2026/ });
    await attendre(100);
    expect(screen.queryByText(/Filtre par réseau inactif/)).toBeNull();
    expect(screen.queryByText(/Restreindre par réseau d’origine/)).toBeNull();
  });

  it('un brouillon non touché ne renvoie pas la clé origine (la colonne reste)', async () => {
    const corps: unknown[] = [];
    await ouvrir([origines(lignes('linkedin')), brouillon({ reseaux: ['linkedin'] }), etape1(corps)]);
    const user = userEvent.setup();
    await screen.findByRole('checkbox', { name: 'LinkedIn' });
    await user.click(screen.getByRole('button', { name: /Continuer : message/ }));
    await waitFor(() => { expect(corps).toHaveLength(1); });
    expect((corps[0] as { cible: Record<string, unknown> }).cible).not.toHaveProperty('origine');
  });

  it('3. deux réseaux cochés : corps exact, trié', async () => {
    const corps: unknown[] = [];
    await ouvrir([origines(lignes('linkedin', 'facebook', 'instagram')), brouillon(), etape1(corps)]);
    const user = userEvent.setup();
    await user.click(await screen.findByText('Restreindre par réseau d’origine'));
    await user.click(await screen.findByRole('checkbox', { name: 'LinkedIn' }));
    await user.click(screen.getByRole('checkbox', { name: 'Facebook' }));
    await user.click(screen.getByRole('button', { name: /Continuer : message/ }));
    await waitFor(() => { expect(corps).toHaveLength(1); });
    expect((corps[0] as { cible: { origine: unknown } }).cible.origine).toEqual({ reseaux: ['facebook', 'linkedin'] });
  });

  it('4. onze réseaux proposés : la onzième case est désactivée une fois dix cochées', async () => {
    const noms = Array.from({ length: 11 }, (_x, i) => `reseau${String(i).padStart(2, '0')}`);
    await ouvrir([origines(lignes(...noms)), brouillon()]);
    const user = userEvent.setup();
    await user.click(await screen.findByText('Restreindre par réseau d’origine'));
    const cases = await screen.findAllByRole('checkbox', { name: /^Reseau\d\d$/ });
    expect(cases).toHaveLength(11);
    for (const c of cases.slice(0, 10)) await user.click(c);
    expect(cases[10]).toBeDisabled();
    expect(cases[0]).toBeEnabled();
    await user.click(cases[0] as HTMLElement);
    expect(cases[10]).toBeEnabled();
  });

  it('5. 422 filtre_origine_inactif : message affiché tel quel, aucun nouvel appel', async () => {
    const corps: unknown[] = [];
    const motif = 'Le filtre par réseau d’origine est inactif dans cet espace : retirez-le ou attendez son ouverture.';
    await ouvrir([origines(lignes('linkedin')), brouillon(), etape1(corps, { statut: 422, message: motif })]);
    const user = userEvent.setup();
    await user.click(await screen.findByText('Restreindre par réseau d’origine'));
    await user.click(await screen.findByRole('checkbox', { name: 'LinkedIn' }));
    await user.click(screen.getByRole('button', { name: /Continuer : message/ }));
    expect(await screen.findByText(motif)).toBeVisible();
    await attendre(1500); // bien au-delà de l'enregistrement différé (600 ms)
    expect(corps).toHaveLength(1);
  });

  it('6. récapitulatif : libellés attendus', async () => {
    await ouvrir([origines(lignes('linkedin', 'facebook')), brouillon({ reseaux: ['linkedin', 'facebook'] })]);
    expect(await screen.findByText('Uniquement les personnes venues de : Facebook, LinkedIn')).toBeVisible();
    expect(await screen.findByText(/L’estimation affichée ne tient pas compte du filtre/)).toBeVisible();
  });

  it('6bis. sans filtre : pas de ligne de réseau dans le récapitulatif', async () => {
    await ouvrir([origines(lignes('linkedin')), brouillon()]);
    await screen.findByRole('radio', { name: /salon-artisans-2026/ });
    expect(screen.queryByText(/Uniquement les personnes venues de/)).toBeNull();
  });
});
