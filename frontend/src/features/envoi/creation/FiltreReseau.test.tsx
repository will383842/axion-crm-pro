/** L2b-E — filtre « réseau d'origine » dans l'assistant (étape 1) et le récapitulatif. */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { NouvelleCampagnePage } from '@/features/envoi/campagnes/NouvelleCampagnePage';
import { handlers as handlersCreation } from '@/demo/handlers/envoi/creation.handlers';
import { renderScreen } from '../../../../tests/helpers/renderScreen';
import { useState } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import { server } from '../../../../tests/msw/server';
import { FiltreReseau } from './FiltreReseau';
import { SAISIE_INITIALE, contenuEtape, saisieDepuis, type Saisie } from './saisie';
import type { Brouillon } from './api';

const CHEMIN = '/envoi/campagnes/nouvelle';
const lignes = (...noms: string[]) => noms.map((reseau, i) => ({ reseau, canal: null, compte: null, demandes: 20 - i, personnes: 20 - i }));
const origines = (data: ReturnType<typeof lignes>) => http.get('*/crm/origines/par-reseau', () => HttpResponse.json({ actif: true, data }));

function brouillon(origine?: { reseaux?: string[]; mots?: string[]; guides?: string[] }) {
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

/* PO-D — mots et guides (le branchement dans EtapeCible est hors liste : ESCALADE sur la PR). */
const motsApi = (mots: string[]) => http.get('*/crm/origines/par-mot', () => HttpResponse.json({ actif: true, data: mots.map((mot, i) => ({ mot, famille: 'guide_reseau', detail: null, demandes: 30 - i, personnes: 30 - i })) }));
const guidesApi = (guides: string[]) => http.get('*/crm/origines/par-guide', () => HttpResponse.json({ actif: true, data: guides.map((guide, i) => ({ guide, demandes: 30 - i, personnes: 30 - i })) }));

function Banc({ initial, sortie }: { initial?: Partial<Saisie> | undefined; sortie: { s: Saisie } }) {
  const [s, setS] = useState<Saisie>({ ...SAISIE_INITIALE, ...initial });
  sortie.s = s;
  return (
    <FiltreReseau
      reseaux={s.reseaux}
      modifier={(reseaux) => { setS((x) => ({ ...x, reseaux, reseauxModifies: true })); }}
      mots={s.mots}
      guides={s.guides}
      modifierContenu={(patch) => { setS((x) => ({ ...x, ...patch, reseauxModifies: true })); }}
    />
  );
}

function monter(handlers: ReturnType<typeof http.get>[], sortie: { s: Saisie }, initial?: Partial<Saisie>) {
  server.use(origines(lignes('linkedin')), ...handlers);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}><Banc initial={initial} sortie={sortie} /></QueryClientProvider>);
}

describe('FiltreReseau — mots et guides (PO-D)', () => {
  it('7. par-mot en 404 : aucune liste de mots ; le bloc des réseaux reste', async () => {
    const sortie = { s: SAISIE_INITIALE };
    monter([http.get('*/crm/origines/par-mot', () => HttpResponse.json({ message: 'Introuvable' }, { status: 404 })), guidesApi(['g1'])], sortie);
    await screen.findByText('Restreindre par réseau d’origine');
    await screen.findByRole('checkbox', { name: 'g1' });
    expect(screen.queryByText(/Par mot/)).toBeNull();
  });

  it('8. « actif: false » sur les deux : seules les cases réseaux restent', async () => {
    const sortie = { s: SAISIE_INITIALE };
    monter([http.get('*/crm/origines/par-mot', () => HttpResponse.json({ actif: false })), http.get('*/crm/origines/par-guide', () => HttpResponse.json({ actif: false }))], sortie);
    await screen.findByRole('checkbox', { name: 'LinkedIn' });
    await attendre(100);
    expect(screen.queryByText(/Par mot/)).toBeNull();
    expect(screen.queryByText(/Par guide/)).toBeNull();
  });

  it('9. deux mots cochés dans le désordre : corps exact, trié', async () => {
    const sortie = { s: SAISIE_INITIALE };
    monter([motsApi(['b', 'a', 'c']), guidesApi([])], sortie);
    const user = userEvent.setup();
    await user.click(await screen.findByRole('checkbox', { name: 'b' }));
    await user.click(screen.getByRole('checkbox', { name: 'a' }));
    const cible = contenuEtape(1, { ...sortie.s, cible: { type: 'etiquette', reference: 'x', libelle: 'x' } }).cible;
    expect(cible?.origine).toEqual({ mots: ['a', 'b'] });
    expect(JSON.stringify(cible?.origine)).toBe('{"mots":["a","b"]}');
  });

  it('10. onze mots proposés : la onzième case est désactivée une fois dix cochées', async () => {
    const noms = Array.from({ length: 11 }, (_x, i) => `mot${String(i).padStart(2, '0')}`);
    const sortie = { s: SAISIE_INITIALE };
    monter([motsApi(noms), guidesApi([])], sortie);
    const user = userEvent.setup();
    const cases = await screen.findAllByRole('checkbox', { name: /^mot\d\d$/ });
    expect(cases).toHaveLength(11);
    for (const c of cases.slice(0, 10)) await user.click(c);
    expect(cases[10]).toBeDisabled();
    await user.click(cases[0] as HTMLElement);
    expect(cases[10]).toBeEnabled();
  });

  it('11. réseau + mot + guide : les trois clés partent ensemble, triées', async () => {
    const sortie = { s: SAISIE_INITIALE };
    monter([motsApi(['m1']), guidesApi(['g2', 'g1'])], sortie);
    const user = userEvent.setup();
    await user.click(await screen.findByRole('checkbox', { name: 'LinkedIn' }));
    await user.click(screen.getByRole('checkbox', { name: 'm1' }));
    await user.click(screen.getByRole('checkbox', { name: 'g2' }));
    await user.click(screen.getByRole('checkbox', { name: 'g1' }));
    expect(contenuEtape(1, { ...sortie.s, cible: { type: 'etiquette', reference: 'x', libelle: 'x' } }).cible?.origine).toEqual({ reseaux: ['linkedin'], mots: ['m1'], guides: ['g1', 'g2'] });
  });

  it('12. tout décoché après modification : origine: null ; non touché : clé absente', () => {
    const cible = { type: 'etiquette' as const, reference: 'x', libelle: 'x' };
    expect(contenuEtape(1, { ...SAISIE_INITIALE, cible, reseauxModifies: true }).cible).toHaveProperty('origine', null);
    expect(contenuEtape(1, { ...SAISIE_INITIALE, cible }).cible).not.toHaveProperty('origine');
  });

  it('13. un brouillon avec mots et guides se relit ; toucher un réseau garde mots et guides', () => {
    const b = { id: 'b', nom: 'n', etape_courante: 1, etapes_faites: [], contenu: { cible: { type: 'etiquette', reference: 'x', libelle: 'x', origine: { mots: ['b', 'a'], guides: ['g'] } } }, mis_a_jour_le: '', campagne_id: null, annulable_jusqua: null } as unknown as Brouillon;
    const s = saisieDepuis(b);
    expect(s.mots).toEqual(['a', 'b']);
    expect(contenuEtape(1, { ...s, reseaux: ['linkedin'], reseauxModifies: true }).cible?.origine).toEqual({ reseaux: ['linkedin'], mots: ['a', 'b'], guides: ['g'] });
  });

  it('14. récapitulatif exact : réseau, mot et guide', async () => {
    await ouvrir([origines(lignes('linkedin')), motsApi(['mot-a']), guidesApi(['guide-a']), brouillon({ reseaux: ['linkedin'], mots: ['mot-a'], guides: ['guide-a'] })]);
    expect(await screen.findByText('Uniquement les personnes venues de : LinkedIn, par le mot mot-a, pour le guide guide-a')).toBeVisible();
  });

  it('15. mots seuls : récapitulatif sans réseau', async () => {
    await ouvrir([origines(lignes('linkedin')), motsApi(['mot-a']), guidesApi([]), brouillon({ mots: ['mot-a'] })]);
    expect(await screen.findByText('Uniquement les personnes venues par le mot mot-a')).toBeVisible();
  });
});
