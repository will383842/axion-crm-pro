/**
 * GARDE — audit 360, D25-011 (S2) : cinq lectures imbriquées de la réponse
 * d'API, sans garde, sur trois écrans de la console.
 *
 * Mesure du 2026-08-22, avant correctif :
 *   - `ContactsHubPage.tsx:256` `company.contacts.length > 0`
 *   - `ContactsHubPage.tsx:278`, `:288`, `:289` `company.tags.length`
 *   - `CandidatesPage.tsx:210` `candidate.tags.length > 0`
 *   - `PersonTimelinePage.tsx:108` `data.data.length === 0`
 *
 * Pourquoi ce n'est pas de la paranoïa de type. `types.ts` déclare `tags`,
 * `contacts` et `data` OBLIGATOIRES — mais c'est une affirmation de
 * COMPILATION portant sur une réponse HTTP que rien ne valide à l'exécution.
 * Une clef absente (champ retiré côté API, réponse tronquée, 200 partiel) ne
 * produit pas un trou dans l'écran : elle jette, et emporte l'écran entier.
 *
 * CE QUE CETTE GARDE INSPECTE, et rien d'autre : que chacun des trois écrans
 * se rende SANS lever quand la clef imbriquée manque. Elle ne dit rien de la
 * validation de schéma à la frontière (le second geste de la piste D25-011,
 * non fait ici).
 *
 * La frontière d'erreur locale est là pour que l'échec soit LISIBLE : sans
 * elle, l'exception remonte brute dans vitest et le message n'indique ni
 * l'écran ni le geste.
 */
import { Component, type ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children }: { children: ReactNode }) => <span>{children}</span>,
  useParams: () => ({ personKey: 'pk-garde-d25-011' }),
}));

const get = vi.fn<(url: string) => Promise<{ data: unknown }>>();
vi.mock('@/lib/api', () => ({ api: { get: (url: string) => get(url) } }));

const { ContactsHubPage } = await import('@/features/crm-console/ContactsHubPage');
const { CandidatesPage } = await import('@/features/crm-console/CandidatesPage');
const { PersonTimelinePage } = await import('@/features/crm-console/PersonTimelinePage');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

let erreurLevee: Error | null = null;

/** Capte l'exception de rendu pour la nommer, au lieu de la laisser filer brute. */
class Filet extends Component<{ children: ReactNode }, { emporte: boolean }> {
  // `override` : le projet compile avec `noImplicitOverride`. Sans lui,
  // `tsc --noEmit` rend TS4114 et la porte CI du frontend rougit — le membre
  // redefinit bien celui de `Component`, et le compilateur exige qu'on le dise.
  override state: { emporte: boolean } = { emporte: false };

  static getDerivedStateFromError(): { emporte: boolean } {
    return { emporte: true };
  }

  override componentDidCatch(erreur: Error): void {
    erreurLevee = erreur;
  }

  override render(): ReactNode {
    return this.state.emporte ? <span>ECRAN-EMPORTE</span> : this.props.children;
  }
}

const META = { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false };

function monter(ecran: ReactNode, vivier: boolean): void {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  client.setQueryData(CONSOLE_FEATURES_KEY, {
    console_v2: true,
    universes: { business: true, vivier },
  });

  render(
    <QueryClientProvider client={client}>
      <Filet>{ecran}</Filet>
    </QueryClientProvider>,
  );
}

/**
 * Message d'échec commun : il dit ce qui casse ET le geste.
 * ⚠️ Sous vitest le message personnalisé est le SECOND argument d'`expect`, pas
 * du matcher : `expect(x).toBeNull(msg)` l'avalerait sans jamais l'afficher.
 */
function exigerAucuneErreur(ecran: string, clef: string): void {
  const message =
    `D25-011 rouvert sur ${ecran} : la reponse d'API n'a pas la clef \`${clef}\` et `
    + `l'ecran ENTIER a ete emporte (${erreurLevee?.message ?? ''}). Le type la declare `
    + 'obligatoire, mais un type est une promesse de compilation, pas une garantie '
    + `d'execution. Geste : lire \`x.${clef}?.length ?? 0\` sur ce site, ou valider la `
    + 'reponse a la frontiere (schema zod par vue).';

  expect(erreurLevee?.message ?? null, message).toBeNull();
  expect(screen.queryByText('ECRAN-EMPORTE'), message).toBeNull();
}

/**
 * Attend le témoin SANS faire échouer la recherche elle-même.
 *
 * Ordre délibéré : quand l'écran a été emporté, le témoin est forcément
 * introuvable. Si on laissait `findAllByText` lever, le rouge dirait « texte
 * absent » — vrai, mais muet sur la cause. On récupère donc l'échec, on laisse
 * `exigerAucuneErreur()` parler EN PREMIER, et le témoin ne s'exprime que si
 * aucune exception n'a été levée.
 */
async function attendreTemoin(texte: string | RegExp): Promise<HTMLElement[]> {
  try {
    return await screen.findAllByText(texte, undefined, { timeout: 5000 });
  } catch {
    return [];
  }
}

/** Le témoin : sans lui, la garde serait verte sur un écran qui n'a RIEN rendu. */
function exigerTemoin(trouves: HTMLElement[], ecran: string): void {
  expect(
    trouves.length,
    `${ecran} n'a rien rendu du tout : cette garde ne mesure donc plus la lecture `
    + 'imbriquee de D25-011. Verifier la forme de la reponse simulee et le drapeau '
    + 'de console avant de conclure quoi que ce soit.',
  ).toBeGreaterThan(0);
}

beforeEach(() => {
  get.mockReset();
  erreurLevee = null;
});

describe('D25-011 — une clef imbriquee absente ne doit pas emporter l ecran', () => {
  it('ContactsHubPage tient sans `contacts` ni `tags`', async () => {
    const societe = {
      id: 'c1',
      siren: '123456789',
      denomination: 'ENTREPRISE SANS CLEFS',
      relation_type: 'prospect',
      lifecycle_stage: 'nouveau',
      legal_basis: 'interet_legitime',
      city_name: null,
      department_code: null,
      size_category: null,
      email_generic: null,
      updated_at: null,
      // `tags` et `contacts` ABSENTS — c'est tout l'objet du cas.
    };

    get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.includes('/counts')
          ? { total: 1, by_relation_type: { prospect: 1 }, by_lifecycle_stage: { nouveau: 1 } }
          : { data: [societe], meta: META },
      }),
    );

    monter(<ContactsHubPage />, false);

    const temoin = await attendreTemoin('ENTREPRISE SANS CLEFS');
    exigerAucuneErreur('ContactsHubPage', 'contacts');
    exigerTemoin(temoin, 'ContactsHubPage');
  });

  it('CandidatesPage tient sans `tags`', async () => {
    const candidat = {
      id: 'k1',
      first_name: 'Camille',
      last_name: 'SANSTAGS',
      relation_type: 'candidat_tech',
      lifecycle_stage: 'nouveau',
      consent_vivier_at: null,
      derniere_interaction_at: null,
      purge_prevue_le: null,
      cv_ref: null,
      opt_out: false,
      person_key: null,
      // `tags` ABSENT.
    };

    get.mockImplementation((url: string) =>
      Promise.resolve({
        data: url.includes('/counts')
          ? { total: 1, by_relation_type: {}, by_lifecycle_stage: {} }
          : { data: [candidat], meta: META },
      }),
    );

    monter(<CandidatesPage />, true);

    const temoin = await attendreTemoin(/SANSTAGS/);
    exigerAucuneErreur('CandidatesPage', 'tags');
    exigerTemoin(temoin, 'CandidatesPage');
  });

  it('PersonTimelinePage tient sans `data`', async () => {
    get.mockImplementation(() =>
      Promise.resolve({
        data: {
          person_key: 'pk-garde-d25-011',
          universes: {
            business: { accessible: true, exists: true },
            vivier: { accessible: false, exists: false },
          },
          subjects: [
            { id: 's1', universe: 'business', type: 'contact', first_name: 'Dominique', last_name: 'SANSTIMELINE', email: null },
          ],
          // `data` ABSENT — la timeline elle-meme.
        },
      }),
    );

    monter(<PersonTimelinePage />, false);

    // Le nom paraît DEUX fois (titre de page + encart identité) : d'où
    // `findAllByText` dans `attendreTemoin`, et non `findByText` qui lèverait
    // « found multiple elements » — un rouge qui ne parlerait pas de D25-011.
    const temoin = await attendreTemoin('Dominique SANSTIMELINE');
    exigerAucuneErreur('PersonTimelinePage', 'data');
    exigerTemoin(temoin, 'PersonTimelinePage');
  });
});
