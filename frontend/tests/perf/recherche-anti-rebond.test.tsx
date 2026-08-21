/**
 * GARDE — la recherche a la frappe ne part PAS a chaque touche.
 *
 * ── Le defaut (G42-010, S1) ───────────────────────────────────────────────
 * Huit ecrans placaient l'etat d'un champ de saisie DIRECTEMENT dans la
 * `queryKey` de React Query. Une touche = une cle nouvelle = une requete :
 *
 *   src/features/companies/CompaniesListPage.tsx:256   ["companies", page, filter]
 *   src/features/media/MediaListPage.tsx:157           ["media", page, filter]
 *   src/features/contacts/ContactsListPage.tsx:86      ['contacts', …, search]
 *   src/features/media/JournalistsListPage.tsx:51      ["journalists", page, search]
 *   src/features/campaigns/CampaignsListPage.tsx:60    ['campaigns', { search }]
 *   src/features/crm-console/CandidatesPage.tsx:65     ['crm','candidates',tab,search]
 *   src/features/crm-console/ContactsHubPage.tsx:78    ['crm','contacts-hub',…,search]
 *   src/components/ui/GlobalSearch.tsx:32              ['global-search', search]
 *
 * Mesure du 2026-08-20, par ce fichier meme, AVANT reparation : taper
 * « boulangerie » (11 caracteres) dans `/companies` faisait partir
 * **12 requetes** `GET /companies` (1 au montage + 11, une par touche). La
 * table porte 4,29 M de fiches et le filtre est un `LIKE` : onze de ces douze
 * recherches etaient deja perimees a leur arrivee.
 *
 * ── Ce que cette garde tient ──────────────────────────────────────────────
 * Elle COMPTE les requetes reellement parties sur le reseau (MSW intercepte au
 * niveau XHR, cf. `tests/msw/handlers.ts`) pendant une frappe reelle
 * (`user-event`, une touche = un rendu). Ce n'est pas une lecture de source :
 * c'est le comportement.
 *
 * Elle tient DEUX choses a la fois, et les deux comptent :
 *   1. la requete ne part qu'une fois — sinon l'anti-rebond n'existe pas ;
 *   2. la requete FINIT par partir, avec le terme COMPLET — sinon
 *      « zero requete » serait un vert parfait et l'ecran serait casse.
 *   3. le champ reste VIF : sa valeur affiche les 11 caracteres tout de
 *      suite. Un anti-rebond pose sur le `value` du champ rendrait la saisie
 *      saccadee ; ce n'est pas la reparation demandee.
 *
 * ⚠️ CE QU'ELLE NE COUVRE PAS :
 *  · elle ne joue QU'UN ecran (`CompaniesListPage`), le plus lourd. Les sept
 *    autres sont tenus par la garde de registre du meme dossier
 *    (`registre-recherche.test.ts`), qui est une lecture de source, donc plus
 *    faible. Monter huit ecrans reels couterait plusieurs dizaines de
 *    secondes par execution.
 *  · elle ne mesure pas le DELAI. Un anti-rebond de 5 s la passerait au vert
 *    et rendrait l'ecran inutilisable. Le delai est fixe en un seul endroit
 *    (`src/hooks/useAntiRebond.ts`) et documente la.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, recordGet } from '../msw/handlers';

const TERME = 'boulangerie'; // 11 caracteres
const GEO = {
  regions: [{ code: '11', name: 'Ile-de-France' }],
  departments: [{ code: '75', name: 'Paris' }],
};
const PAGE_VIDE = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 },
};

/** Monte l'ecran et rend le journal des URLs recues sur `/companies`. */
async function monterListeEntreprises(): Promise<{ urls: string[] }> {
  const compagnies = recordGet('/companies', PAGE_VIDE);
  await renderScreen(<CompaniesListPage />, {
    path: '/companies',
    handlers: [compagnies.handler, getJson('/referentiels/geo', GEO)],
  });
  // Le montage lance une premiere requete : on attend qu'elle soit arrivee,
  // sinon le comptage d'apres frappe melangerait les deux causes.
  await waitFor(() => expect(compagnies.urls.length).toBeGreaterThanOrEqual(1));
  return compagnies;
}

describe('recherche a la frappe — anti-rebond', () => {
  it('ne lance pas une requete par touche sur /companies', async () => {
    const compagnies = await monterListeEntreprises();
    const auMontage = compagnies.urls.length;

    const champ = await screen.findByPlaceholderText('Rechercher une entreprise…');
    await userEvent.type(champ, TERME);

    // ── 3. le champ reste vif ───────────────────────────────────────────
    // Assure AVANT l'attente : la valeur doit etre complete immediatement,
    // pas apres le delai d'anti-rebond.
    expect(champ).toHaveValue(TERME);

    // ── 2. la requete finit par partir, avec le terme COMPLET ───────────
    // C'est le verrou anti-« vert sans mesure » : sans lui, un ecran qui ne
    // requeterait plus DU TOUT passerait cette garde haut la main.
    await waitFor(
      () => {
        const derniere = compagnies.urls[compagnies.urls.length - 1] ?? '';
        expect(decodeURIComponent(derniere)).toContain(`filter[denomination]=${TERME}`);
      },
      { timeout: 3000 },
    );

    // ── 1. et une seule fois ────────────────────────────────────────────
    const apresFrappe = compagnies.urls.length - auMontage;
    // Mesure du 2026-08-20 avant reparation : 11 (une par touche).
    // Apres : 1. On laisse 2 de marge — React 19 peut re-planifier un rendu,
    // et une garde qui rougit sur un alea de planification ne serait pas jouee.
    expect(apresFrappe).toBeLessThanOrEqual(2);
    expect(apresFrappe).toBeGreaterThanOrEqual(1);
  });

  it('laisse la DERNIERE valeur gagner apres une correction', async () => {
    // Le risque propre a l'anti-rebond : effacer puis retaper. C'est la
    // derniere valeur qui doit atteindre le serveur, pas une intermediaire
    // restee en route.
    //
    // ⚠️ On n'assure PAS que « boulanx » ne parte JAMAIS. Un anti-rebond ne
    // supprime que ce qui tient dans sa fenetre : si la frappe simulee met
    // plus de 300 ms a franchir le `x`, la valeur intermediaire part
    // legitimement — et c'est aussi ce qui arriverait a un operateur lent.
    // Une garde qui l'interdirait mesurerait la vitesse du banc, pas le
    // produit ; mesure du 2026-08-20 : elle rougissait une execution sur deux
    // sous charge parallele.
    const compagnies = await monterListeEntreprises();
    const auMontage = compagnies.urls.length;

    const champ = await screen.findByPlaceholderText('Rechercher une entreprise…');
    await userEvent.type(champ, 'boulanx{Backspace}gerie'); // 13 frappes

    expect(champ).toHaveValue(TERME);
    await waitFor(
      () => {
        const derniere = compagnies.urls[compagnies.urls.length - 1] ?? '';
        expect(decodeURIComponent(derniere)).toContain(`filter[denomination]=${TERME}`);
      },
      { timeout: 3000 },
    );
    // 13 frappes. Sans anti-rebond : 13 requetes (mesure du TEMOIN plus bas).
    // Avec : une poignee au plus, selon la vitesse du banc.
    expect(compagnies.urls.length - auMontage).toBeLessThanOrEqual(4);
  });
});

// ── TEMOIN ────────────────────────────────────────────────────────────────
//
// Preuve que l'appareil de mesure ci-dessus VOIT bien une requete par touche.
// On ne peut pas recasser `CompaniesListPage` le temps d'un cas ; on monte
// donc un ecran MINIMAL cable exactement comme il l'etait — l'etat du champ
// directement dans la `queryKey` — et on lui applique le meme comptage.

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

/** L'ecran fautif, en douze lignes : la `queryKey` porte l'etat du champ. */
function EcranSansAntiRebond(): React.ReactElement {
  const [recherche, setRecherche] = useState('');
  useQuery({
    queryKey: ['companies', recherche],
    // Type explicite : sans lui, `api.get` rend `any` et la regle
    // `no-unsafe-return` fait rougir `pnpm lint`, qui est BLOQUANTE.
    queryFn: async () =>
      (
        await api.get<{ data: unknown[] }>(
          `/companies?filter[denomination]=${encodeURIComponent(recherche)}`,
        )
      ).data,
  });
  return (
    <input
      aria-label="temoin"
      value={recherche}
      onChange={(e) => setRecherche(e.target.value)}
      placeholder="temoin"
    />
  );
}

describe('recherche a la frappe — TEMOIN de la garde', () => {
  it('compte bien UNE requete PAR TOUCHE quand rien ne les retient', async () => {
    const compagnies = recordGet('/companies', PAGE_VIDE);
    await renderScreen(<EcranSansAntiRebond />, {
      path: '/temoin',
      handlers: [compagnies.handler],
    });
    await waitFor(() => expect(compagnies.urls.length).toBeGreaterThanOrEqual(1));
    const auMontage = compagnies.urls.length;

    await userEvent.type(screen.getByLabelText('temoin'), TERME);

    // C'EST le defaut G42-010 en miniature : 11 caracteres, 11 requetes.
    await waitFor(() => expect(compagnies.urls.length - auMontage).toBe(TERME.length));
    // Et la garde principale, appliquee ici, ROUGIRAIT : 11 > 2.
    expect(compagnies.urls.length - auMontage).toBeGreaterThan(2);
  });

  it('ne passe pas au vert quand AUCUNE requete ne part', () => {
    // Le faux vert redoute : un ecran casse ne requete plus, donc « pas une
    // requete par touche ». C'est l'assertion `toContain(terme)` de la garde
    // principale qui l'attrape — on montre ici qu'elle echouerait bien.
    const urls: string[] = [];
    const derniere = urls[urls.length - 1] ?? '';
    expect(() => expect(derniere).toContain(TERME)).toThrow();
  });
});
