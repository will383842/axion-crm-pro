/**
 * LOT 13 — rattacher une personne en CHERCHANT l'entreprise (audit UX P1-8).
 *
 * Avant : un champ « Identifiant d'entreprise » (placeholder « ex. 1842 ») qu'on
 * ne pouvait remplir qu'en ouvrant un autre onglet. Après : le sélecteur
 * `ChoixEntreprise`, branché sur `GET /crm/entreprises/choix`.
 *
 * Ce que ces tests mesurent, sur l'écran RÉEL (file d'arbitrage) :
 *   - la liste s'affiche (nom, code postal et ville, SIREN) ;
 *   - le choix transmet l'IDENTIFIANT à la mutation de rattachement ;
 *   - le clavier suffit (↓, Entrée, Échap) ;
 *   - aucune requête sous 2 caractères, ni sur un numéro incomplet ;
 *   - une erreur serveur se dit, en français ;
 *   - les suggestions tirées du nom et du code postal reçus ;
 *   - « Rattacher » sans choix explique quoi faire, sans rien envoyer.
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ArbitragePage } from '@/features/crm-console/ArbitragePage';
import { MESSAGE_MOTS_VIDES, MESSAGE_NUMERO, MESSAGE_TROP_COURT } from '@/features/crm-console/ChoixEntreprise';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, dynamicGet, getJson, http, HttpResponse, recordPost, type HttpHandler } from '../msw/handlers';

const LIGNE = {
  activity_id: 41,
  kind: 'form.contact',
  title: 'Formulaire contact',
  occurred_at: '2026-08-18T09:12:00Z',
  external_ref: null,
  person_key: null,
  pending_match: {
    denomination: 'Boulangerie Martin',
    first_name: 'Marie',
    last_name: 'Dupont',
    postcode: '69003',
    city: 'Lyon',
  },
};

const FILE = { data: [LIGNE], meta: { total: 1, per_page: 50 } };

const MARTIN_LYON = {
  id: 1842,
  denomination: 'Boulangerie Martin',
  siren: '552100554',
  siret: null,
  code_postal: '69003',
  ville: 'Lyon',
};
const MARTIN_PARIS = {
  id: 2077,
  denomination: 'Boulangerie Martin et Fils',
  siren: '552100555',
  siret: null,
  code_postal: '75008',
  ville: 'Paris',
};

function choixHandler() {
  return dynamicGet('/crm/entreprises/choix', (_appel, url) => {
    const q = url.searchParams.get('q') ?? '';
    return {
      data: q.toLowerCase().includes('martin') ? [MARTIN_LYON, MARTIN_PARIS] : [],
      indice: null,
    };
  });
}

async function monter(handlers: HttpHandler[] = [], file: unknown = FILE) {
  await renderScreen(<ArbitragePage />, {
    path: '/console/arbitrage',
    consoleFeatures: 'open',
    handlers: [getJson('/crm/arbitrage', file), ...handlers],
  });
  return (await screen.findAllByRole('combobox', { name: 'Entreprise' }))[0] as HTMLElement;
}

/**
 * Les requêtes TAPÉES, hors suggestions : le simple fait de donner le focus au
 * champ vide demande les suggestions (avec `code_postal`) — c'est voulu, et
 * mesuré à part dans le dernier cas.
 */
function tapees(urls: string[]): URL[] {
  return urls.map((u) => new URL(u)).filter((u) => !u.searchParams.has('code_postal'));
}

/** Laisse passer l'anti-rebond (300 ms) avec de la marge. */
function attendre(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

describe('ChoixEntreprise — rattacher en cherchant l’entreprise', () => {
  it('plus aucun champ d’identifiant : le libellé visible est « Entreprise »', async () => {
    await monter([choixHandler().handler]);
    expect(screen.queryByText(/Identifiant d’entreprise/)).not.toBeInTheDocument();
    expect(screen.queryByPlaceholderText(/1842/)).not.toBeInTheDocument();
    expect(screen.getByText('Entreprise', { selector: 'label' })).toBeVisible();
  });

  it('affiche les résultats (nom, lieu, SIREN) et transmet l’identifiant choisi au rattachement', async () => {
    const choix = choixHandler();
    const post = recordPost<{ company_id: number }>('/crm/arbitrage/41/attach', { contact_created: true });
    const champ = await monter([choix.handler, post.handler]);

    await userEvent.type(champ, 'martin');

    const option = await screen.findByRole('option', { name: /Boulangerie Martin et Fils/ });
    expect(option).toHaveTextContent('75008 Paris');
    expect(option).toHaveTextContent('SIREN 552 100 555');
    // La recherche part UNE fois, après l'anti-rebond — pas à chaque lettre.
    expect(tapees(choix.urls)).toHaveLength(1);
    expect(tapees(choix.urls)[0]?.searchParams.get('q')).toBe('martin');

    await userEvent.click(option);
    expect(screen.getByText(/Choisie : Boulangerie Martin et Fils/)).toBeVisible();

    await userEvent.click(screen.getByRole('button', { name: 'Rattacher' }));
    await waitFor(() => expect(post.bodies).toHaveLength(1));
    expect(post.bodies[0]).toEqual({ company_id: 2077 });
  });

  it('au clavier : ↓ parcourt, Entrée choisit, Échap ferme la liste', async () => {
    const post = recordPost<{ company_id: number }>('/crm/arbitrage/41/attach', { contact_created: true });
    const champ = await monter([choixHandler().handler, post.handler]);

    await userEvent.type(champ, 'martin');
    await screen.findByRole('option', { name: /Boulangerie Martin et Fils/ });
    expect(champ).toHaveAttribute('aria-expanded', 'true');

    await userEvent.keyboard('{ArrowDown}');
    const premiere = screen.getByRole('option', { name: /Boulangerie Martin Lyon|69003 Lyon/ });
    expect(premiere).toHaveAttribute('aria-selected', 'true');
    expect(champ).toHaveAttribute('aria-activedescendant', premiere.id);

    await userEvent.keyboard('{ArrowDown}');
    expect(screen.getByRole('option', { name: /Martin et Fils/ })).toHaveAttribute('aria-selected', 'true');
    await userEvent.keyboard('{ArrowUp}');
    expect(premiere).toHaveAttribute('aria-selected', 'true');

    await userEvent.keyboard('{Enter}');
    expect(champ).toHaveValue('Boulangerie Martin');
    expect(champ).toHaveAttribute('aria-expanded', 'false');

    // Échap : on rouvre la liste en retapant, puis Échap la referme.
    await userEvent.type(champ, ' ');
    await screen.findAllByRole('option');
    expect(champ).toHaveAttribute('aria-expanded', 'true');
    await userEvent.keyboard('{Escape}');
    expect(champ).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByRole('option')).not.toBeInTheDocument();

    // Retaper a ANNULÉ le choix : Rattacher demande de choisir, sans rien envoyer.
    await userEvent.click(screen.getByRole('button', { name: 'Rattacher' }));
    expect(await screen.findByText('Choisissez une entreprise dans la liste.')).toBeVisible();
    expect(post.bodies).toHaveLength(0);
  });

  it('même seuil que le serveur : aucune requête sous 3 lettres, ni sur des mots vides, ni sur un numéro incomplet', async () => {
    const choix = choixHandler();
    const champ = await monter([choix.handler]);

    await userEvent.type(champ, 'ma');
    await attendre(600);
    expect(tapees(choix.urls)).toHaveLength(0);
    expect(screen.getByText(MESSAGE_TROP_COURT)).toBeInTheDocument();

    // Articles et formes juridiques seuls : rien ne part.
    await userEvent.clear(champ);
    await userEvent.type(champ, 'SARL les');
    await attendre(600);
    expect(tapees(choix.urls)).toHaveLength(0);
    expect(screen.getByText(MESSAGE_MOTS_VIDES)).toBeInTheDocument();

    // TÉMOIN : un mot du nom ajouté → la requête part.
    await userEvent.type(champ, ' martin');
    await waitFor(() => expect(tapees(choix.urls)).toHaveLength(1));
    expect(tapees(choix.urls)[0]?.searchParams.get('q')).toBe('SARL les martin');

    await userEvent.clear(champ);
    await userEvent.clear(champ);
    await userEvent.type(champ, '552 100');
    await attendre(600);
    expect(tapees(choix.urls)).toHaveLength(1);
    expect(screen.getByText(MESSAGE_NUMERO)).toBeInTheDocument();

    // TÉMOIN : le même champ, numéro complet → une requête.
    await userEvent.type(champ, ' 554');
    await waitFor(() => expect(tapees(choix.urls)).toHaveLength(2));
    expect(tapees(choix.urls)[1]?.searchParams.get('q')).toBe('552 100 554');
  });

  it('erreur : une recherche trop large et une panne se disent en français, sans liste vide trompeuse', async () => {
    let statut = 503;
    const champ = await monter([
      http.get(apiUrl('/crm/entreprises/choix'), () =>
        statut === 503
          ? HttpResponse.json({ error: 'requete_trop_longue', message: 'trop long' }, { status: 503 })
          : new HttpResponse(null, { status: 500 }),
      ),
    ]);

    await userEvent.type(champ, 'boulangerie');
    expect(
      await screen.findByText('La recherche est trop large : ajoutez un mot du nom, la ville ou le code postal.'),
    ).toBeVisible();
    expect(screen.queryByText(/Aucune entreprise trouvée/)).not.toBeInTheDocument();

    statut = 500;
    await userEvent.type(champ, 'x');
    expect(await screen.findByText('La recherche n’a pas abouti. Réessayez dans un instant.')).toBeVisible();
  });

  it('aucun résultat : le dit, et suggère quoi taper', async () => {
    const champ = await monter([choixHandler().handler]);
    await userEvent.type(champ, 'zzzz');
    expect(
      await screen.findByText('Aucune entreprise trouvée. Essayez le SIREN, ou ajoutez la ville ou le code postal.'),
    ).toBeVisible();
  });

  it('un résultat PÉRIMÉ n’est jamais affiché : l’ancienne frappe qui répond après la nouvelle est ignorée', async () => {
    let liberer!: () => void;
    const porte = new Promise<void>((resolve) => {
      liberer = resolve;
    });
    const champ = await monter([
      http.get(apiUrl('/crm/entreprises/choix'), async ({ request }) => {
        const q = new URL(request.url).searchParams.get('q') ?? '';
        if (q === 'garage') {
          // L'ancienne frappe : sa réponse n'arrive qu'après la nouvelle.
          await porte;
          return HttpResponse.json({ data: [{ ...MARTIN_PARIS, id: 9001, denomination: 'Garage Périmé' }], indice: null });
        }
        return HttpResponse.json({ data: q.includes('central') ? [{ ...MARTIN_LYON, id: 9002, denomination: 'Garage Central' }] : [], indice: null });
      }),
    ]);

    await userEvent.type(champ, 'garage');
    await attendre(450); // la requête « garage » est partie, et reste en vol
    await userEvent.type(champ, ' central');
    expect(await screen.findByRole('option', { name: /Garage Central/ })).toBeVisible();

    liberer();
    await attendre(300);
    expect(screen.queryByText('Garage Périmé')).not.toBeInTheDocument();
    expect(screen.getByRole('option', { name: /Garage Central/ })).toBeVisible();
  });

  it('suggestions : un nom reçu qui commence par « Les » est bien proposé ; des mots vides seuls ne lancent rien', async () => {
    const choix = dynamicGet('/crm/entreprises/choix', () => ({
      data: [{ ...MARTIN_LYON, id: 3003, denomination: 'Les Jardins du Lac', code_postal: '74000', ville: 'Annecy' }],
      indice: null,
    }));
    const ligne = (denomination: string, id: number) => ({
      ...LIGNE,
      activity_id: id,
      pending_match: { ...LIGNE.pending_match, denomination, postcode: '74000' },
    });
    await monter([choix.handler], { data: [ligne('Les Jardins du Lac', 51), ligne('SARL', 52)], meta: { total: 2, per_page: 50 } });

    const [jardins, sarl] = screen.getAllByRole('combobox', { name: 'Entreprise' });
    await userEvent.click(jardins as HTMLElement);
    const liste = await screen.findByRole('listbox', { name: 'Suggestions d’entreprises' });
    expect(await within(liste).findByRole('option', { name: /Les Jardins du Lac/ })).toBeVisible();
    expect(new URL(choix.urls[0] as string).searchParams.get('q')).toBe('Les Jardins du Lac');

    await userEvent.click(sarl as HTMLElement);
    await attendre(400);
    expect(choix.urls).toHaveLength(1);
  });

  it('suggestions : à l’ouverture du champ vide, le nom et le code postal reçus sont proposés', async () => {
    const choix = choixHandler();
    const champ = await monter([choix.handler]);

    // Rien ne part au chargement de la file.
    await attendre(400);
    expect(choix.urls).toHaveLength(0);

    await userEvent.click(champ);
    const liste = await screen.findByRole('listbox', { name: 'Suggestions d’entreprises' });
    expect(within(liste).getByText('Suggestions')).toBeVisible();
    expect(await within(liste).findAllByRole('option')).toHaveLength(2);

    const url = new URL(choix.urls[0] as string);
    expect(url.searchParams.get('q')).toBe('Boulangerie Martin');
    expect(url.searchParams.get('code_postal')).toBe('69003');
  });
});
