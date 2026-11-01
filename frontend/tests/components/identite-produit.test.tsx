/**
 * DM0-COQ — L'IDENTITÉ PAR PRODUIT (ADR-13).
 *
 * Ce que la garde tient :
 *  1. la coquille porte `data-produit` = `crm`, `envoi` ou `commun` selon la
 *     route (écrans partagés : connexion, compte, utilisateurs, journal,
 *     demandes RGPD, introuvable) ;
 *  2. le bouton principal prend la couleur du produit (`bg-produit`) ;
 *  3. RÈGLE D'OR : la couleur d'identité ne sert JAMAIS à dire un état. Aucune
 *     `StatusPill` n'utilise une classe `*-produit` ;
 *  4. les jetons existent pour les trois identités, avec les teintes validées ;
 *  5. l'onglet du navigateur dit « Envoi · Campagnes » dans l'espace Envoi.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { Button } from '@/components/ui';
import { produitDeChemin } from '@/features/socle/produit/useProduitCourant';
import { renderScreen } from '../helpers/renderScreen';
import { FEATURES_LES_DEUX_PRODUITS, getJson, handlersEnvoi } from '../msw/handlers';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.resolve(ICI, '../../src');

function racineDeLaCoquille(): HTMLElement {
  const racine = document.querySelector<HTMLElement>('[data-coquille]');
  expect(racine, 'La coquille doit porter `data-coquille` (RootLayout).').not.toBeNull();
  return racine as HTMLElement;
}

beforeEach(() => {
  window.sessionStorage.clear();
});

describe('Produit courant — d’après la route', () => {
  it.each([
    ['/', 'crm'],
    ['/companies', 'crm'],
    ['/companies/42', 'crm'],
    ['/collectes/new', 'crm'],
    ['/taches', 'crm'],
    ['/affaires', 'crm'],
    ['/settings', 'crm'],
    ['/envoi', 'envoi'],
    ['/envoi/campagnes', 'envoi'],
    ['/envoi/reglages/connexion-crm', 'envoi'],
    ['/login', 'commun'],
    ['/2fa', 'commun'],
    ['/magic-link', 'commun'],
    ['/password-reset', 'commun'],
    ['/compte', 'commun'],
    ['/users', 'commun'],
    ['/audit-logs', 'commun'],
    ['/rgpd/requests', 'commun'],
  ])('%s → %s', (chemin, attendu) => {
    expect(produitDeChemin(chemin)).toBe(attendu);
  });

  it('« /envoyer » ou « /envois » ne sont pas l’espace Envoi (préfixe strict)', () => {
    expect(produitDeChemin('/envois')).toBe('crm');
    expect(produitDeChemin('/presse/envois')).toBe('crm');
  });
});

describe('Produit courant — posé sur la coquille', () => {
  it.each([
    ['/companies', 'crm'],
    ['/envoi/campagnes', 'envoi'],
    ['/users', 'commun'],
  ])('sur %s, la coquille porte data-produit="%s"', async (chemin, attendu) => {
    const vue = await renderScreen(<p>Contenu</p>, {
      path: chemin,
      withLayout: true,
      handlers: [getJson('/config/features', FEATURES_LES_DEUX_PRODUITS), ...handlersEnvoi()],
    });
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
    expect(racineDeLaCoquille()).toHaveAttribute('data-produit', attendu);
  });

  it('l’onglet du navigateur dit « Envoi · Campagnes »', async () => {
    await renderScreen(<p>Contenu</p>, {
      path: '/envoi/campagnes',
      withLayout: true,
      handlers: [getJson('/config/features', FEATURES_LES_DEUX_PRODUITS), ...handlersEnvoi()],
    });
    await waitFor(() => expect(document.title).toBe('Envoi · Campagnes'));
  });
});

describe('Bouton principal — couleur du produit', () => {
  it('`variant="primary"` (le défaut) prend `bg-produit`', () => {
    render(<Button>Enregistrer</Button>);
    const bouton = screen.getByRole('button', { name: 'Enregistrer' });
    expect(bouton.className).toContain('bg-produit');
    expect(bouton.className).toContain('text-white');
  });

  it('les autres variantes ne prennent PAS la couleur du produit (un seul bouton plein)', () => {
    render(
      <>
        <Button variant="secondary">Secondaire</Button>
        <Button variant="ghost">Discret</Button>
        <Button variant="destructive">Supprimer</Button>
      </>,
    );
    for (const nom of ['Secondaire', 'Discret', 'Supprimer']) {
      expect(screen.getByRole('button', { name: nom }).className).not.toMatch(/\bbg-produit\b/);
    }
  });
});

function fichiers(dossier: string): string[] {
  return readdirSync(dossier).flatMap((nom) => {
    const chemin = path.join(dossier, nom);
    if (statSync(chemin).isDirectory()) return fichiers(chemin);
    return /\.(tsx?|css)$/.test(nom) ? [chemin] : [];
  });
}

describe('Règle d’or (ADR-13) — jamais la couleur d’identité sur un état', () => {
  it('StatusPill n’utilise aucune classe `*-produit`', () => {
    const source = readFileSync(path.join(SRC, 'components/ui/StatusPill.tsx'), 'utf8');
    expect(source).not.toMatch(/-produit/);
  });

  it('aucun appel à <StatusPill> ne lui passe une classe `*-produit`', () => {
    const fautifs: string[] = [];
    for (const fichier of fichiers(SRC)) {
      const source = readFileSync(fichier, 'utf8');
      for (const appel of source.matchAll(/<StatusPill\b[^>]*>/g)) {
        if (/-produit\b/.test(appel[0])) fautifs.push(`${path.relative(SRC, fichier)} : ${appel[0]}`);
      }
    }
    expect(fautifs).toEqual([]);
  });
});

describe('Jetons d’identité (index.css)', () => {
  const css = readFileSync(path.join(SRC, 'styles/index.css'), 'utf8');
  const bloc = (produit: string) =>
    new RegExp(`\\[data-produit=["']${produit}["']\\]\\s*\\{([^}]*)\\}`).exec(css)?.[1] ?? '';

  it('les jetons par défaut sont déclarés dans @theme (utilitaires bg-produit, text-produit…)', () => {
    const theme = /@theme\s*\{([\s\S]*?)\n\}/.exec(css)?.[1] ?? '';
    for (const jeton of ['--color-produit:', '--color-produit-fonce:', '--color-produit-pale:', '--color-produit-vif:', '--shadow-produit:']) {
      expect(theme, jeton).toContain(jeton);
    }
  });

  it('CRM Pro = bleu de marque', () => {
    expect(bloc('crm')).toMatch(/--color-produit:\s*var\(--color-brand-600\)/);
    expect(bloc('crm')).toMatch(/--color-produit-fonce:\s*var\(--color-brand-700\)/);
    expect(bloc('crm')).toMatch(/--color-produit-pale:\s*var\(--color-brand-50\)/);
    expect(bloc('crm')).toMatch(/--color-produit-vif:\s*var\(--color-brand-400\)/);
  });

  it('Envoi = turquoise pétrole', () => {
    expect(bloc('envoi')).toMatch(/--color-produit:\s*#0b7285/i);
    expect(bloc('envoi')).toMatch(/--color-produit-fonce:\s*#085563/i);
    expect(bloc('envoi')).toMatch(/--color-produit-pale:\s*#e3f3f6/i);
    expect(bloc('envoi')).toMatch(/--color-produit-vif:\s*#22b8cf/i);
  });

  it('Commun = bandeau ardoise, bouton bleu de marque', () => {
    expect(bloc('commun')).toMatch(/--color-produit-bandeau:\s*#334155/i);
    expect(bloc('commun')).toMatch(/--color-produit:\s*var\(--color-brand-600\)/);
  });
});
