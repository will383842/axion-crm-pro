/**
 * L'ASSISTANT DE CAMPAGNE, DU CÔTÉ DE CELUI QUI TAPE — D26-005, D26-008, D26-010.
 *
 * Trois défauts mesurés le 2026-08-22, tous dans `CampaignWizardPage` :
 *
 *  - D26-005 : les champs numériques bornaient la valeur à CHAQUE frappe.
 *    Sur « Durée max (minutes) » (`min={5}`), taper « 12 » donnait « 1 » →
 *    borné à 5 → le champ affichait 5, et la frappe suivante produisait « 52 ».
 *  - D26-008 : une limite saisie pour une source ensuite désélectionnée
 *    n'apparaissait plus NULLE PART (le panneau ne montre que les sources
 *    retenues) et partait quand même au serveur, où elle était persistée.
 *  - D26-010 : sept champs tronquent un collage sans compteur ni message.
 *
 * Ces gardes pilotent l'assistant comme un utilisateur : elles traversent les
 * quatre étapes et lisent ce qui part réellement dans `api.post`. Tester
 * `buildPayload()` isolément aurait supposé de l'extraire ; on aurait alors
 * prouvé qu'une fonction filtre, pas que l'assistant envoie une charge propre.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

const posts: Array<{ url: string; payload: Record<string, unknown> }> = [];

vi.mock('@tanstack/react-router', () => ({
  useNavigate: () => () => {},
  Link: ({ children, to }: { children?: ReactNode; to?: string }) => <a href={to}>{children}</a>,
}));

vi.mock('@/lib/api', () => ({
  api: {
    // L'endpoint `/coverage` n'est qu'un décor ici : il ajoute des compteurs aux
    // zones. Une réponse vide suffit, les zones référentielles sont en dur.
    get: () => Promise.resolve({ data: { cells: [] } }),
    post: (url: string, payload: Record<string, unknown>) => {
      posts.push({ url, payload });
      return Promise.resolve({ data: { id: 7 } });
    },
  },
}));

vi.mock('sonner', () => ({ toast: { success: () => {}, error: () => {} } }));

const { CampaignWizardPage } = await import('@/features/campaigns/CampaignWizardPage');

function afficher() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  return render(
    <QueryClientProvider client={client}>
      <CampaignWizardPage />
    </QueryClientProvider>,
  );
}

/** Étape 1 → 2 : un nom suffit. */
async function passerLIdentite(utilisateur: ReturnType<typeof userEvent.setup>, nom = 'Campagne de garde') {
  await utilisateur.type(screen.getByPlaceholderText(/Prospection PME Paris IT/i), nom);
  await utilisateur.click(screen.getByRole('button', { name: 'Continuer' }));
}

/** Étape 2 → 3 : une zone suffit. */
async function choisirUneZone(utilisateur: ReturnType<typeof userEvent.setup>) {
  await utilisateur.type(screen.getByPlaceholderText(/Rechercher un dépt/i), 'Paris');
  await utilisateur.click(screen.getByRole('button', { name: /75\s+Paris/ }));
  await utilisateur.click(screen.getByRole('button', { name: 'Continuer' }));
}

beforeEach(() => {
  posts.length = 0;
});

describe('D26-005 — les champs numériques ne corrigent plus la frappe', () => {
  it('taper « 12 » dans « Durée max » laisse « 12 », et non « 52 »', async () => {
    const utilisateur = userEvent.setup();
    afficher();
    await passerLIdentite(utilisateur);
    await choisirUneZone(utilisateur);
    await utilisateur.click(screen.getByRole('button', { name: 'Continuer' })); // étape 3 → 4

    const duree = screen.getByRole('spinbutton', { name: /Durée max/i });
    await utilisateur.clear(duree);
    await utilisateur.type(duree, '12');

    expect(
      (duree as HTMLInputElement).value,
      'D26-005 : le champ « Durée max » borne de nouveau la valeur à CHAQUE ' +
        'frappe. Avec `min={5}`, le « 1 » de « 12 » est remonté à 5 et la frappe ' +
        'suivante produit « 52 » : le champ corrige l’utilisateur pendant qu’il ' +
        'écrit. GESTE : garder la saisie en chaîne dans `NumberField` ' +
        '(`src/features/campaigns/CampaignWizardPage.tsx`) et ne borner que sur ' +
        '`onBlur`.',
    ).toBe('12');
  });

  it('borne à la sortie du champ, et n’envoie jamais de valeur hors bornes', async () => {
    const utilisateur = userEvent.setup();
    afficher();
    await passerLIdentite(utilisateur);
    await choisirUneZone(utilisateur);
    await utilisateur.click(screen.getByRole('button', { name: 'Continuer' }));

    const duree = screen.getByRole('spinbutton', { name: /Durée max/i });
    await utilisateur.clear(duree);
    await utilisateur.type(duree, '2');
    await utilisateur.tab();

    expect(
      (duree as HTMLInputElement).value,
      'D26-005 : « 2 » n’a pas été borné à la sortie du champ. Déplacer le ' +
        'bornage vers `onBlur` sans le FAIRE à `onBlur` remplace une gêne ' +
        'd’ergonomie par un envoi invalide. GESTE : vérifier le `onBlur` de ' +
        '`NumberField`.',
    ).toBe('5');

    await utilisateur.click(screen.getByRole('button', { name: 'Créer en brouillon' }));
    expect(posts[0]?.payload['max_duration_minutes']).toBe(5);
  });
});

describe('D26-008 — les limites des sources abandonnées ne partent plus', () => {
  it('une limite saisie puis la source désélectionnée : rien n’est envoyé pour elle', async () => {
    const utilisateur = userEvent.setup();
    afficher();
    await passerLIdentite(utilisateur);
    await choisirUneZone(utilisateur);

    // Étape 3 : on ajoute France Travail à côté d'INSEE (retenue par défaut).
    await utilisateur.click(screen.getByRole('button', { name: /France Travail/ }));
    await utilisateur.click(screen.getByRole('button', { name: 'Continuer' }));

    // Étape 4 : une limite pour chacune des deux sources.
    await utilisateur.click(screen.getByRole('button', { name: /Limites par source/i }));
    await utilisateur.type(screen.getByRole('spinbutton', { name: /RPM — INSEE Sirene/ }), '11');
    await utilisateur.type(screen.getByRole('spinbutton', { name: /RPM — France Travail/ }), '42');

    // Retour à l'étape 3 : on retire France Travail. Sa limite n'a dès lors plus
    // aucun affichage — c'est exactement la situation du constat.
    await utilisateur.click(screen.getByRole('button', { name: 'Précédent' }));
    await utilisateur.click(screen.getByRole('button', { name: /France Travail/ }));
    await utilisateur.click(screen.getByRole('button', { name: 'Continuer' }));

    await utilisateur.click(screen.getByRole('button', { name: 'Créer en brouillon' }));

    const limites = posts[0]?.payload['per_source_limits'] as Record<string, unknown> | undefined;
    expect(
      limites !== undefined && Object.prototype.hasOwnProperty.call(limites, 'france_travail'),
      'D26-008 : la limite d’une source DÉSÉLECTIONNÉE part encore au serveur, ' +
        'où elle est persistée — alors que le panneau ne l’affiche plus, la source ' +
        'n’étant plus retenue. Une valeur transmise que personne ne peut voir est ' +
        'le pire des deux mondes. GESTE : rétablir le filtre sur `sources` dans ' +
        '`buildPayload()` (`src/features/campaigns/CampaignWizardPage.tsx`).',
    ).toBe(false);

    // La limite de la source RETENUE, elle, doit bien partir : filtrer ne doit
    // pas devenir « ne rien envoyer ».
    expect(
      (limites as Record<string, { rpm?: number }> | undefined)?.['insee']?.rpm,
      'D26-008 : le filtre a emporté aussi la limite d’une source RETENUE. ' +
        'GESTE : vérifier que le filtre teste bien `sources.includes(...)`.',
    ).toBe(11);
  });
});

describe('D26-010 — la troncature se voit', () => {
  it('un compteur apparaît quand le nom approche sa borne de 120 caractères', async () => {
    const utilisateur = userEvent.setup();
    afficher();

    const champNom = screen.getByPlaceholderText(/Prospection PME Paris IT/i);
    // Sous le seuil : pas de compteur, sinon c'est du bruit permanent.
    await utilisateur.type(champNom, 'court');
    expect(document.querySelector('[data-compteur-de-saisie]')).toBeNull();

    await utilisateur.clear(champNom);
    await utilisateur.paste('x'.repeat(200));

    const compteur = document.querySelector('[data-compteur-de-saisie]');
    expect(
      compteur !== null,
      'D26-010 : aucun compteur devant `maxLength={120}`. L’attribut HTML ' +
        'tronque un collage SANS RIEN DIRE : 200 caractères collés en laissent ' +
        '120, et rien à l’écran ne l’annonce. GESTE : rétablir le ' +
        '`<CompteurDeSaisie>` du champ « Nom de la campagne » dans ' +
        '`src/features/campaigns/CampaignWizardPage.tsx`.',
    ).toBe(true);
    // On ne fige pas le nombre de gauche : selon que la couche de saisie
    // applique `maxLength` au collage ou non, il vaut 120 ou 200. Ce qui compte
    // et qui manquait, c'est que la borne et le dépassement soient DITS.
    expect(compteur?.textContent).toContain('/ 120');
    expect(compteur?.textContent).toContain('limite atteinte');
  });
});
