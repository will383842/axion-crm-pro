/**
 * D28-004 — LE DÉCLENCHEUR DE MENU N'EST PLUS UN BOUTON DANS UN BOUTON.
 *
 * Mesure du 2026-08-22 : `DropdownMenu` enveloppait son `trigger` dans son
 * PROPRE `<button type="button" aria-haspopup="menu">`, et cinq des sept
 * appelants lui passaient un élément qui était déjà un `<button>` —
 * `AudiencesListPage`, `CampaignsListPage`, `CompanyDetailPage`, `CompanyRow`
 * et `ScraperRunsPage` (les trois derniers via `IconButton`). Deux
 * conséquences mesurables : du HTML invalide (axe `nested-interactive`) et un
 * déclencheur extérieur SANS nom accessible, puisque l'`aria-label` du bouton
 * intérieur ne remonte pas sur celui qui l'enveloppe.
 *
 * Le composant clone désormais son déclencheur. Ces gardes inspectent les trois
 * choses que ce choix doit tenir : un seul bouton, le nom accessible conservé,
 * et le `onClick` de l'appelant composé plutôt qu'écrasé.
 *
 * ⚠️ CE QUE CES GARDES NE PROUVENT PAS : elles ne font tourner aucun axe et ne
 * mesurent aucun contraste. La règle `nested-interactive` reste vérifiée par
 * `tests/e2e/a11y.spec.ts`.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { DropdownMenu } from '@/components/ui/DropdownMenu';
import { IconButton } from '@/components/ui/IconButton';

const racine = path.dirname(fileURLToPath(import.meta.url));
const sourcesFrontend = path.resolve(racine, '../../src');

/**
 * Parcours récursif écrit à la main. Les itérateurs « tout faits » ont déjà
 * tronqué un parcours dans ce dépôt (14 fichiers vus sur 56 réels) : une garde
 * qui n'a pas ouvert tous les fichiers certifie ce qu'elle n'a pas inspecté.
 */
function fichiersSources(depart: string): string[] {
  const trouves: string[] = [];
  for (const entree of readdirSync(depart)) {
    const chemin = path.join(depart, entree);
    if (statSync(chemin).isDirectory()) {
      trouves.push(...fichiersSources(chemin));
    } else if (/\.tsx$/.test(entree)) {
      trouves.push(chemin);
    }
  }
  return trouves;
}

describe('D28-004 — le déclencheur de DropdownMenu', () => {
  it('D28-004 — ne rend qu’UN seul bouton et garde le nom accessible de l’appelant', async () => {
    const { container } = render(
      <DropdownMenu
        trigger={
          <button type="button" aria-label="Actions">
            ⋮
          </button>
        }
        items={[{ id: 'a', label: 'Voir la fiche' }]}
      />,
    );

    const boutons = container.querySelectorAll('button');
    expect(
      boutons.length,
      'D28-004 : le déclencheur rend à nouveau plus d’un bouton — `DropdownMenu` ' +
        'enveloppe son `trigger` au lieu de le cloner. C’est du HTML invalide ' +
        '(axe `nested-interactive`) et le bouton extérieur n’a aucun nom ' +
        'accessible. GESTE : revenir au `cloneElement(trigger, …)` de ' +
        '`src/components/ui/DropdownMenu.tsx`, ne PAS réintroduire de `<button>` ' +
        'autour de `{trigger}`.',
    ).toBe(1);

    const declencheur = screen.getByRole('button', { name: 'Actions' });
    expect(
      declencheur.getAttribute('aria-haspopup'),
      'D28-004 : le déclencheur ne porte plus `aria-haspopup="menu"` — rien ' +
        'n’annonce qu’un menu va s’ouvrir. GESTE : le poser dans le ' +
        '`cloneElement` de `src/components/ui/DropdownMenu.tsx`.',
    ).toBe('menu');
    expect(
      declencheur.getAttribute('aria-expanded'),
      'D28-004 : `aria-expanded` ne suit plus l’état fermé du menu. GESTE : le ' +
        'poser dans le `cloneElement` de `src/components/ui/DropdownMenu.tsx`.',
    ).toBe('false');

    await userEvent.click(declencheur);
    expect(
      screen.getByRole('button', { name: 'Actions' }).getAttribute('aria-expanded'),
      'D28-004 : le menu s’ouvre mais `aria-expanded` reste à `false` — l’état ' +
        'annoncé ment. GESTE : câbler `aria-expanded={open}` dans le ' +
        '`cloneElement` de `src/components/ui/DropdownMenu.tsx`.',
    ).toBe('true');
    expect(screen.getByRole('menu')).toBeTruthy();
  });

  it('D28-004 — compose le onClick de l’appelant au lieu de l’écraser', async () => {
    // `CompanyRow` pose un `stopPropagation()` sur son `IconButton` : l’écraser
    // ferait naviguer la ligne en même temps qu’on ouvre le menu.
    const clicAppelant = vi.fn();

    render(
      <DropdownMenu
        trigger={
          <IconButton label="Actions" onClick={clicAppelant}>
            <span aria-hidden>⋮</span>
          </IconButton>
        }
        items={[{ id: 'a', label: 'Voir la fiche' }]}
      />,
    );

    await userEvent.click(screen.getByRole('button', { name: 'Actions' }));

    expect(
      clicAppelant.mock.calls.length,
      'D28-004 : le `onClick` passé au déclencheur n’est plus appelé — le ' +
        '`cloneElement` de `src/components/ui/DropdownMenu.tsx` l’écrase. ' +
        '`CompanyRow` y arrête la propagation du clic : sans lui, ouvrir le ' +
        'menu déclenche AUSSI la navigation de la ligne. GESTE : rappeler ' +
        '`declencheur.props.onClick?.(event)` avant `setOpen`.',
    ).toBe(1);
    expect(screen.getByRole('menu')).toBeTruthy();
  });

  it('D28-004 — aucun appelant ne passe un <span> en déclencheur', () => {
    // Le wrapper `<button>` ayant disparu, un `<span>` en déclencheur n’est plus
    // focalisable : le menu deviendrait inatteignable au clavier. C’est le
    // travers exact qu’avaient `UserMenu` et `WorkspaceSelector` avant le
    // 2026-08-22, corrigés en même temps que le composant.
    const coupables = fichiersSources(sourcesFrontend).filter((chemin) =>
      /trigger=\{\s*<span/.test(readFileSync(chemin, 'utf8')),
    );

    expect(
      coupables.map((c) => path.relative(sourcesFrontend, c)),
      'D28-004 : ce(s) fichier(s) passent un `<span>` comme `trigger` de ' +
        '`DropdownMenu`. Le composant ne fournit plus de `<button>` autour : le ' +
        'menu n’est plus atteignable au clavier. GESTE : passer le déclencheur ' +
        'en `<button type="button">` (modèle : `components/layout/UserMenu.tsx`).',
    ).toEqual([]);
  });
});
